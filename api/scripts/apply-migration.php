<?php
declare(strict_types=1);

use Perigallo\Ticketing\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$root = dirname(__DIR__, 2);
$migrations = realpath($root . '/database/migrations');
$migration = realpath($argv[1] ?? '');

if ($migrations === false || $migration === false || strpos($migration, $migrations . DIRECTORY_SEPARATOR) !== 0 || pathinfo($migration, PATHINFO_EXTENSION) !== 'sql') {
    fwrite(STDERR, "Uso: php api/scripts/apply-migration.php database/migrations/NNN_nombre.sql\n");
    exit(1);
}

$sql = file_get_contents($migration);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "No se ha podido leer la migración.\n");
    exit(1);
}

Database::pdo()->exec($sql);
fwrite(STDOUT, 'Migración aplicada: ' . basename($migration) . PHP_EOL);
