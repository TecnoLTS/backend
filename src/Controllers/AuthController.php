<?php

namespace App\Controllers;

use App\Repositories\AuthSecurityRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\MailService;
use Firebase\JWT\JWT;
use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;

class AuthController {
    private $userRepository;
    private $settingsRepository;
    private $authSecurityRepository;
    private $passwordResetTokenRepository;

    public function __construct() {
        $this->userRepository = new UserRepository();
        $this->settingsRepository = new SettingsRepository();
        $this->authSecurityRepository = new AuthSecurityRepository();
        $this->passwordResetTokenRepository = new PasswordResetTokenRepository();
    }

    private function isDevelopment(): bool {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
        if ($env === 'development' || $env === 'dev' || $env === 'local') {
            return true;
        }
        $debug = strtolower((string)($_ENV['APP_DEBUG'] ?? 'false'));
        return in_array($debug, ['1', 'true', 'yes', 'on'], true);
    }

    private function getJwtSecretOrFail(): ?string {
        $secretKey = (string)($_ENV['JWT_SECRET'] ?? '');
        $weakValues = [
            'default_secret',
            'super-secret-key-change-this-in-production',
            'change-me-to-a-long-random-secret',
        ];

        if ($secretKey === '' || strlen($secretKey) < 32 || in_array($secretKey, $weakValues, true)) {
            Response::error('Configuración JWT inválida', 500, 'AUTH_CONFIG_INVALID');
            return null;
        }
        return $secretKey;
    }

    private function authCookieLifetimeSeconds(): int {
        $configured = (int)($_ENV['AUTH_COOKIE_TTL_SECONDS'] ?? 10800);
        return max(900, $configured);
    }

    private function normalizeRecoveryCode(string $code): string {
        $normalized = preg_replace('/[^A-Z0-9]/i', '', strtoupper(trim($code)));
        return is_string($normalized) ? $normalized : '';
    }

    private function isTruthyDbValue(mixed $value): bool {
        return in_array($value, [true, 1, '1', 't', 'true', 'TRUE', 'yes', 'on'], true);
    }

    private function decodeJsonObject(mixed $value): array {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isSyntheticLocalPosEmail(?string $email): bool {
        $normalized = strtolower(trim((string)$email));
        return $normalized !== '' && str_ends_with($normalized, '@local-pos.invalid');
    }

    private function canReplaceExistingRegistrationUser(array $user): bool {
        $role = strtolower(trim((string)($user['role'] ?? 'customer')));
        if ($role === 'admin' || $role === 'service') {
            return false;
        }

        if ($this->isSyntheticLocalPosEmail((string)($user['email'] ?? ''))) {
            return true;
        }

        $profile = $this->decodeJsonObject($user['profile'] ?? null);
        $origin = strtolower(trim((string)($profile['origin'] ?? $profile['source'] ?? '')));
        if ($origin === 'local_pos') {
            return true;
        }

        return !$this->isTruthyDbValue($user['email_verified'] ?? false);
    }

    private function adminRecoveryCodeValues(): array {
        $currentKey = 'security.admin_mfa_recovery_code.current';
        $previousKey = 'security.admin_mfa_recovery_code.previous';

        $current = trim((string)$this->settingsRepository->get($currentKey));
        $previous = trim((string)$this->settingsRepository->get($previousKey));

        if ($current === '') {
            $legacyCurrent = trim((string)($_ENV['ADMIN_MFA_RECOVERY_CODE'] ?? ''));
            if ($legacyCurrent !== '') {
                $this->settingsRepository->set($currentKey, $legacyCurrent);
                $current = $legacyCurrent;
            }
        }

        if ($previous === '') {
            $legacyPrevious = trim((string)($_ENV['ADMIN_MFA_RECOVERY_CODE_PREVIOUS'] ?? ''));
            if ($legacyPrevious !== '') {
                $this->settingsRepository->set($previousKey, $legacyPrevious);
                $previous = $legacyPrevious;
            }
        }

        return [
            'current' => $current,
            'previous' => $previous,
        ];
    }

    private function adminRecoveryCodes(): array {
        $codes = [];
        $values = $this->adminRecoveryCodeValues();

        foreach ([$values['current'] ?? '', $values['previous'] ?? ''] as $rawCode) {
            if ($rawCode === '') {
                continue;
            }
            $normalized = $this->normalizeRecoveryCode($rawCode);
            if ($normalized !== '' && !in_array($normalized, $codes, true)) {
                $codes[] = $normalized;
            }
        }

        return $codes;
    }

    private function adminFallbackEmail(): ?string {
        $email = trim((string)($_ENV['ADMIN_MFA_FALLBACK_EMAIL'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return strtolower($email);
    }

    private function looksUndeliverableEmail(string $email): bool {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
        return in_array($domain, ['example.com', 'example.org', 'example.net', 'localhost', 'local'], true);
    }

    private function maskEmail(string $email): string {
        $email = trim(strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'correo de respaldo';
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $visibleLocal = strlen($localPart) <= 2 ? substr($localPart, 0, 1) : substr($localPart, 0, 2);
        $maskedLocal = $visibleLocal . str_repeat('*', max(2, strlen($localPart) - strlen($visibleLocal)));
        return $maskedLocal . '@' . $domain;
    }

    private function isAdminRecoveryAllowed(): bool {
        if (!function_exists('get_client_ip') || !function_exists('client_ip_matches_allowlist')) {
            return false;
        }

        $clientIp = \get_client_ip();
        $mode = trim((string)($_ENV['ADMIN_IP_MODE'] ?? 'off'));
        $allowlist = trim((string)($_ENV['ADMIN_IP_ALLOWLIST'] ?? ''));

        return \client_ip_matches_allowlist($clientIp, $allowlist, $mode);
    }

    private function sendAdminMfaCode(array $user, string $code): ?string {
        $recipients = [];
        $primaryEmail = strtolower(trim((string)($user['email'] ?? '')));
        if ($primaryEmail !== '' && !$this->looksUndeliverableEmail($primaryEmail)) {
            $recipients[] = $primaryEmail;
        }

        $fallbackEmail = $this->adminFallbackEmail();
        if ($fallbackEmail && !in_array($fallbackEmail, $recipients, true)) {
            $recipients[] = $fallbackEmail;
        }

        foreach ($recipients as $recipient) {
            if (MailService::send(
                $recipient,
                'Tu código MFA de administrador',
                "Hola " . ($user['name'] ?? 'Administrador') . ",\n\nTu código MFA es: {$code}\n\nExpira en 10 minutos."
            )) {
                return $recipient;
            }
        }

        return null;
    }

    private function loginMaxAttempts(): int {
        $configured = (int)($_ENV['AUTH_LOGIN_MAX_ATTEMPTS'] ?? 5);
        return max(3, $configured);
    }

    private function loginLockMinutes(): int {
        $configured = (int)($_ENV['AUTH_LOGIN_LOCK_MINUTES'] ?? 15);
        return max(5, $configured);
    }

    private function passwordResetTtlMinutes(): int {
        $configured = (int)($_ENV['AUTH_PASSWORD_RESET_TTL_MINUTES'] ?? 30);
        return max(10, min(120, $configured));
    }

    private function isLoginLocked(array $user): bool {
        $lockedUntil = $user['login_locked_until'] ?? null;
        return is_string($lockedUntil) && $lockedUntil !== '' && strtotime($lockedUntil) > time();
    }

    private function lockoutMessage(array $user): string {
        $lockedUntil = $user['login_locked_until'] ?? null;
        $formatted = is_string($lockedUntil) && $lockedUntil !== ''
            ? date('H:i', strtotime($lockedUntil))
            : null;

        if ($formatted) {
            return "Cuenta bloqueada temporalmente por intentos fallidos. Intenta otra vez después de las {$formatted}.";
        }

        return 'Cuenta bloqueada temporalmente por intentos fallidos. Intenta más tarde.';
    }

    private function registerFailedLoginAttempt(array $user): void {
        $attempts = (int)($user['failed_login_attempts'] ?? 0);
        $wasLockExpired = !$this->isLoginLocked($user) && !empty($user['login_locked_until']);
        $nextAttempts = $wasLockExpired ? 1 : ($attempts + 1);
        $lockedUntil = null;

        if ($nextAttempts >= $this->loginMaxAttempts()) {
            $lockedUntil = date('Y-m-d H:i:s', time() + ($this->loginLockMinutes() * 60));
        }

        $this->userRepository->setLoginFailureState($user['id'], $nextAttempts, $lockedUntil);
    }

    private function getClientIp(): ?string {
        if (function_exists('get_client_ip')) {
            $ip = trim((string)\get_client_ip());
            return $ip !== '' ? $ip : null;
        }

        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? $ip : null;
    }

    private function getUserAgent(): ?string {
        $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $userAgent !== '' ? $userAgent : null;
    }

    private function recordAuthEvent(?array $user, string $eventType, string $status = 'info', array $metadata = []): void {
        try {
            $this->authSecurityRepository->recordEvent(
                $eventType,
                $status,
                $user['id'] ?? null,
                $user['email'] ?? ($metadata['email'] ?? null),
                $this->getClientIp(),
                $this->getUserAgent(),
                $metadata
            );
        } catch (\Throwable $exception) {
            error_log('[AUTH_SECURITY_EVENT_FAILED] ' . $exception->getMessage());
        }
    }

    private function passwordResetGenericMessage(): string {
        return 'Si el correo existe, te enviaremos un enlace para restablecer tu contraseña.';
    }

    private function isPasswordResetRateLimited(string $email): bool {
        $emailCount = $this->authSecurityRepository->countRecentEventsByEmail(
            'password_reset_requested',
            $email,
            '1 hour'
        );
        $clientIp = $this->getClientIp();
        $ipCount = $clientIp
            ? $this->authSecurityRepository->countRecentEventsByIp('password_reset_requested', $clientIp, '1 hour')
            : 0;

        return $emailCount >= 3 || $ipCount >= 10;
    }

    private function tokenHash(string $token): string {
        return hash('sha256', $token);
    }

    private function buildPasswordResetUrl(string $token): string {
        $baseUrl = TenantContext::publicBaseUrl()
            ?? TenantContext::appUrl()
            ?? ($_ENV['NEXT_PUBLIC_BASE_URL'] ?? ($_ENV['APP_URL'] ?? $this->getRequestBaseUrl()));
        return rtrim($baseUrl, '/') . '/reset-password?token=' . urlencode($token);
    }

    private function sendPasswordResetEmail(string $email, string $name, string $token): bool {
        $resetUrl = $this->buildPasswordResetUrl($token);
        $ttlMinutes = $this->passwordResetTtlMinutes();
        $safeName = trim($name) !== '' ? trim($name) : 'Usuario';
        $subject = 'Restablece tu contraseña';
        $message = "Hola {$safeName},\n\n";
        $message .= "Recibimos una solicitud para restablecer tu contraseña en Para Mascotas EC.\n";
        $message .= "Usa este enlace dentro de los próximos {$ttlMinutes} minutos:\n";
        $message .= "{$resetUrl}\n\n";
        $message .= "Si no solicitaste este cambio, ignora este correo. Tu contraseña actual seguirá vigente.\n";

        return MailService::send($email, $subject, $message);
    }

    private function serializeUser(array $user): array {
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'] ?? 'customer',
        ];
    }

    private function issueSession(array $user): void {
        $secretKey = $this->getJwtSecretOrFail();
        if (!$secretKey) {
            return;
        }
        $tokenId = bin2hex(random_bytes(16));
        $expiresAt = time() + $this->authCookieLifetimeSeconds();
        $payload = [
            'iat' => time(),
            'exp' => $expiresAt,
            'sub' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'] ?? 'customer',
            'tenant_id' => TenantContext::id(),
            'jti' => $tokenId,
        ];

        $this->userRepository->markSuccessfulLogin($user['id']);
        $this->userRepository->setActiveTokenId($user['id'], $tokenId);
        $jwt = JWT::encode($payload, $secretKey, 'HS256');
        Response::noStore();
        Response::setAuthCookie($jwt, $expiresAt);
        Response::setCsrfCookie(bin2hex(random_bytes(32)), $expiresAt);

        Response::json([
            'user' => $this->serializeUser($user),
        ]);
    }

    private function validateAdminMfa(array $user, string $code): bool {
        $attempts = (int)($user['otp_attempts'] ?? 0);
        if ($attempts >= 5) {
            $this->recordAuthEvent($user, 'admin_mfa_blocked', 'blocked', [
                'reason' => 'too_many_attempts',
            ]);
            Response::error('Demasiados intentos de MFA. Solicita un nuevo código.', 429, 'AUTH_MFA_TOO_MANY_ATTEMPTS');
            return false;
        }

        $expiresAt = $user['otp_expires_at'] ?? null;
        if (!$expiresAt || strtotime((string)$expiresAt) < time()) {
            $this->recordAuthEvent($user, 'admin_mfa_expired', 'failure');
            Response::error('El código MFA ha expirado. Inicia sesión otra vez para recibir uno nuevo.', 400, 'AUTH_MFA_EXPIRED');
            return false;
        }

        if (($user['otp_code'] ?? '') !== $code) {
            $this->userRepository->incrementOtpAttempts($user['id']);
            $this->recordAuthEvent($user, 'admin_mfa_invalid', 'failure');
            Response::error('Código MFA incorrecto', 400, 'AUTH_MFA_INVALID');
            return false;
        }

        $this->userRepository->markEmailVerifiedByOtp($user['id']);
        $this->recordAuthEvent($user, 'admin_mfa_verified', 'success');
        return true;
    }

    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['email']) || !isset($data['password'])) {
            Response::error('El correo y la contraseña son obligatorios', 400, 'AUTH_LOGIN_MISSING_FIELDS');
            return;
        }

        $user = $this->userRepository->getByEmail($data['email']);

        if ($user && $this->isLoginLocked($user)) {
            $this->recordAuthEvent($user, 'login_locked', 'blocked');
            Response::error($this->lockoutMessage($user), 429, 'AUTH_LOGIN_LOCKED');
            return;
        }

        if (!$user || !password_verify($data['password'], $user['password'])) {
            if ($user) {
                $this->registerFailedLoginAttempt($user);
                $refreshedUser = $this->userRepository->getByEmail($data['email']);
                $locked = $refreshedUser && $this->isLoginLocked($refreshedUser);
                $this->recordAuthEvent($refreshedUser ?: $user, $locked ? 'login_locked' : 'login_failed', $locked ? 'blocked' : 'failure', [
                    'failed_login_attempts' => (int)($refreshedUser['failed_login_attempts'] ?? $user['failed_login_attempts'] ?? 0),
                    'login_locked_until' => $refreshedUser['login_locked_until'] ?? ($user['login_locked_until'] ?? null),
                ]);
            } else {
                $this->recordAuthEvent(null, 'login_failed', 'failure', [
                    'email' => strtolower(trim((string)$data['email'])),
                    'reason' => 'user_not_found_or_invalid_password',
                ]);
            }
            Response::error('Credenciales inválidas', 401, 'AUTH_LOGIN_INVALID');
            return;
        }

        $this->userRepository->clearLoginFailures($user['id']);

        if (!$this->isTruthyDbValue($user['email_verified'] ?? false)) {
            $this->recordAuthEvent($user, 'login_unverified_email', 'failure');
            Response::error('Por favor, confirma tu dirección de correo electrónico antes de iniciar sesión', 403, 'AUTH_EMAIL_NOT_VERIFIED');
            return;
        }

        $role = strtolower((string)($user['role'] ?? 'customer'));
        if ($role === 'admin') {
            $mfaCode = trim((string)($data['mfaCode'] ?? $data['mfa_code'] ?? ''));
            if ($mfaCode === '') {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60));
                $this->userRepository->setOtpForEmail($user['email'], $code, $expiresAt);

                $recipient = $this->sendAdminMfaCode($user, $code);
                if ($recipient) {
                    $this->recordAuthEvent($user, 'admin_mfa_email_sent', 'success', [
                        'delivery' => 'email',
                    ]);
                    Response::json([
                        'mfaRequired' => true,
                        'mfaMethod' => 'email_otp',
                    ], 202, null, 'Te enviamos un código MFA a ' . $this->maskEmail($recipient) . '.');
                    return;
                }

                if ($this->isAdminRecoveryAllowed() && $this->adminRecoveryCodes() !== []) {
                    error_log('[AUTH_MFA_RECOVERY_FALLBACK] SMTP unavailable, allowing private recovery flow for admin ' . $user['id']);
                    $this->recordAuthEvent($user, 'admin_mfa_recovery_required', 'warning', [
                        'reason' => 'smtp_unavailable',
                    ]);
                    Response::json([
                        'mfaRequired' => true,
                        'mfaMethod' => 'recovery_code',
                    ], 202, null, 'No se pudo enviar el código por correo. Usa el código de recuperación del administrador desde una red privada/autorizada.');
                    return;
                }

                $this->recordAuthEvent($user, 'admin_mfa_email_failed', 'failure', [
                    'reason' => 'smtp_send_failed',
                ]);
                Response::error('No se pudo enviar el código MFA al correo del administrador', 500, 'AUTH_MFA_SEND_FAILED');
                return;
            }

            $userWithOtp = $this->userRepository->getByEmailWithOtp($user['email']);
            $acceptedRecoveryCodes = $this->adminRecoveryCodes();
            if (
                $acceptedRecoveryCodes !== []
                && $this->isAdminRecoveryAllowed()
                && in_array($this->normalizeRecoveryCode($mfaCode), $acceptedRecoveryCodes, true)
            ) {
                error_log('[AUTH_MFA_RECOVERY_SUCCESS] Admin recovery code used for ' . $user['id']);
                $this->recordAuthEvent($user, 'admin_mfa_recovery_success', 'success');
                if ($userWithOtp) {
                    $user = array_merge($user, $userWithOtp);
                }
            } elseif (!$userWithOtp || !$this->validateAdminMfa($userWithOtp, $mfaCode)) {
                return;
            } else {
                $user = array_merge($user, $userWithOtp);
            }
        }

        $this->recordAuthEvent($user, 'login_success', 'success');
        $this->issueSession($user);
    }

    public function session() {
        $payload = Auth::requireUser();
        $user = $this->userRepository->getById((string)($payload['sub'] ?? ''));
        if (!$user) {
            Response::clearAuthCookie();
            Response::clearCsrfCookie();
            Response::error('Sesión inválida', 401, 'AUTH_TOKEN_REVOKED');
            return;
        }
        Response::noStore();
        Response::ensureCsrfCookie((int)($payload['exp'] ?? (time() + 10800)));
        Response::json([
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout() {
        $payload = Auth::optionalUser();
        if ($payload && !empty($payload['sub'])) {
            $this->userRepository->clearActiveTokenId((string)$payload['sub']);
        }
        Response::noStore();
        Response::clearAuthCookie();
        Response::clearCsrfCookie();
        header('Clear-Site-Data: "storage"');
        Response::json(['loggedOut' => true], 200, null, 'Sesión cerrada correctamente.');
    }

    public function register() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['email']) || !isset($data['password']) || !isset($data['name'])) {
            Response::error('Nombre, correo y contraseña son obligatorios', 400, 'AUTH_REGISTER_MISSING_FIELDS');
            return;
        }

        if (mb_strlen($data['password']) < 12) {
            Response::error('La contraseña debe tener al menos 12 caracteres', 400, 'AUTH_REGISTER_WEAK_PASSWORD');
            return;
        }

        $docType = $data['documentType'] ?? ($data['document_type'] ?? null);
        $docNumber = $data['documentNumber'] ?? ($data['document_number'] ?? null);
        if (!$docType || !$docNumber) {
            Response::error('Tipo y número de identificación son obligatorios', 400, 'AUTH_REGISTER_MISSING_ID');
            return;
        }

        try {
            $data['document_type'] = $docType;
            $data['document_number'] = $docNumber;
            $data['business_name'] = $data['businessName'] ?? ($data['business_name'] ?? null);
            $skipVerificationEmail = (bool)($data['skipVerificationEmail'] ?? ($data['skip_verification_email'] ?? false));
            $sendOtpOnCreate = (bool)($data['sendOtpOnCreate'] ?? ($data['send_otp_on_create'] ?? false));
            $existingUser = $this->userRepository->getByEmail($data['email']);
            $existingByDocument = $this->userRepository->getByDocumentNumber($docNumber);
            $replaceUserId = null;

            if ($existingUser) {
                if ($this->canReplaceExistingRegistrationUser($existingUser)) {
                    $replaceUserId = (string)$existingUser['id'];
                } else {
                    Response::error('El usuario ya existe', 409, 'AUTH_REGISTER_EXISTS');
                    return;
                }
            }

            if ($existingByDocument && (!$replaceUserId || $replaceUserId !== (string)$existingByDocument['id'])) {
                if ($this->canReplaceExistingRegistrationUser($existingByDocument)) {
                    $replaceUserId = (string)$existingByDocument['id'];
                } else {
                    Response::error('Ya existe un usuario con ese documento', 409, 'AUTH_REGISTER_DOCUMENT_EXISTS');
                    return;
                }
            }

            $replacedExistingUser = $replaceUserId !== null;
            if ($replaceUserId) {
                $result = $this->userRepository->replaceRegistrationData($replaceUserId, $data, [
                    'skip_verification_token' => $skipVerificationEmail
                ]);
            } else {
                $result = $this->userRepository->create($data, [
                    'skip_verification_token' => $skipVerificationEmail
                ]);
            }

            if ($skipVerificationEmail && $sendOtpOnCreate) {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60));
                $this->userRepository->setOtpForEmail($data['email'], $code, $expiresAt);

                if (!$this->sendOtpEmail($data['email'], $data['name'], $code)) {
                    if (!$replacedExistingUser) {
                        $this->userRepository->deleteById($result['id']);
                    }
                    Response::error('No se pudo enviar el código de verificación', 500, 'AUTH_OTP_SEND_FAILED');
                    return;
                }

                Response::json([
                    'id' => $result['id'],
                    'otpSent' => true,
                ], 201, null, 'Cuenta creada. Te enviamos un código al correo para verificarla.');
                return;
            }

            if (!$skipVerificationEmail) {
                if (!$this->sendVerificationEmail($data['email'], $data['name'], $result['token'])) {
                    $this->userRepository->markEmailVerifiedById($result['id']);
                    $payload = [
                        'id' => $result['id'],
                        'email_verified' => true
                    ];
                    if ($this->isDevelopment()) {
                        $payload['debug_token'] = $result['token'];
                    }
                    Response::json($payload, 201, null, 'Cuenta creada. No se pudo enviar el correo de verificación, pero tu cuenta quedó activa. Inicia sesión para continuar.');
                    return;
                }
            }
            $payload = ['id' => $result['id']];
            if ($this->isDevelopment()) {
                $payload['debug_token'] = $result['token'];
            }
            Response::json($payload, 201, null, 'Usuario registrado exitosamente. Por favor, revisa tu correo para confirmar tu cuenta.');
        } catch (\Exception $e) {
            error_log('[AUTH_REGISTER_FAILED] ' . $e->getMessage());
            Response::error('No se pudo registrar el usuario', 500, 'AUTH_REGISTER_FAILED');
        }
    }

    public function guestToken() {
        Response::error('Compra como invitado deshabilitada', 403, 'GUEST_DISABLED');
        return;
        $secretKey = $this->getJwtSecretOrFail();
        if (!$secretKey) {
            return;
        }
        $payload = [
            'iat' => time(),
            'exp' => time() + (60 * 15), // 15 minutes
            'sub' => 'guest-' . bin2hex(random_bytes(8)),
            'role' => 'guest',
            'scope' => 'guest_checkout',
            'tenant_id' => TenantContext::id()
        ];

        $jwt = JWT::encode($payload, $secretKey, 'HS256');

        Response::json([
            'token' => $jwt,
            'expires_in' => 900
        ]);
    }

    public function verify() {
        $token = $_GET['token'] ?? null;

        if (!$token) {
            Response::error('Token de verificación no proporcionado', 400, 'AUTH_VERIFY_MISSING_TOKEN');
            return;
        }

        $user = $this->userRepository->verifyToken($token);

        if ($user) {
            Response::json(['verified' => true], 200, null, 'Correo electrónico confirmado exitosamente. Ya puedes iniciar sesión.');
        } else {
            Response::error('Token de verificación inválido o expirado', 400, 'AUTH_VERIFY_INVALID');
        }
    }

    public function requestOtp() {
        $data = json_decode(file_get_contents('php://input'), true);
        $email = $data['email'] ?? null;
        if (!$email) {
            Response::error('El correo es obligatorio', 400, 'AUTH_OTP_EMAIL_REQUIRED');
            return;
        }

        $user = $this->userRepository->getByEmail($email);
        if (!$user) {
            $this->recordAuthEvent(null, 'otp_request_unknown_email', 'info', [
                'email' => strtolower(trim((string)$email)),
            ]);
            // Avoid user enumeration.
            Response::json(['sent' => true], 200, null, 'Si el correo existe, se enviará un código de verificación.');
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60));
        $this->userRepository->setOtpForEmail($email, $code, $expiresAt);

        if (!$this->sendOtpEmail($email, $user['name'] ?? 'Usuario', $code)) {
            $this->recordAuthEvent($user, 'otp_send_failed', 'failure');
            Response::error('No se pudo enviar el código de verificación', 500, 'AUTH_OTP_SEND_FAILED');
            return;
        }

        $this->recordAuthEvent($user, 'otp_sent', 'success', [
            'delivery' => 'email',
        ]);
        Response::json([
            'sent' => true,
            'delivery' => 'email',
        ], 200, null, 'Código de verificación enviado.');
    }

    public function verifyOtp() {
        $data = json_decode(file_get_contents('php://input'), true);
        $email = $data['email'] ?? null;
        $code = $data['code'] ?? null;
        if (!$email || !$code) {
            Response::error('Correo y código son obligatorios', 400, 'AUTH_OTP_REQUIRED');
            return;
        }

        $user = $this->userRepository->getByEmailWithOtp($email);
        if (!$user) {
            $this->recordAuthEvent(null, 'otp_verify_unknown_email', 'failure', [
                'email' => strtolower(trim((string)$email)),
            ]);
            Response::error('Usuario no encontrado', 404, 'AUTH_OTP_USER_NOT_FOUND');
            return;
        }

        $attempts = (int)($user['otp_attempts'] ?? 0);
        if ($attempts >= 5) {
            $this->recordAuthEvent($user, 'otp_blocked', 'blocked', [
                'reason' => 'too_many_attempts',
            ]);
            Response::error('Demasiados intentos. Solicita un nuevo código.', 429, 'AUTH_OTP_TOO_MANY_ATTEMPTS');
            return;
        }

        $expiresAt = $user['otp_expires_at'] ?? null;
        if (!$expiresAt || strtotime($expiresAt) < time()) {
            $this->recordAuthEvent($user, 'otp_expired', 'failure');
            Response::error('El código ha expirado. Solicita uno nuevo.', 400, 'AUTH_OTP_EXPIRED');
            return;
        }

        if (($user['otp_code'] ?? '') !== $code) {
            $this->userRepository->incrementOtpAttempts($user['id']);
            $this->recordAuthEvent($user, 'otp_invalid', 'failure');
            Response::error('Código incorrecto', 400, 'AUTH_OTP_INVALID');
            return;
        }

        $this->userRepository->markEmailVerifiedByOtp($user['id']);
        $this->recordAuthEvent($user, 'otp_verified', 'success');
        Response::json(['verified' => true], 200, null, 'Correo verificado correctamente.');
    }

    public function requestPasswordReset() {
        $data = json_decode(file_get_contents('php://input'), true);
        $email = strtolower(trim((string)($data['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Ingresa un correo electrónico válido', 400, 'AUTH_PASSWORD_RESET_EMAIL_INVALID');
            return;
        }

        if ($this->isPasswordResetRateLimited($email)) {
            $this->recordAuthEvent(null, 'password_reset_rate_limited', 'blocked', [
                'email' => $email,
            ]);
            Response::error('Demasiados intentos en poco tiempo. Espera y vuelve a intentarlo.', 429, 'AUTH_PASSWORD_RESET_RATE_LIMITED');
            return;
        }

        $user = $this->userRepository->getByEmail($email);
        $this->recordAuthEvent($user, 'password_reset_requested', 'info', [
            'email' => $email,
            'user_exists' => (bool)$user,
        ]);

        if (!$user || $this->looksUndeliverableEmail($email)) {
            Response::json(['sent' => true], 200, null, $this->passwordResetGenericMessage());
            return;
        }

        try {
            $this->passwordResetTokenRepository->deleteExpired();
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + ($this->passwordResetTtlMinutes() * 60));
            $this->passwordResetTokenRepository->create(
                (string)$user['id'],
                $this->tokenHash($token),
                $expiresAt,
                $this->getClientIp(),
                $this->getUserAgent()
            );

            if ($this->sendPasswordResetEmail($email, $user['name'] ?? 'Usuario', $token)) {
                $this->recordAuthEvent($user, 'password_reset_email_sent', 'success', [
                    'delivery' => 'email',
                ]);
            } else {
                $this->recordAuthEvent($user, 'password_reset_email_failed', 'failure', [
                    'reason' => 'smtp_send_failed',
                ]);
            }
        } catch (\Throwable $exception) {
            error_log('[PASSWORD_RESET_REQUEST_FAILED] ' . $exception->getMessage());
            $this->recordAuthEvent($user, 'password_reset_request_failed', 'failure');
        }

        Response::json(['sent' => true], 200, null, $this->passwordResetGenericMessage());
    }

    public function confirmPasswordReset() {
        $data = json_decode(file_get_contents('php://input'), true);
        $token = trim((string)($data['token'] ?? ''));
        $password = trim((string)($data['password'] ?? $data['newPassword'] ?? ''));

        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            Response::error('El enlace de recuperación es inválido o expiró.', 400, 'AUTH_PASSWORD_RESET_TOKEN_INVALID');
            return;
        }

        if ($password === '') {
            Response::error('La nueva contraseña es obligatoria', 400, 'AUTH_PASSWORD_RESET_PASSWORD_REQUIRED');
            return;
        }

        if (mb_strlen($password) < 12) {
            Response::error('La nueva contraseña debe tener al menos 12 caracteres', 400, 'AUTH_PASSWORD_RESET_WEAK_PASSWORD');
            return;
        }

        try {
            $resetToken = $this->passwordResetTokenRepository->getValidToken($this->tokenHash($token));

            if (!$resetToken) {
                $this->recordAuthEvent(null, 'password_reset_token_invalid', 'failure');
                Response::error('El enlace de recuperación es inválido o expiró.', 400, 'AUTH_PASSWORD_RESET_TOKEN_INVALID');
                return;
            }

            $userId = (string)$resetToken['user_id'];
            $user = $this->userRepository->getById($userId);
            if (!$user) {
                $this->recordAuthEvent(null, 'password_reset_user_missing', 'failure');
                Response::error('El enlace de recuperación es inválido o expiró.', 400, 'AUTH_PASSWORD_RESET_TOKEN_INVALID');
                return;
            }

            $currentPasswordHash = $this->userRepository->getPasswordHash($userId);
            if ($currentPasswordHash && password_verify($password, $currentPasswordHash)) {
                $this->recordAuthEvent($user, 'password_reset_same_password', 'failure');
                Response::error('La nueva contraseña debe ser diferente a la actual', 400, 'AUTH_PASSWORD_RESET_SAME_PASSWORD');
                return;
            }

            $this->userRepository->resetPasswordAfterRecovery(
                $userId,
                password_hash($password, PASSWORD_DEFAULT),
                bin2hex(random_bytes(16))
            );
            $this->passwordResetTokenRepository->markUsed(
                (string)$resetToken['id'],
                $this->getClientIp(),
                $this->getUserAgent()
            );

            $this->recordAuthEvent($user, 'password_reset_completed', 'success');
            Response::json(['passwordReset' => true], 200, null, 'Contraseña restablecida correctamente. Inicia sesión con tu nueva contraseña.');
        } catch (\Throwable $exception) {
            error_log('[PASSWORD_RESET_CONFIRM_FAILED] ' . $exception->getMessage());
            Response::error('No se pudo restablecer la contraseña', 500, 'AUTH_PASSWORD_RESET_FAILED');
        }
    }

    private function sendVerificationEmail($email, $name, $token) {
        $verifyUrl = $this->buildVerificationUrl($token);
        $subject = 'Confirma tu cuenta';

        $safeName = trim($name);
        $message = "Hola {$safeName},\n\n";
        $message .= "Gracias por registrarte en Para Mascotas EC. Para confirmar tu cuenta, visita el siguiente enlace:\n";
        $message .= "{$verifyUrl}\n\n";
        $message .= "Si no solicitaste este registro, puedes ignorar este correo.\n";

        return MailService::send($email, $subject, $message);
    }

    private function sendOtpEmail($email, $name, $code) {
        $subject = 'Tu código de verificación';

        $safeName = trim($name);
        $message = "Hola {$safeName},\n\n";
        $message .= "Tu código de verificación es: {$code}\n";
        $message .= "Este código expira en 10 minutos.\n\n";
        $message .= "Si no solicitaste este código, puedes ignorar este correo.\n";

        return MailService::send($email, $subject, $message);
    }

    private function buildVerificationUrl($token) {
        $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? $this->getRequestBaseUrl());
        return rtrim($baseUrl, '/') . '/api/auth/verify?token=' . urlencode($token);
    }

    private function getRequestBaseUrl() {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (!$proto) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $proto . '://' . $host;
    }
}
