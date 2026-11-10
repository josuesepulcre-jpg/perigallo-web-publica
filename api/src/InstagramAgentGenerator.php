<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Una inferencia acotada para el prototipo. No dispone de herramientas de salida.
 * El servicio de la aplicación controla permisos, cuotas, estados y revisiones.
 */
final class InstagramAgentGenerator
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';
    private const DEFAULT_MODEL = 'gpt-5.4-mini';
    private const MAX_RESPONSE_BYTES = 2097152;
    private const MAX_INPUT_BYTES = 65536;
    private const MAX_KEY_BYTES = 4096;

    /** @var callable|null */
    private $transport;

    /**
     * Transporte de pruebas: callable(array $request): array{status:int, body:string}.
     * Recibe el JSON de petición, nunca la clave ni las cabeceras. También exige
     * configuración válida. No se reintenta ni se invoca otra ruta en caso de fallo.
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /** @return array{configured:bool, model:string, provider:string, api:string, curl_available:bool} */
    public function configuration(): array
    {
        $model = self::DEFAULT_MODEL;
        try {
            $model = $this->model();
            $configured = $this->apiKey() !== null;
        } catch (RuntimeException $error) {
            $configured = false;
        }
        return [
            'configured' => $configured,
            'model' => $model,
            'provider' => 'openai',
            'api' => 'responses',
            'curl_available' => function_exists('curl_init'),
        ];
    }

    /** @return array<string, mixed> Campos del borrador y metadatos en _generation. */
    public function generate(string $kind, array $payload): array
    {
        if (!in_array($kind, ['content', 'conversation'], true)) {
            throw new RuntimeException('El tipo de generación no es válido.', 422);
        }
        $model = $this->model();
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            throw new RuntimeException('Falta configurar una clave de OpenAI en el servidor.', 401);
        }
        $this->validateInput($payload);
        foreach (['no_contact', 'handoff'] as $flag) {
            if (array_key_exists($flag, $payload) && !is_bool($payload[$flag])) {
                throw new RuntimeException('El estado de la conversación no es válido.', 422);
            }
        }
        if (isset($payload['format']) && (!is_string($payload['format']) || !in_array($payload['format'], ['photo', 'carousel', 'reel', 'story'], true))) {
            throw new RuntimeException('El formato solicitado no es válido.', 422);
        }
        $prompt = @file_get_contents(__DIR__ . '/prompts/instagram-agent.txt');
        if (!is_string($prompt) || trim($prompt) === '' || strlen($prompt) > self::MAX_INPUT_BYTES || !preg_match('//u', $prompt)) {
            throw new RuntimeException('No está disponible la configuración editorial del agente.', 503);
        }
        try {
            $input = json_encode(['kind' => $kind, 'mode' => 'SIMULATION', 'data' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Los datos de la simulación no son válidos.', 422);
        }
        if (strlen($input) > self::MAX_INPUT_BYTES) {
            throw new RuntimeException('El contexto de la simulación supera el tamaño permitido.', 422);
        }
        $schema = $this->schema($kind);
        $request = [
            'model' => $model,
            'instructions' => $prompt,
            'input' => [['role' => 'user', 'content' => $input]],
            'reasoning' => ['effort' => 'low'],
            'max_output_tokens' => 4500,
            'store' => false,
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'perigallo_instagram_' . $kind,
                'strict' => true,
                'schema' => $schema,
            ]],
        ];
        if ($this->transport !== null) {
            try {
                $response = ($this->transport)($request);
            } catch (Throwable $error) {
                // Los transportes no pueden filtrar mensajes, cabeceras ni claves.
                $code = $error instanceof RuntimeException && $error->getCode() === 504 ? 504 : 502;
                throw new RuntimeException('No se pudo confirmar el resultado de la generación. No se ha reintentado automáticamente.', $code);
            }
        } else {
            $response = $this->request($request, $apiKey);
        }
        if (!is_array($response) || !is_int($response['status'] ?? null) || !is_string($response['body'] ?? null)) {
            throw new RuntimeException('El servicio devolvió una respuesta de transporte no válida.', 502);
        }
        if (strlen($response['body']) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('La respuesta del servicio supera el tamaño permitido.', 502);
        }
        $this->assertHttpSuccess($response['status']);
        return $this->parseResponse($response['body'], $kind, $schema, $payload, $model);
    }

    private function model(): string
    {
        $value = getenv('INSTAGRAM_AGENT_MODEL');
        $model = $value === false || trim($value) === '' ? self::DEFAULT_MODEL : trim($value);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,127}$/D', $model)) {
            throw new RuntimeException('La configuración del modelo de OpenAI no es válida.', 401);
        }
        // Los modelos desconocidos se consultan tal cual: nunca hay fallback de pago.
        return $model;
    }

    private function apiKey(): ?string
    {
        $key = getenv('OPENAI_API_KEY');
        if ($key !== false && trim($key) !== '') {
            return $this->validateKey(trim($key));
        }
        $path = getenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE');
        if ($path === false || trim($path) === '') {
            return null;
        }
        $path = trim($path);
        if (!$this->privatePath($path)) {
            throw new RuntimeException('La ubicación privada de la clave de OpenAI no es válida.', 401);
        }
        if (!file_exists($path)) {
            return null;
        }
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > self::MAX_KEY_BYTES || !is_readable($path)) {
            throw new RuntimeException('No se puede leer la configuración privada de OpenAI.', 401);
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se puede leer la configuración privada de OpenAI.', 401);
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev'] || !$this->privatePath($path)) {
                throw new RuntimeException('No se puede verificar la configuración privada de OpenAI.', 401);
            }
            $value = stream_get_contents($handle, self::MAX_KEY_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($value) || strlen($value) > self::MAX_KEY_BYTES) {
            throw new RuntimeException('No se puede leer la configuración privada de OpenAI.', 401);
        }
        return trim($value) === '' ? null : $this->validateKey(trim($value));
    }

    private function validateKey(string $key): string
    {
        if (strlen($key) > self::MAX_KEY_BYTES || preg_match('/[^\x21-\x7E]/', $key)) {
            throw new RuntimeException('La configuración de la clave de OpenAI no es válida.', 401);
        }
        return $key;
    }

    /** No acepta rutas públicas, traversal ni enlaces en ningún componente. */
    private function privatePath(string $path): bool
    {
        clearstatcache(true);
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            return false;
        }
        $parts = explode('/', $path);
        $current = '';
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..') {
                return false;
            }
            if ($part === '') {
                continue;
            }
            $current .= '/' . $part;
            if (is_link($current)) {
                return false;
            }
        }
        $roots = [dirname(__DIR__, 2), (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')];
        foreach ($roots as $root) {
            if ($root === '') {
                continue;
            }
            $root = rtrim(realpath($root) ?: $root, '/');
            if ($current === $root || str_starts_with($current, $root . '/')) {
                return false;
            }
        }
        return $current !== '' && $current !== '/';
    }

    private function validateInput(array $payload): void
    {
        $nodes = 0;
        $walk = function ($value, int $depth) use (&$walk, &$nodes): void {
            if (++$nodes > 2000 || $depth > 12) {
                throw new RuntimeException('El contexto de la simulación es demasiado complejo.', 422);
            }
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    if (is_string($key) && (strlen($key) > 128 || !preg_match('//u', $key))) {
                        throw new RuntimeException('El contexto contiene campos no válidos.', 422);
                    }
                    $walk($item, $depth + 1);
                }
            } elseif (is_string($value)) {
                if (strlen($value) > self::MAX_INPUT_BYTES || !preg_match('//u', $value)) {
                    throw new RuntimeException('El contexto contiene texto no válido o demasiado extenso.', 422);
                }
            } elseif (!is_null($value) && !is_bool($value) && !is_int($value) && !(is_float($value) && is_finite($value))) {
                throw new RuntimeException('El contexto contiene valores no válidos.', 422);
            }
        };
        $walk($payload, 0);
    }

    /** @return array{status:int, body:string} */
    private function request(array $request, string $key): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('El servidor necesita la extensión cURL para generar contenido.', 503);
        }
        $curl = curl_init(self::ENDPOINT);
        if ($curl === false) {
            throw new RuntimeException('No se pudo iniciar la conexión con el servicio.', 503);
        }
        $body = '';
        $tooLarge = false;
        try {
            $options = [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR] = 'https';
            } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            if (!curl_setopt_array($curl, $options)) {
                throw new RuntimeException('No se pudo configurar la conexión con el servicio.', 503);
            }
            $result = curl_exec($curl);
            $errno = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($curl);
        }
        if ($tooLarge) {
            throw new RuntimeException('La respuesta del servicio supera el tamaño permitido.', 502);
        }
        if ($result === false || $errno !== 0) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new RuntimeException('El servicio ha agotado el tiempo de espera; el resultado de la generación es incierto. No se ha reintentado automáticamente.', 504);
            }
            throw new RuntimeException('No se pudo confirmar el resultado de la generación. No se ha reintentado automáticamente.', 502);
        }
        return ['status' => $status, 'body' => $body];
    }

    private function assertHttpSuccess(int $status): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('OpenAI no ha autorizado la petición. Revisa la clave y los permisos del proyecto.', 401);
        }
        if ($status === 429) {
            throw new RuntimeException('OpenAI ha alcanzado el límite de solicitudes o de cuota. Revisa el uso y la facturación antes de volver a intentarlo.', 429);
        }
        if ($status === 408 || $status === 504) {
            throw new RuntimeException('El servicio ha agotado el tiempo de espera; el resultado de la generación es incierto. No se ha reintentado automáticamente.', 504);
        }
        if ($status >= 500) {
            throw new RuntimeException('El servicio de generación no está disponible en este momento. No se ha reintentado automáticamente.', 503);
        }
        if ($status === 400 || $status === 404 || $status === 422) {
            throw new RuntimeException('OpenAI ha rechazado la configuración de la petición o del modelo. Revisa el modelo configurado.', 502);
        }
        throw new RuntimeException('El servicio de generación devolvió una respuesta HTTP no válida.', 502);
    }

    private function parseResponse(string $body, string $kind, array $schema, array $payload, string $model): array
    {
        try {
            $response = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('El servicio devolvió una respuesta JSON no válida.', 502);
        }
        if (!is_array($response) || ($response['status'] ?? null) !== 'completed' || ($response['error'] ?? null) !== null) {
            throw new RuntimeException('La generación no se completó. El borrador no se ha aceptado ni se ha reintentado automáticamente.', 502);
        }
        $items = $response['output'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) > 64) {
            throw new RuntimeException('El servicio no devolvió una salida válida.', 502);
        }
        $texts = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('El servicio no devolvió una salida válida.', 502);
            }
            if (($item['type'] ?? null) === 'reasoning') {
                continue;
            }
            if (($item['type'] ?? null) !== 'message' || ($item['role'] ?? null) !== 'assistant'
                || (isset($item['status']) && $item['status'] !== 'completed')
                || !is_array($item['content'] ?? null) || !array_is_list($item['content'])) {
                throw new RuntimeException('El servicio devolvió un tipo de salida no admitido.', 502);
            }
            foreach ($item['content'] as $content) {
                if (is_array($content) && ($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('El modelo no pudo atender esta propuesta. Revisa el contenido antes de volver a solicitarla.', 422);
                }
                if (!is_array($content) || ($content['type'] ?? null) !== 'output_text' || !is_string($content['text'] ?? null)) {
                    throw new RuntimeException('El servicio no devolvió texto estructurado válido.', 502);
                }
                $texts[] = $content['text'];
            }
        }
        if ($texts === []) {
            throw new RuntimeException('El servicio no devolvió ningún borrador.', 502);
        }
        try {
            $json = implode('', $texts);
            $structured = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('El borrador generado no contiene JSON válido.', 502);
        }
        // Conservar object/array durante la validación impide aceptar {} como [].
        $this->validateSchema($structured, $schema);
        $output = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if ($kind === 'content') {
            if (isset($payload['format']) && $output['format'] !== $payload['format']) {
                throw new RuntimeException('El borrador generado no respeta el formato solicitado.', 502);
            }
            $slides = count($output['slides']);
            if (($output['format'] === 'photo' && $slides !== 0)
                || ($output['format'] === 'carousel' && $slides < 2)
                || (in_array($output['format'], ['reel', 'story'], true) && $slides < 1)) {
                throw new RuntimeException('La estructura visual del borrador no corresponde a su formato.', 502);
            }
        } else {
            if (($output['decision'] === 'REPLY' && trim($output['reply']) === '')
                || (in_array($output['decision'], ['NO_CONTACT', 'HUMAN_TAKEOVER'], true) && $output['reply'] !== '')
                || (($payload['no_contact'] ?? false) === true && $output['decision'] !== 'NO_CONTACT')
                || (($payload['handoff'] ?? false) === true && ($payload['no_contact'] ?? false) !== true && $output['decision'] !== 'HUMAN_TAKEOVER')) {
                throw new RuntimeException('La respuesta propuesta no respeta el estado de la conversación.', 502);
            }
        }
        $id = $response['id'] ?? null;
        if (!is_string($id) || !preg_match('/^resp_[A-Za-z0-9_\-]{1,190}$/D', $id)) {
            throw new RuntimeException('El servicio no devolvió un identificador de generación válido.', 502);
        }
        $responseModel = $response['model'] ?? $model;
        if (!is_string($responseModel) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,127}$/D', $responseModel)) {
            $responseModel = $model;
        }
        $output['_generation'] = [
            'response_id' => $id,
            'model' => $responseModel,
            'usage' => $this->safeUsage($response['usage'] ?? null),
        ];
        return $output;
    }

    private function safeUsage($usage): ?array
    {
        if (!is_array($usage)) {
            return null;
        }
        $safe = [];
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
            if (isset($usage[$key]) && is_int($usage[$key]) && $usage[$key] >= 0 && $usage[$key] <= 1000000000) {
                $safe[$key] = $usage[$key];
            }
        }
        foreach (['input_tokens_details' => 'cached_tokens', 'output_tokens_details' => 'reasoning_tokens'] as $group => $key) {
            $value = is_array($usage[$group] ?? null) ? ($usage[$group][$key] ?? null) : null;
            if (is_int($value) && $value >= 0 && $value <= 1000000000) {
                $safe[$group] = [$key => $value];
            }
        }
        return $safe === [] ? null : $safe;
    }

    private function schema(string $kind): array
    {
        $string = static fn (int $max, int $min = 1): array => ['type' => 'string', 'minLength' => $min, 'maxLength' => $max];
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        $pending = ['type' => 'array', 'maxItems' => 20, 'items' => $string(500)];
        if ($kind === 'conversation') {
            return $object([
                'classification' => $string(80),
                'reply' => $string(2000, 0),
                'decision' => ['type' => 'string', 'enum' => ['REPLY', 'ESCALATE', 'NO_CONTACT', 'HUMAN_TAKEOVER']],
                'next_action' => $string(1000),
                'lead_status' => $string(80),
                'pending_facts' => $pending,
            ]);
        }
        return $object([
            'title' => $string(160),
            'format' => ['type' => 'string', 'enum' => ['photo', 'carousel', 'reel', 'story']],
            'objective' => $string(500),
            'caption' => $string(2200),
            'visual_brief' => $string(4000),
            'slides' => ['type' => 'array', 'maxItems' => 10, 'items' => $object([
                'title' => $string(160),
                'body' => $string(1500, 0),
                'visual_brief' => $string(2500),
            ])],
            'alt_text' => $string(1000),
            'cta' => $string(300),
            'pending_facts' => $pending,
        ]);
    }

    /** Valida de nuevo todo el esquema; no confía en strict del proveedor. */
    private function validateSchema($value, array $schema): void
    {
        $valid = true;
        switch ($schema['type']) {
            case 'object':
                $properties = $value instanceof \stdClass ? get_object_vars($value) : [];
                $valid = $value instanceof \stdClass
                    && count($properties) === count($schema['properties'])
                    && array_diff(array_keys($properties), array_keys($schema['properties'])) === [];
                if ($valid) {
                    foreach ($schema['properties'] as $key => $child) {
                        if (!array_key_exists($key, $properties)) {
                            $valid = false;
                            break;
                        }
                        $this->validateSchema($properties[$key], $child);
                    }
                }
                break;
            case 'array':
                $valid = is_array($value) && array_is_list($value) && count($value) <= $schema['maxItems'];
                if ($valid) {
                    foreach ($value as $child) {
                        $this->validateSchema($child, $schema['items']);
                    }
                }
                break;
            case 'string':
                $valid = is_string($value) && preg_match('//u', $value) === 1
                    && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value);
                if ($valid && isset($schema['enum'])) {
                    $valid = in_array($value, $schema['enum'], true);
                }
                if ($valid && isset($schema['maxLength'])) {
                    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value);
                    $valid = $length <= $schema['maxLength'] && $length >= $schema['minLength']
                        && ($schema['minLength'] === 0 || trim($value) !== '');
                }
                break;
            default:
                $valid = false;
        }
        if (!$valid) {
            throw new RuntimeException('El borrador generado no cumple el esquema y los límites de la simulación.', 502);
        }
    }
}
