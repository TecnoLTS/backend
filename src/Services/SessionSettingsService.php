<?php

namespace App\Services;

use App\Repositories\SettingsRepository;

class SessionSettingsService {
    private const CUSTOMER_KEY = 'auth_session_customer_hours';
    private const ADMIN_KEY = 'auth_session_admin_hours';
    private const DEFAULT_CUSTOMER_HOURS = 6;
    private const DEFAULT_ADMIN_HOURS = 12;
    private const MIN_CUSTOMER_HOURS = 6;
    private const MIN_ADMIN_HOURS = 12;
    private const MAX_HOURS = 168;

    public function __construct(private ?SettingsRepository $settings = null) {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function getSettings(bool $persistDefaults = true): array {
        $customerRaw = $this->settings->get(self::CUSTOMER_KEY);
        $adminRaw = $this->settings->get(self::ADMIN_KEY);

        $customerHours = $this->normalizeHours(
            $customerRaw,
            $this->defaultCustomerHours(),
            self::MIN_CUSTOMER_HOURS
        );
        $adminHours = $this->normalizeHours(
            $adminRaw,
            $this->defaultAdminHours(),
            self::MIN_ADMIN_HOURS
        );

        if ($persistDefaults && $customerRaw === null) {
            $this->settings->set(self::CUSTOMER_KEY, (string)$customerHours);
        }
        if ($persistDefaults && $adminRaw === null) {
            $this->settings->set(self::ADMIN_KEY, (string)$adminHours);
        }

        return $this->formatSettings($customerHours, $adminHours);
    }

    public function updateSettings($customerHours, $adminHours): array {
        $normalizedCustomerHours = $this->normalizeHours(
            $customerHours,
            $this->defaultCustomerHours(),
            self::MIN_CUSTOMER_HOURS
        );
        $normalizedAdminHours = $this->normalizeHours(
            $adminHours,
            $this->defaultAdminHours(),
            self::MIN_ADMIN_HOURS
        );

        $this->settings->set(self::CUSTOMER_KEY, (string)$normalizedCustomerHours);
        $this->settings->set(self::ADMIN_KEY, (string)$normalizedAdminHours);

        return $this->formatSettings($normalizedCustomerHours, $normalizedAdminHours);
    }

    public function ttlSecondsForRole(?string $role): int {
        $settings = $this->getSettings();
        $normalizedRole = strtolower(trim((string)$role));
        $hours = $normalizedRole === 'admin'
            ? (int)$settings['adminSessionHours']
            : (int)$settings['customerSessionHours'];

        return $hours * 3600;
    }

    private function normalizeHours($value, int $default, int $minimum): int {
        $hours = is_numeric($value) ? (int)round((float)$value) : $default;
        return max($minimum, min(self::MAX_HOURS, $hours));
    }

    private function defaultCustomerHours(): int {
        $legacySeconds = (int)($_ENV['AUTH_COOKIE_TTL_SECONDS'] ?? 0);
        $legacyHours = $legacySeconds > 0 ? (int)ceil($legacySeconds / 3600) : self::DEFAULT_CUSTOMER_HOURS;
        return max(self::MIN_CUSTOMER_HOURS, min(self::MAX_HOURS, $legacyHours));
    }

    private function defaultAdminHours(): int {
        $legacySeconds = (int)($_ENV['AUTH_ADMIN_COOKIE_TTL_SECONDS'] ?? ($_ENV['AUTH_COOKIE_TTL_SECONDS'] ?? 0));
        $legacyHours = $legacySeconds > 0 ? (int)ceil($legacySeconds / 3600) : self::DEFAULT_ADMIN_HOURS;
        return max(self::MIN_ADMIN_HOURS, min(self::MAX_HOURS, $legacyHours));
    }

    private function formatSettings(int $customerHours, int $adminHours): array {
        return [
            'customerSessionHours' => $customerHours,
            'adminSessionHours' => $adminHours,
            'customerSessionTtlSeconds' => $customerHours * 3600,
            'adminSessionTtlSeconds' => $adminHours * 3600,
            'minCustomerSessionHours' => self::MIN_CUSTOMER_HOURS,
            'minAdminSessionHours' => self::MIN_ADMIN_HOURS,
            'maxSessionHours' => self::MAX_HOURS,
        ];
    }
}
