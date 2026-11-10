<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/src/InstagramAgentGenerator.php';

use Perigallo\Ticketing\InstagramAgentGenerator;

// Todos los casos usan transporte inyectado; este archivo no hace llamadas de red.
$assertions = 0;
$secret = 'sk-test-FAKE-NOT-A-REAL-KEY';
$providerDetail = 'PROVIDER-PRIVATE-DETAIL';

function iga_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function iga_error(callable $operation, int $status, string $message): void
{
    global $secret, $providerDetail;
    try {
        $operation();
    } catch (RuntimeException $error) {
        iga_assert($error->getCode() === $status, $message . ': código inesperado.');
        iga_assert(!str_contains($error->getMessage(), $secret) && !str_contains($error->getMessage(), $providerDetail), 'Un error ha expuesto detalles privados.');
        iga_assert($error->getPrevious() === null, 'Un error ha conservado una excepción privada.');
        return;
    }
    throw new RuntimeException($message . ': se aceptó un caso inválido.');
}

function iga_content(): array
{
    return [
        'title' => 'Una mesa para compartir',
        'format' => 'photo',
        'objective' => 'Presentar la propuesta de celebraciones de Perigallo.',
        'caption' => 'Hay celebraciones que empiezan alrededor de una mesa. ¿Qué te gustaría celebrar?',
        'visual_brief' => 'Propuesta: fotografía real autorizada de una mesa preparada, con luz natural.',
        'slides' => [],
        'alt_text' => 'Propuesta de una mesa preparada para una celebración.',
        'cta' => 'Cuéntanos qué te gustaría celebrar.',
        'pending_facts' => ['Seleccionar una fotografía autorizada.'],
    ];
}

function iga_conversation(): array
{
    return [
        'classification' => 'EVENT_INQUIRY',
        'reply' => '¡Hola! ¿Qué tipo de celebración tienes en mente y para qué fecha orientativa?',
        'decision' => 'REPLY',
        'next_action' => 'Revisar la respuesta propuesta en el prototipo.',
        'lead_status' => 'QUALIFYING',
        'pending_facts' => [],
    ];
}

function iga_response(array $output, array $overrides = []): array
{
    return array_replace([
        'id' => 'resp_mock_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'gpt-5.4-mini-2026-03-17',
        'output' => [
            ['type' => 'reasoning', 'summary' => []],
            [
                'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'annotations' => []]],
            ],
        ],
        'usage' => [
            'input_tokens' => 300, 'output_tokens' => 120, 'total_tokens' => 420,
            'input_tokens_details' => ['cached_tokens' => 0],
            'output_tokens_details' => ['reasoning_tokens' => 20],
            'private_detail' => 'DO-NOT-RETURN',
        ],
    ], $overrides);
}

function iga_generator(array $response, int $status = 200): InstagramAgentGenerator
{
    return new InstagramAgentGenerator(static fn (array $request): array => [
        'status' => $status,
        'body' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}

function iga_invalid_output(array $output, string $kind = 'content', array $payload = []): void
{
    iga_error(static fn () => iga_generator(iga_response($output))->generate($kind, $payload), 502, 'La salida no cumple el contrato');
}

$environmentKeys = ['OPENAI_API_KEY', 'INSTAGRAM_AGENT_MODEL', 'INSTAGRAM_AGENT_OPENAI_KEY_FILE'];
$original = [];
foreach ($environmentKeys as $name) {
    $original[$name] = getenv($name);
}
$originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
$temporary = sys_get_temp_dir() . '/perigallo-instagram-generator-' . bin2hex(random_bytes(8));
$publicFixture = __DIR__ . '/instagram-agent-key-test-' . bin2hex(random_bytes(8)) . '.tmp';
$cleanup = [];

try {
    foreach ($environmentKeys as $name) {
        putenv($name);
    }
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

    // No key: fail before invoking transport; configuration contains no paths/secrets.
    $calls = 0;
    $captured = null;
    $client = new InstagramAgentGenerator(static function (array $request) use (&$calls, &$captured): array {
        $calls++;
        $captured = $request;
        return ['status' => 200, 'body' => json_encode(iga_response(iga_content()), JSON_THROW_ON_ERROR)];
    });
    iga_assert($client->configuration()['configured'] === false, 'La configuración sin clave aparece activa.');
    iga_error(static fn () => $client->generate('content', []), 401, 'Sin clave');
    iga_assert($calls === 0, 'Se invocó el transporte sin clave.');

    putenv('OPENAI_API_KEY=' . $secret);
    $configuration = $client->configuration();
    iga_assert($configuration === [
        'configured' => true, 'model' => 'gpt-5.4-mini', 'provider' => 'openai',
        'api' => 'responses', 'curl_available' => function_exists('curl_init'),
    ], 'El contrato de configuración cambió.');
    $result = $client->generate('content', ['brief' => 'Una celebración cercana', 'format' => 'photo']);
    iga_assert($calls === 1 && $result['title'] === iga_content()['title'], 'No se procesó una respuesta con reasoning antes de message.');
    iga_assert($result['_generation']['response_id'] === 'resp_mock_123', 'No se conservó el identificador del proveedor.');
    iga_assert($result['_generation']['model'] === 'gpt-5.4-mini-2026-03-17', 'No se conservó el modelo devuelto.');
    iga_assert($result['_generation']['usage']['total_tokens'] === 420 && !isset($result['_generation']['usage']['private_detail']), 'Los metadatos de uso no se filtraron correctamente.');
    iga_assert($captured['model'] === 'gpt-5.4-mini' && $captured['store'] === false && $captured['max_output_tokens'] === 4500, 'La petición no respeta modelo, almacenamiento o límite de tokens.');
    iga_assert($captured['reasoning'] === ['effort' => 'low'] && !isset($captured['tools']), 'La petición añadió herramientas o cambió el esfuerzo.');
    iga_assert($captured['text']['format']['type'] === 'json_schema' && $captured['text']['format']['strict'] === true, 'No se solicitó el esquema estricto.');
    iga_assert(!str_contains(json_encode($captured, JSON_THROW_ON_ERROR), $secret), 'El transporte de pruebas recibió una credencial.');
    iga_assert(str_contains($captured['instructions'], 'SIMULATION') && str_contains($captured['instructions'], 'Todos los precios'), 'Faltan límites esenciales del prompt.');
    $input = json_decode($captured['input'][0]['content'], true, 32, JSON_THROW_ON_ERROR);
    iga_assert($input['mode'] === 'SIMULATION' && $input['data']['format'] === 'photo', 'El contexto no está separado de las instrucciones.');

    $conversation = iga_generator(iga_response(iga_conversation()))->generate('conversation', ['message' => 'Quiero celebrar mi boda']);
    iga_assert($conversation['decision'] === 'REPLY' && $conversation['classification'] === 'EVENT_INQUIRY', 'No se aceptó la conversación válida.');
    $noContact = array_replace(iga_conversation(), ['decision' => 'NO_CONTACT', 'reply' => '', 'lead_status' => 'NO_CONTACT']);
    iga_assert(iga_generator(iga_response($noContact))->generate('conversation', ['no_contact' => true, 'handoff' => true])['reply'] === '', 'La exclusión no prevaleció sobre la atención humana.');
    $human = array_replace(iga_conversation(), ['decision' => 'HUMAN_TAKEOVER', 'reply' => '', 'lead_status' => 'HUMAN_TAKEOVER']);
    iga_assert(iga_generator(iga_response($human))->generate('conversation', ['handoff' => true])['reply'] === '', 'La atención humana no suspendió la propuesta.');

    // No silent fallback and no retries when a model is unavailable.
    putenv('INSTAGRAM_AGENT_MODEL=unknown-model-for-test');
    $unknownCalls = 0;
    $unknownClient = new InstagramAgentGenerator(static function (array $request) use (&$unknownCalls, $secret, $providerDetail): array {
        $unknownCalls++;
        iga_assert($request['model'] === 'unknown-model-for-test', 'Se sustituyó silenciosamente el modelo configurado.');
        return ['status' => 404, 'body' => json_encode(['error' => ['message' => $secret . $providerDetail]], JSON_THROW_ON_ERROR)];
    });
    iga_error(static fn () => $unknownClient->generate('content', []), 502, 'Modelo desconocido');
    iga_assert($unknownCalls === 1, 'Se reintentó la petición a un modelo desconocido.');
    putenv('INSTAGRAM_AGENT_MODEL');

    foreach ([401 => 401, 403 => 401, 429 => 429, 400 => 502, 404 => 502, 422 => 502, 500 => 503, 503 => 503, 408 => 504, 504 => 504, 302 => 502] as $upstream => $expected) {
        $httpClient = iga_generator(['error' => ['message' => $secret . $providerDetail]], $upstream);
        iga_error(static fn () => $httpClient->generate('content', []), $expected, 'Error HTTP ' . $upstream);
    }
    $timeoutCalls = 0;
    $timeoutClient = new InstagramAgentGenerator(static function (array $request) use (&$timeoutCalls, $secret): array {
        $timeoutCalls++;
        throw new RuntimeException($secret, 504);
    });
    iga_error(static fn () => $timeoutClient->generate('content', []), 504, 'Tiempo de espera agotado');
    iga_assert($timeoutCalls === 1, 'Se reintentó un resultado incierto.');
    iga_error(static fn () => (new InstagramAgentGenerator(static fn (array $r): array => ['status' => '200', 'body' => '{}']))->generate('content', []), 502, 'Contrato de transporte incorrecto');
    iga_error(static fn () => (new InstagramAgentGenerator(static fn (array $r): array => ['status' => 200, 'body' => '{']))->generate('content', []), 502, 'JSON del proveedor inválido');
    iga_error(static fn () => (new InstagramAgentGenerator(static fn (array $r): array => ['status' => 200, 'body' => str_repeat('x', 2097153)]))->generate('content', []), 502, 'Respuesta demasiado extensa');

    $refusal = iga_response(iga_content());
    $refusal['output'][1]['content'] = [['type' => 'refusal', 'refusal' => $secret . $providerDetail]];
    iga_error(static fn () => iga_generator($refusal)->generate('content', []), 422, 'Negativa del modelo');
    foreach (['incomplete', 'failed', 'in_progress', 'queued', 'cancelled'] as $state) {
        iga_error(static fn () => iga_generator(iga_response(iga_content(), ['status' => $state, 'incomplete_details' => ['reason' => 'max_output_tokens']]))->generate('content', []), 502, 'Respuesta ' . $state);
    }
    $toolCall = iga_response(iga_content());
    $toolCall['output'][] = ['type' => 'function_call', 'name' => 'publish'];
    iga_error(static fn () => iga_generator($toolCall)->generate('content', []), 502, 'Herramienta no permitida');
    iga_error(static fn () => iga_generator(iga_response(iga_content(), ['output' => [['type' => 'reasoning', 'summary' => []]]]))->generate('content', []), 502, 'Salida sin mensaje');
    iga_error(static fn () => iga_generator(iga_response(iga_content(), ['id' => "resp_invalid\n"]))->generate('content', []), 502, 'Identificador no válido');
    iga_assert(iga_generator(iga_response(iga_content(), ['usage' => null]))->generate('content', [])['_generation']['usage'] === null, 'Se inventó consumo de tokens no informado.');

    // Every field is required, typed, bounded and closed to additional properties.
    $missing = iga_content();
    unset($missing['caption']);
    iga_invalid_output($missing);
    iga_invalid_output(array_replace(iga_content(), ['extra' => 'unexpected']));
    iga_invalid_output(array_replace(iga_content(), ['caption' => null]));
    iga_invalid_output(array_replace(iga_content(), ['caption' => str_repeat('á', 2201)]));
    iga_invalid_output(array_replace(iga_content(), ['caption' => "texto\0oculto"]));
    iga_invalid_output(array_replace(iga_content(), ['title' => '  ']));
    iga_invalid_output(array_replace(iga_content(), ['format' => 'video']));
    iga_invalid_output(array_replace(iga_content(), ['pending_facts' => array_fill(0, 21, 'Pendiente') ]));
    iga_invalid_output(array_replace(iga_content(), ['pending_facts' => ['fecha' => 'Pendiente']]));
    iga_invalid_output(array_replace(iga_content(), ['slides' => [(object) ['title' => 'Una lámina', 'body' => '', 'visual_brief' => 'Fotografía']]]));
    $longCaption = array_replace(iga_content(), ['caption' => str_repeat('á', 2200)]);
    iga_assert(iga_generator(iga_response($longCaption))->generate('content', [])['caption'] === $longCaption['caption'], 'Se contaron bytes en lugar de caracteres.');
    $arrayAsObject = iga_response(iga_content());
    $arrayAsObject['output'][1]['content'][0]['text'] = str_replace('"slides":[]', '"slides":{}', $arrayAsObject['output'][1]['content'][0]['text']);
    iga_error(static fn () => iga_generator($arrayAsObject)->generate('content', []), 502, 'Objeto utilizado como lista vacía');

    $slide = ['title' => 'Portada', 'body' => 'Una idea para celebrar', 'visual_brief' => 'Composición conceptual con texto legible.'];
    $carousel = array_replace(iga_content(), ['format' => 'carousel', 'slides' => [$slide, $slide]]);
    iga_assert(count(iga_generator(iga_response($carousel))->generate('content', ['format' => 'carousel'])['slides']) === 2, 'No se aceptó un carrusel válido.');
    iga_invalid_output(array_replace($carousel, ['slides' => [$slide]]));
    iga_invalid_output(array_replace($carousel, ['slides' => array_fill(0, 11, $slide)]));
    iga_invalid_output(array_replace($carousel, ['slides' => [array_replace($slide, ['body' => null]), $slide]]));
    iga_invalid_output($carousel, 'content', ['format' => 'photo']);
    foreach (['reel', 'story'] as $format) {
        iga_invalid_output(array_replace(iga_content(), ['format' => $format]));
        iga_assert(iga_generator(iga_response(array_replace(iga_content(), ['format' => $format, 'slides' => [$slide]])))->generate('content', [])['format'] === $format, 'Se rechazó un guion válido.');
    }
    iga_invalid_output(array_replace(iga_conversation(), ['decision' => 'SEND']), 'conversation');
    iga_invalid_output(array_replace(iga_conversation(), ['reply' => '']), 'conversation');
    iga_invalid_output(array_replace(iga_conversation(), ['reply' => str_repeat('a', 2001)]), 'conversation');
    iga_invalid_output(array_replace(iga_conversation(), ['classification' => 123]), 'conversation');
    iga_invalid_output(array_replace(iga_conversation(), ['decision' => 'NO_CONTACT']), 'conversation');
    iga_invalid_output(array_replace(iga_conversation(), ['decision' => 'HUMAN_TAKEOVER']), 'conversation');
    iga_invalid_output(iga_conversation(), 'conversation', ['no_contact' => true]);
    iga_invalid_output(iga_conversation(), 'conversation', ['handoff' => true]);

    $beforeInvalidInput = $calls;
    iga_error(static fn () => $client->generate('publish', []), 422, 'Tipo no admitido');
    iga_error(static fn () => $client->generate('content', ['format' => 'video']), 422, 'Formato no admitido');
    iga_error(static fn () => $client->generate('conversation', ['no_contact' => 'true']), 422, 'Estado de contacto no booleano');
    iga_error(static fn () => $client->generate('content', ['brief' => str_repeat('x', 65537)]), 422, 'Entrada demasiado extensa');
    iga_error(static fn () => $client->generate('content', ['brief' => "\xC3\x28"]), 422, 'Entrada UTF-8 inválida');
    iga_error(static fn () => $client->generate('content', ['brief' => new stdClass()]), 422, 'Entrada con objeto no admitido');
    iga_assert($calls === $beforeInvalidInput, 'Se invocó el transporte con una entrada inválida.');

    // Keys are server-side and must be in an absolute, private path without symlinks.
    iga_assert(mkdir($temporary, 0700), 'No se creó el directorio privado de pruebas.');
    $keyFile = $temporary . '/openai.key';
    file_put_contents($keyFile, $secret . "\n");
    chmod($keyFile, 0600);
    $cleanup[] = $keyFile;
    putenv('OPENAI_API_KEY');
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $keyFile);
    iga_assert($client->configuration()['configured'] === true, 'No se leyó la clave privada.');
    iga_assert($client->generate('content', [])['format'] === 'photo', 'No se generó con clave privada.');
    $safeConfig = json_encode($client->configuration(), JSON_THROW_ON_ERROR);
    iga_assert(!str_contains($safeConfig, $secret) && !str_contains($safeConfig, $keyFile), 'La configuración pública expuso clave o ruta.');
    putenv('OPENAI_API_KEY=invalid key');
    iga_error(static fn () => $client->generate('content', []), 401, 'La clave de entorno inválida se ignoró');
    putenv('OPENAI_API_KEY=' . $secret);
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=relative.key');
    iga_assert($client->configuration()['configured'] === true, 'La variable de entorno no tuvo prioridad sobre el archivo.');
    putenv('OPENAI_API_KEY');
    iga_error(static fn () => $client->generate('content', []), 401, 'Ruta relativa');
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $temporary . '/../other.key');
    iga_error(static fn () => $client->generate('content', []), 401, 'Traversal en ruta');
    file_put_contents($publicFixture, $secret);
    $cleanup[] = $publicFixture;
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $publicFixture);
    iga_error(static fn () => $client->generate('content', []), 401, 'Clave dentro del directorio público');
    $link = $temporary . '/linked.key';
    iga_assert(symlink($keyFile, $link), 'No se creó el enlace de prueba.');
    $cleanup[] = $link;
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $link);
    iga_error(static fn () => $client->generate('content', []), 401, 'Archivo de clave enlazado');
    $directoryLink = $temporary . '/linked-directory';
    iga_assert(symlink($temporary, $directoryLink), 'No se creó el directorio enlazado de prueba.');
    $cleanup[] = $directoryLink;
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $directoryLink . '/openai.key');
    iga_error(static fn () => $client->generate('content', []), 401, 'Directorio de clave enlazado');
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $temporary . '/missing.key');
    iga_assert($client->configuration()['configured'] === false, 'Un archivo inexistente aparece configurado.');
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $temporary);
    iga_error(static fn () => $client->generate('content', []), 401, 'Directorio utilizado como clave');
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $keyFile);
    file_put_contents($keyFile, str_repeat('x', 4097));
    clearstatcache();
    iga_error(static fn () => $client->generate('content', []), 401, 'Clave demasiado extensa');
    putenv('OPENAI_API_KEY=' . $secret);
    putenv("INSTAGRAM_AGENT_MODEL=invalid model\n");
    iga_error(static fn () => $client->generate('content', []), 401, 'Configuración de modelo inválida');

    echo 'InstagramAgentGenerator: ' . $assertions . " comprobaciones correctas; ninguna llamada de red.\n";
} finally {
    foreach ($original as $name => $value) {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
    if ($originalDocumentRoot === null) {
        unset($_SERVER['DOCUMENT_ROOT']);
    } else {
        $_SERVER['DOCUMENT_ROOT'] = $originalDocumentRoot;
    }
    foreach (array_reverse($cleanup) as $path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
    if (is_dir($temporary)) {
        rmdir($temporary);
    }
}
