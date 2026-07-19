<?php

declare(strict_types=1);

namespace App\Modules\Mailer\Application;

final class MailPayloadSanitizer
{
    private const SECRET_KEY_PATTERN = '/(?:password|passwd|secret|token|authorization|cookie|api[_-]?key|smtp[_-]?pass|private[_-]?key)/i';

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public static function metadata(array $metadata, int $maxBytes = 32768): array
    {
        $clean = self::walk($metadata, 0);
        $encoded = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || strlen($encoded) > $maxBytes) {
            throw new \InvalidArgumentException('Mailer metadata exceeds its safe persistence limit.');
        }

        return $clean;
    }

    public static function error(string $message): string
    {
        $singleLine = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);
        $singleLine = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[REDACTED_EMAIL]', $singleLine) ?? $singleLine;
        $singleLine = preg_replace('/(?i)(authorization|bearer|password|secret|token|api[_-]?key)\s*[:=]?\s*[^\s,;]+/', '$1=[REDACTED]', $singleLine) ?? $singleLine;

        return mb_substr($singleLine !== '' ? $singleLine : 'Mailer transport failed.', 0, 1000);
    }

    /** @return mixed */
    private static function walk(mixed $value, int $depth): mixed
    {
        if ($depth > 5) {
            return '[TRUNCATED_DEPTH]';
        }
        if (!is_array($value)) {
            if (is_string($value)) {
                return mb_substr($value, 0, 2000);
            }
            return is_scalar($value) || $value === null ? $value : (string)$value;
        }

        $clean = [];
        $count = 0;
        foreach ($value as $key => $child) {
            if (++$count > 100) {
                $clean['_truncated'] = true;
                break;
            }
            $normalizedKey = mb_substr((string)$key, 0, 120);
            if (preg_match(self::SECRET_KEY_PATTERN, $normalizedKey)) {
                continue;
            }
            $clean[$normalizedKey] = self::walk($child, $depth + 1);
        }

        return $clean;
    }
}
