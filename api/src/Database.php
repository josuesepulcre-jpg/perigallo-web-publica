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
        // Las fechas introducidas desde el editor (ventas, publicación y
        // horarios) se guardan como hora civil de la aplicación. La sesión
        // MySQL debe comparar NOW() en esa misma zona; usar UTC aquí hacía que
        // una venta programada para las 19:00 en España no se activase hasta
        // dos horas después durante el horario de verano.
        $appTimezone = new \DateTimeZone(env_value('APP_TIMEZONE', 'Europe/Madrid') ?? 'Europe/Madrid');
        $offset = (new \DateTimeImmutable('now', $appTimezone))->format('P');
        self::$pdo->exec('SET time_zone = ' . self::$pdo->quote($offset));

        return self::$pdo;
    }
}
