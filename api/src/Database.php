<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = env_value('DB_HOST', 'localhost');
        $port = env_value('DB_PORT', '3306');
        $db = env_value('DB_DATABASE');
        $user = env_value('DB_USERNAME');
        $pass = env_value('DB_PASSWORD');
        $charset = env_value('DB_CHARSET', 'utf8mb4');

        if (!$db || !$user) {
            throw new RuntimeException('Base de datos de ticketing no configurada.');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        self::$pdo = new PDO($dsn, $user, $pass ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec("SET time_zone = '+00:00'");

        return self::$pdo;
    }
}
