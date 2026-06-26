<?php

namespace App\Core;

use PDO;
use PDOException;

class Database {
    private static $instances = [];
    private $connection;

    private function __construct(array $config) {
        $host = $config['host'];
        $port = $config['port'];
        $db   = $config['database'];
        $user = $config['username'];
        $pass = $config['password'];

        $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    private static function resolveConfig(?string $moduleKey = null): array {
        return ConnectionRegistry::resolveDatabaseConfig($moduleKey);
    }

    public static function getInstance(?string $moduleKey = null) {
        $config = self::resolveConfig($moduleKey);
        $key = implode('|', [
            $config['host'],
            $config['port'],
            $config['database'],
            $config['username'],
            $config['module'] ?? 'primary',
        ]);
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($config);
        }
        return self::$instances[$key]->connection;
    }

    public static function getModuleInstance(string $moduleKey) {
        return self::getInstance($moduleKey);
    }
}
