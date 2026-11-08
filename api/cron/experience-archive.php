<?php
declare(strict_types=1);
// CLI only; public access is also denied by api/.htaccess.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
$count=(new \Perigallo\Ticketing\ExperienceArchive(\Perigallo\Ticketing\Database::pdo()))->syncCompleted();
echo 'Borradores preparados: '.$count.PHP_EOL;
