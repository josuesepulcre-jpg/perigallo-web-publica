<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/src/InstagramAgentStore.php';
require dirname(__DIR__) . '/api/src/InstagramAgentService.php';

use Perigallo\Ticketing\InstagramAgentService;
use Perigallo\Ticketing\InstagramAgentStore;

$directories = [];
$passed = 0;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function throws(callable $callback, int $code, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        check($error->getCode() === $code, $message . ': código ' . $error->getCode() . ' en lugar de ' . $code);
        return;
    }
    throw new RuntimeException($message . ': no se lanzó excepción');
}

function test(string $name, callable $callback): void
{
    global $passed;
    $callback();
    $passed++;
    echo 'OK ' . $name . PHP_EOL;
}

function fixture(?callable $generator = null): array
{
    global $directories;
    $directory = sys_get_temp_dir() . '/perigallo-ig-test-' . bin2hex(random_bytes(10));
    $directories[] = $directory;
    $store = new InstagramAgentStore($directory);
    $generator ??= static fn(string $kind, array $payload): array => $kind === 'content' ? content($payload['format'] ?? 'photo') : conversation();
    return [$store, new InstagramAgentService($store, $generator), $directory];
}

function content(string $format = 'photo'): array
{
    return ['title' => 'La mesa que compartimos', 'format' => $format, 'objective' => 'Conversaciones sobre celebraciones', 'caption' => 'Cocina, espacio y hospitalidad. ¿Qué celebración estás imaginando?', 'visual_brief' => 'Fotografía real autorizada de una mesa; pendiente de seleccionar.', 'slides' => [], 'alt_text' => 'Mesa preparada para una celebración.', 'cta' => 'Cuéntanos tu idea.', 'pending_facts' => []];
}

function conversation(): array
{
    return ['classification' => 'COMMERCIAL', 'reply' => '¿En qué fecha estáis pensando y cuántas personas seríais?', 'decision' => 'REPLY', 'next_action' => 'Revisar la información recibida.', 'lead_status' => 'NEEDS_INFORMATION', 'pending_facts' => ['Disponibilidad por confirmar.']];
}

function generated(InstagramAgentService $service, string $request = 'content-request-001'): array
{
    return $service->command('generate_content', ['request_id' => $request, 'brief' => 'Una propuesta sobre celebraciones.', 'format' => 'photo'], 'owner')['draft'];
}

try {
    test('Persistencia privada y estado inicial sin operaciones reales', function (): void {
        [$store, $service, $directory] = fixture();
        $state = $service->snapshot()['state'];
        check($state['mode'] === 'SIMULATION' && $state['settings']['schedule_enabled'] === false, 'Estado inicial incorrecto');
        $draft = generated($service);
        $reloaded = (new InstagramAgentStore($directory))->read();
        check($reloaded['drafts'][0]['id'] === $draft['id'], 'No persiste entre instancias');
        check((fileperms($directory) & 0777) === 0700, 'Directorio no privado');
        check((fileperms($directory . '/state.json') & 0777) === 0600, 'Estado no privado');
        check((fileperms($directory . '/state.lock') & 0777) === 0600, 'Bloqueo no privado');
        foreach (['publish_real', 'send_real', 'set_mode'] as $op) {
            throws(fn() => $service->command($op, [], 'owner'), 403, 'Operación real bloqueada');
        }
    });

    test('Aprobación de versión, simulación idempotente y revocación por cambios', function (): void {
        [$store, $service] = fixture();
        $draft = generated($service);
        throws(fn() => $service->command('simulate_publish', ['id' => $draft['id'], 'revision' => 1], 'owner'), 409, 'Sin aprobación');
        $review = $service->command('review_draft', ['id' => $draft['id'], 'revision' => 1, 'decision' => 'approve', 'feedback' => 'Aprobado para probar.'], 'owner');
        check($review['draft']['approval']['mode'] === 'SIMULATION', 'Aprobación sin entorno');
        $first = $service->command('simulate_publish', ['id' => $draft['id'], 'revision' => 1], 'owner');
        $second = $service->command('simulate_publish', ['id' => $draft['id'], 'revision' => 1], 'owner');
        check($first['run']['id'] === $second['run']['id'] && $second['duplicate'], 'Simulación duplicada');
        check($first['run']['external_action'] === false, 'Acción externa inesperada');
        $edited = $service->command('save_draft', ['id' => $draft['id'], 'revision' => 1, 'caption' => 'Un texto distinto para la misma celebración.'], 'owner')['draft'];
        check($edited['revision'] === 2 && $edited['approval'] === null && $edited['execution_status'] === 'NOT_SCHEDULED', 'La edición no invalida aprobación');
        throws(fn() => $service->command('simulate_publish', ['id' => $draft['id'], 'revision' => 2], 'owner'), 409, 'Revisión nueva requiere aprobación');
        throws(fn() => $service->command('save_draft', ['id' => $draft['id'], 'revision' => 1, 'caption' => 'Desactualizado'], 'owner'), 409, 'Escritura de revisión antigua');
    });

    test('El hash verifica el texto completo aunque una versión sea manipulada', function (): void {
        [$store, $service] = fixture();
        $draft = generated($service);
        $service->command('review_draft', ['id' => $draft['id'], 'revision' => 1, 'decision' => 'approve'], 'owner');
        $store->transaction(function (array &$state): void { $state['drafts'][0]['caption'] = 'Cambio sin nueva aprobación'; });
        throws(fn() => $service->command('simulate_publish', ['id' => $draft['id'], 'revision' => 1], 'owner'), 409, 'Hash de aprobación desactualizado');
    });

    test('Pausa impide generación y salidas, pero permite correcciones y revisión', function (): void {
        [$store, $service] = fixture();
        $draft = generated($service);
        $service->command('pause', [], 'owner');
        throws(fn() => generated($service, 'paused-request-001'), 423, 'Generación durante pausa');
        $edited = $service->command('save_draft', ['id' => $draft['id'], 'revision' => 1, 'caption' => 'Edición durante la pausa.'], 'owner')['draft'];
        $service->command('review_draft', ['id' => $draft['id'], 'revision' => $edited['revision'], 'decision' => 'approve'], 'owner');
        throws(fn() => $service->command('simulate_publish', ['id' => $draft['id'], 'revision' => $edited['revision']], 'owner'), 423, 'Salida durante pausa');
        $service->command('resume', [], 'owner');
        check(generated($service, 'resumed-request-001')['id'] !== '', 'Reanudación fallida');
    });

    test('Idempotencia reserva antes de la IA y detecta colisión de payload', function (): void {
        $calls = 0;
        $service = null;
        [$store, $service] = fixture(function (string $kind, array $payload) use (&$calls, &$service): array {
            $calls++;
            $pending = $service->command('generate_content', ['request_id' => 'concurrent-request', 'brief' => 'Encargo concurrente', 'format' => 'photo'], 'owner');
            check($pending['pending'] === true && $pending['duplicate'] === true, 'No se observa la reserva concurrente');
            return content();
        });
        $input = ['request_id' => 'concurrent-request', 'brief' => 'Encargo concurrente', 'format' => 'photo'];
        $first = $service->command('generate_content', $input, 'owner');
        $second = $service->command('generate_content', $input, 'owner');
        check($calls === 1 && $first['draft']['id'] === $second['draft']['id'], 'La IA se llamó más de una vez');
        throws(fn() => $service->command('generate_content', array_merge($input, ['brief' => 'Otro encargo']), 'owner'), 409, 'Mismo request_id con datos distintos');
    });

    test('Los fallos se registran y no se reintentan al repetir la petición', function (): void {
        $calls = 0;
        [$store, $service] = fixture(function () use (&$calls): array { $calls++; throw new RuntimeException('Ejemplo de error de transporte; no debe persistirse.'); });
        $input = ['request_id' => 'failed-request-001', 'brief' => 'Prueba de fallo', 'format' => 'photo'];
        throws(fn() => $service->command('generate_content', $input, 'owner'), 502, 'Error del generador');
        $again = $service->command('generate_content', $input, 'owner');
        check($calls === 1 && $again['ok'] === false && $again['run']['status'] === 'FAILED', 'Reintento ciego');
        check($store->read()['drafts'] === [], 'El fallo creó contenido');
        check(!str_contains(json_encode($store->read()), 'Ejemplo de error de transporte'), 'Error sensible persistido');
    });

    test('El límite diario incluye intentos fallidos y no cobra reservas duplicadas', function (): void {
        [$store, $service] = fixture();
        $service->command('save_settings', ['daily_generation_limit' => 1], 'owner');
        generated($service, 'limited-request-001');
        generated($service, 'limited-request-001');
        throws(fn() => generated($service, 'limited-request-002'), 429, 'Límite diario no aplicado');
        [$failedStore, $failedService] = fixture(static function (): array { throw new RuntimeException('fallo'); });
        $failedService->command('save_settings', ['daily_generation_limit' => 1], 'owner');
        throws(fn() => generated($failedService, 'limited-failed-001'), 502, 'Fallo reservado');
        throws(fn() => generated($failedService, 'limited-failed-002'), 429, 'Fallo no consume reserva');
    });

    test('No contactar prevalece sobre la IA y cancela seguimientos', function (): void {
        [$store, $service] = fixture();
        $first = $service->command('simulate_conversation', ['request_id' => 'conversation-start', 'message' => 'Quiero información para una boda.', 'label' => 'Caso ficticio'], 'owner')['conversation'];
        $task = $service->command('save_task', ['title' => 'Seguimiento de la consulta', 'conversation_id' => $first['id'], 'type' => 'FOLLOW_UP'], 'owner')['task'];
        $stopped = $service->command('simulate_conversation', ['request_id' => 'conversation-stop1', 'conversation_id' => $first['id'], 'message' => 'No me escribáis más.'], 'owner')['conversation'];
        check($stopped['decision'] === 'NO_CONTACT' && $stopped['no_contact'] === true, 'No contactar no prevalece');
        check($stopped['reply'] === '', 'Se preparó una respuesta automática tras la baja');
        $tasks = $store->read()['tasks'];
        $savedTask = array_values(array_filter($tasks, static fn(array $t): bool => $t['id'] === $task['id']))[0];
        check($savedTask['status'] === 'CANCELLED', 'Seguimiento no cancelado');
        check(count(array_filter($tasks, static fn(array $t): bool => $t['type'] === 'FOLLOW_UP' && $t['status'] === 'OPEN')) === 0, 'Seguimiento generado por IA no cancelado');
        throws(fn() => $service->command('save_task', ['title' => 'Otro seguimiento', 'conversation_id' => $first['id'], 'type' => 'FOLLOW_UP'], 'owner'), 409, 'Seguimiento reabierto después de baja');
        $later = $service->command('simulate_conversation', ['request_id' => 'conversation-later', 'conversation_id' => $first['id'], 'message' => 'Gracias'], 'owner')['conversation'];
        check($later['no_contact'] === true, 'Una respuesta neutra borró la baja');
    });

    test('Intervención humana conserva la consulta y evita seguimientos automáticos', function (): void {
        [$store, $service] = fixture();
        $response = $service->command('simulate_conversation', ['request_id' => 'human-handoff-001', 'message' => 'Quiero hablar con una persona del equipo.'], 'owner');
        check($response['conversation']['handoff'] === true && $response['conversation']['decision'] === 'HUMAN_TAKEOVER', 'No se detectó intervención humana');
        check($response['conversation']['reply'] === '', 'Se preparó respuesta automática durante intervención humana');
        check($response['conversation']['reply_status'] === 'DRAFT', 'Se afirma haber enviado respuesta');
        check(count($store->read()['tasks']) === 1 && $store->read()['tasks'][0]['type'] === 'INTERNAL', 'Falta tarea interna');
    });

    test('Pausa durante una llamada descarta salida y no mantiene el lock de almacenamiento', function (): void {
        $service = null;
        [$store, $service] = fixture(function () use (&$service): array {
            $service->command('pause', [], 'owner');
            return content();
        });
        $result = $service->command('generate_content', ['request_id' => 'pause-inflight-001', 'brief' => 'Prueba', 'format' => 'photo'], 'owner');
        check($result['discarded'] && $result['run']['status'] === 'DISCARDED' && $store->read()['drafts'] === [], 'Salida creada después de pausa');
    });

    test('Programación registra heartbeat y ejecuta como máximo un ciclo por día', function (): void {
        $calls = 0;
        [$store, $service] = fixture(function () use (&$calls): array { $calls++; return content(); });
        $disabled = $service->runScheduled();
        check($disabled['skipped'] && $disabled['reason'] === 'schedule_disabled', 'Programación por defecto activada');
        check($store->read()['schedule']['last_heartbeat'] !== null, 'No se registra heartbeat');
        $service->command('save_settings', ['schedule_enabled' => true, 'schedule_time' => '00:00'], 'owner');
        $first = $service->runScheduled();
        $second = $service->runScheduled();
        check($calls === 1 && isset($first['draft']) && $second['skipped'], 'Ciclo diario duplicado');
        check($store->read()['schedule']['last_run_id'] === $first['run']['id'], 'No se conserva run_id programado');
    });

    test('No se reintenta automáticamente un ciclo programado fallido', function (): void {
        $calls = 0;
        [$store, $service] = fixture(function () use (&$calls): array { $calls++; throw new RuntimeException('fallo'); });
        $service->command('save_settings', ['schedule_enabled' => true, 'schedule_time' => '00:00'], 'owner');
        throws(fn() => $service->runScheduled(), 502, 'Ciclo fallido');
        check($service->runScheduled()['skipped'] && $calls === 1, 'Ciclo fallido reintentado');
    });

    test('Una pausa revoca la salida pendiente incluso si se reanuda antes de terminar', function (): void {
        $service = null;
        [$store, $service] = fixture(function () use (&$service): array {
            $service->command('pause', [], 'owner');
            $service->command('resume', [], 'owner');
            return content();
        });
        $result = $service->command('generate_content', ['request_id' => 'pause-resume-flight', 'brief' => 'Prueba', 'format' => 'photo'], 'owner');
        check($result['discarded'] && $store->read()['drafts'] === [], 'Reanudar resucitó un trabajo cancelado');
    });

    test('La trazabilidad de IA conserva solo identificadores y consumo permitidos', function (): void {
        [$store, $service] = fixture(static fn(): array => array_merge(content(), ['_generation' => [
            'response_id' => 'resp_example123', 'model' => 'test-model',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30, 'input_tokens_details' => ['cached_tokens' => 5, 'secret' => 'NO'], 'output_tokens_details' => ['reasoning_tokens' => 2], 'raw' => 'NO'],
            'authorization' => 'NO',
        ]]));
        generated($service);
        $metadata = $store->read()['runs'][0]['generation'];
        check($metadata['response_id'] === 'resp_example123' && $metadata['usage']['total_tokens'] === 30, 'No se conservó trazabilidad');
        check(!isset($metadata['authorization'], $metadata['usage']['raw']) && !isset($metadata['usage']['input_tokens_details']['secret']), 'Se conservó metadato no permitido');
    });

    test('Un timeout conserva resultado UNKNOWN y no lo reintenta automáticamente', function (): void {
        $calls = 0;
        [$store, $service] = fixture(function () use (&$calls): array { $calls++; throw new RuntimeException('Mensaje interno sensible', 504); });
        $input = ['request_id' => 'timeout-request-01', 'message' => 'Una consulta ficticia'];
        throws(fn() => $service->command('simulate_conversation', $input, 'owner'), 504, 'Timeout');
        $again = $service->command('simulate_conversation', $input, 'owner');
        check($again['unknown'] && $again['run']['status'] === 'UNKNOWN' && $calls === 1, 'Timeout reintentado');
        check($store->read()['conversations'][0]['pending_run_id'] === null, 'Conversación bloqueada tras timeout');
        check(!str_contains(json_encode($store->read()), 'Mensaje interno sensible'), 'Se filtró error de callback');
        check($service->snapshot()['report']['counts']['unknown_runs'] === 1, 'Informe oculta resultado incierto');
    });

    test('Las ejecuciones abandonadas se reconcilian como UNKNOWN sin otra llamada IA', function (): void {
        $calls = 0;
        [$store, $service] = fixture(function () use (&$calls): array { $calls++; return conversation(); });
        $input = ['request_id' => 'abandoned-request1', 'message' => 'Una consulta ficticia'];
        $result = $service->command('simulate_conversation', $input, 'owner');
        $runId = $result['run']['id'];
        $store->transaction(function (array &$state) use ($runId): void {
            $state['runs'][0]['status'] = 'RUNNING';
            $state['runs'][0]['created_at'] = gmdate('c', time() - 125);
            $state['runs'][0]['completed_at'] = null;
            $state['conversations'][0]['pending_run_id'] = $runId;
            $state['conversations'][0]['status'] = 'GENERATING';
        });
        $snapshot = $service->snapshot();
        check($snapshot['state']['runs'][0]['status'] === 'UNKNOWN', 'La ejecución abandonada sigue RUNNING');
        check($snapshot['state']['conversations'][0]['pending_run_id'] === null, 'La conversación sigue bloqueada');
        check($service->command('simulate_conversation', $input, 'owner')['unknown'] && $calls === 1, 'Se repitió una ejecución incierta');
    });

    test('La aprobación exige resolver hechos y confirmar derechos de los recursos', function (): void {
        [$store, $service] = fixture();
        $draft = $service->command('save_draft', array_merge(content(), ['pending_facts' => ['Confirmar fecha.'], 'asset_url' => '/media/photo.jpg']), 'owner')['draft'];
        throws(fn() => $service->command('review_draft', ['id' => $draft['id'], 'revision' => 1, 'decision' => 'approve'], 'owner'), 409, 'Hechos pendientes aprobados');
        $edited = $service->command('save_draft', ['id' => $draft['id'], 'revision' => 1, 'pending_facts' => []], 'owner')['draft'];
        throws(fn() => $service->command('review_draft', ['id' => $draft['id'], 'revision' => $edited['revision'], 'decision' => 'approve'], 'owner'), 409, 'Recurso sin derechos aprobado');
        $edited = $service->command('save_draft', ['id' => $draft['id'], 'revision' => $edited['revision'], 'asset_rights_confirmed' => true], 'owner')['draft'];
        check($service->command('review_draft', ['id' => $draft['id'], 'revision' => $edited['revision'], 'decision' => 'approve'], 'owner')['draft']['editorial_status'] === 'APPROVED', 'No se puede aprobar tras resolver pendientes');
    });

    test('La edición de tareas exige la revisión vigente', function (): void {
        [$store, $service] = fixture();
        $task = $service->command('save_task', ['title' => 'Revisión interna'], 'owner')['task'];
        throws(fn() => $service->command('save_task', ['id' => $task['id'], 'status' => 'DONE'], 'owner'), 409, 'Tarea editada sin revisión');
        $edited = $service->command('save_task', ['id' => $task['id'], 'revision' => 1, 'status' => 'DONE'], 'owner')['task'];
        check($edited['revision'] === 2 && $edited['status'] === 'DONE', 'Tarea no actualizada');
        throws(fn() => $service->command('save_task', ['id' => $task['id'], 'revision' => 1, 'status' => 'OPEN'], 'owner'), 409, 'Actualización de tarea antigua');
    });

    test('Los errores seguros del generador conservan código y orientación útil', function (): void {
        require_once dirname(__DIR__) . '/api/src/InstagramAgentGenerator.php';
        $key = getenv('OPENAI_API_KEY');
        $keyFile = getenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE');
        $model = getenv('INSTAGRAM_AGENT_MODEL');
        try {
            putenv('OPENAI_API_KEY');
            putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE');
            putenv('INSTAGRAM_AGENT_MODEL');
            $generator = new Perigallo\Ticketing\InstagramAgentGenerator();
            [$store, $service] = fixture([$generator, 'generate']);
            throws(fn() => generated($service, 'missing-credential1'), 401, 'Clave ausente');
            check(str_contains($store->read()['runs'][0]['error'], 'clave de OpenAI') && $store->read()['runs'][0]['error_code'] === 401, 'Se perdió la orientación de configuración');
            putenv('OPENAI_API_KEY=test-not-a-real-key');
            $generator = new Perigallo\Ticketing\InstagramAgentGenerator(static fn(): array => ['status' => 429, 'body' => 'raw-body-must-not-be-stored']);
            [$quotaStore, $quotaService] = fixture([$generator, 'generate']);
            throws(fn() => generated($quotaService, 'quota-request-001'), 429, 'Cuota del proveedor');
            check(str_contains($quotaStore->read()['runs'][0]['error'], 'cuota'), 'Se perdió la orientación de cuota');
            check(!str_contains(json_encode($quotaStore->read()), 'raw-body-must-not-be-stored'), 'Se guardó el cuerpo del proveedor');
        } finally {
            $key === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $key);
            $keyFile === false ? putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE') : putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $keyFile);
            $model === false ? putenv('INSTAGRAM_AGENT_MODEL') : putenv('INSTAGRAM_AGENT_MODEL=' . $model);
        }
    });

    test('Informes calculados desde registros y métricas de Instagram N/D', function (): void {
        [$store, $service] = fixture();
        $draft = generated($service);
        $service->command('review_draft', ['id' => $draft['id'], 'revision' => 1, 'decision' => 'approve'], 'owner');
        $service->command('simulate_publish', ['id' => $draft['id'], 'revision' => 1], 'owner');
        $service->command('simulate_conversation', ['request_id' => 'report-conversation', 'message' => 'Quiero más información.'], 'owner');
        $report = $service->snapshot()['report'];
        check($report['counts']['drafts'] === 1 && $report['counts']['approved'] === 1 && $report['counts']['simulated_publications'] === 1 && $report['counts']['conversations'] === 1, 'Conteo incorrecto');
        check($report['instagram']['reach'] === null && $report['instagram']['revenue'] === null && $report['instagram']['connected'] === false, 'Métricas reales inventadas');
    });

    test('El historial de conversaciones respeta el presupuesto de contexto', function (): void {
        [$store, $service] = fixture(static function (string $kind, array $payload): array {
            check(strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) < 64000, 'El historial excede el límite del proveedor');
            check(strlen($payload['brand_context']) === 20000 && strlen($payload['message']) === 4800, 'Se truncaron hechos o el mensaje actual');
            return conversation();
        });
        $service->command('save_settings', ['brand_context' => str_repeat('a', 20000), 'daily_generation_limit' => 20], 'owner');
        $conversationId = null;
        for ($i = 0; $i < 12; $i++) {
            $result = $service->command('simulate_conversation', ['request_id' => 'long-history-' . $i, 'message' => str_repeat('a', 4800), 'conversation_id' => $conversationId], 'owner');
            $conversationId = $result['conversation']['id'];
        }
        check(count($store->read()['conversations'][0]['messages']) === 24, 'Se perdió el historial persistido al acotar contexto');
    });

    test('Validación de tamaño, URL externa y fechas no altera el estado', function (): void {
        [$store, $service] = fixture();
        throws(fn() => $service->command('save_draft', array_merge(content(), ['caption' => str_repeat('a', 2201)]), 'owner'), 400, 'Caption excesivo');
        foreach (['https://example.com/a.jpg', '//example.com/a.jpg', 'javascript:alert(1)', 'https://perigallo.com.evil.example/a.jpg'] as $url) {
            throws(fn() => $service->command('save_draft', array_merge(content(), ['asset_url' => $url]), 'owner'), 400, 'URL externa');
        }
        throws(fn() => $service->command('save_draft', array_merge(content(), ['scheduled_at' => '2026-02-31T10:00']), 'owner'), 400, 'Fecha inválida');
        check($store->read()['drafts'] === [], 'Validación fallida creó registros');
    });

    test('Corrupción falla sin sobrescribir ni reinicializar datos', function (): void {
        [$store, $service, $directory] = fixture();
        generated($service);
        file_put_contents($directory . '/state.json', '{invalid');
        throws(fn() => $store->read(), 500, 'JSON corrupto');
        throws(fn() => $service->command('pause', [], 'owner'), 500, 'Transacción con corrupción');
        check(file_get_contents($directory . '/state.json') === '{invalid', 'Se sobrescribió la corrupción');
    });

    test('Una excepción en transacción conserva los datos anteriores', function (): void {
        [$store, $service] = fixture();
        generated($service);
        try {
            $store->transaction(function (array &$state): void { $state['drafts'] = []; throw new RuntimeException('rollback'); });
        } catch (RuntimeException $error) {
            check($error->getMessage() === 'rollback', 'Excepción inesperada');
        }
        check(count($store->read()['drafts']) === 1, 'La transacción fallida perdió los datos');
    });

    echo 'PASS ' . $passed . ' pruebas de dominio; generador sintético, sin red ni credenciales.' . PHP_EOL;
} finally {
    foreach ($directories as $directory) {
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($directory . '/.state-*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
