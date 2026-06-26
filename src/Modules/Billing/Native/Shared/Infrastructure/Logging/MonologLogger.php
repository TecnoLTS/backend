<?php

declare(strict_types=1);

namespace BillingService\Shared\Infrastructure\Logging;

use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LoggerInterface;

class MonologLogger implements LoggerInterface
{
    private Logger $logger;
    private array $sharedContext = [];

    public function __construct(string $channel = 'billing-service')
    {
        $this->logger = new Logger($channel);

        $logPath = $this->resolveWritableLogPath($this->resolveLogPath());
        $logLevel = $this->resolveLogLevel($_ENV['LOG_LEVEL'] ?? 'info');

        $handler = new StreamHandler($logPath, $logLevel);
        $handler->setFormatter(new LineFormatter(
            format: "%datetime% [%channel%] %level_name%: %message% %context% %extra%\n",
            dateFormat: 'Y-m-d H:i:s',
            allowInlineLineBreaks: false,
            ignoreEmptyContextAndExtra: true
        ));

        $this->logger->pushHandler($handler);
        $this->logger->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(context: array_merge($this->sharedContext, $record->context));
        });
    }

    private function resolveLogPath(): string
    {
        $configuredPath = $_ENV['LOG_PATH'] ?? '';
        $channel = strtolower(trim((string) ($_ENV['LOG_CHANNEL'] ?? 'single')));

        if ($channel === 'daily') {
            $baseDirectory = $this->resolveBaseLogDirectory($configuredPath);
            return sprintf('%s/%s.log', rtrim($baseDirectory, '/'), date('Y/m/d'));
        }

        if (!empty($_ENV['LOG_PATH'])) {
            return $_ENV['LOG_PATH'];
        }

        if (is_dir('/var/www/html/storage/billing/logs')) {
            return '/var/www/html/storage/billing/logs/app.log';
        }

        if (is_dir('/var/www/html/storage/billing/log')) {
            return '/var/www/html/storage/billing/log/app.log';
        }

        return '/var/www/html/storage/billing/logs/app.log';
    }

    private function resolveBaseLogDirectory(string $configuredPath): string
    {
        if ($configuredPath !== '') {
            return str_ends_with($configuredPath, '.log')
                ? dirname($configuredPath)
                : rtrim($configuredPath, '/');
        }

        if (is_dir('/var/www/html/storage/billing/logs')) {
            return '/var/www/html/storage/billing/logs';
        }

        if (is_dir('/var/www/html/storage/billing/log')) {
            return '/var/www/html/storage/billing/log';
        }

        return '/var/www/html/storage/billing/logs';
    }

    private function resolveLogLevel(string $level): int
    {
        return match(strtolower($level)) {
            'debug' => Logger::DEBUG,
            'info' => Logger::INFO,
            'notice' => Logger::NOTICE,
            'warning' => Logger::WARNING,
            'error' => Logger::ERROR,
            'critical' => Logger::CRITICAL,
            'alert' => Logger::ALERT,
            'emergency' => Logger::EMERGENCY,
            default => Logger::INFO,
        };
    }

    private function resolveWritableLogPath(string $logPath): string
    {
        if (str_starts_with($logPath, 'php://')) {
            return $logPath;
        }

        $logDir = dirname($logPath);
        if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            return 'php://stderr';
        }

        if (is_file($logPath) && is_writable($logPath)) {
            return $logPath;
        }

        if (!is_file($logPath) && is_writable($logDir)) {
            return $logPath;
        }

        return 'php://stderr';
    }

    private function safeLog(string $level, mixed $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable $exception) {
            error_log(sprintf(
                '[LoggerFallback] %s %s | %s',
                strtoupper($level),
                (string) $message,
                $exception->getMessage()
            ));
        }
    }

    public function emergency($message, array $context = []): void
    {
        $this->safeLog('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->safeLog('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->safeLog('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->safeLog('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->safeLog('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->safeLog('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->safeLog('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->safeLog('debug', $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $this->safeLog((string) $level, $message, $context);
    }

    public function replaceSharedContext(array $context): void
    {
        $this->sharedContext = $context;
    }

    public function clearSharedContext(): void
    {
        $this->sharedContext = [];
    }
}
