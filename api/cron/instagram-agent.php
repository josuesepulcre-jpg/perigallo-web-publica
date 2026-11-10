<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\InstagramAgentRuntime;

try {
    $result = InstagramAgentRuntime::service()->runScheduled('programador');
    // Cron logs contain operational metadata, never drafts or conversations.
    echo json_encode([
        'ok' => $result['ok'] ?? false,
        'mode' => 'SIMULATION',
        'skipped' => $result['skipped'] ?? false,
        'reason' => $result['reason'] ?? null,
        'run_id' => $result['run']['id'] ?? $result['run_id'] ?? null,
        'status' => $result['run']['status'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'El ciclo del agente no se ha completado. Revísalo en el panel privado.' . PHP_EOL);
    exit(1);
}
