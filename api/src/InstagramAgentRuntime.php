<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use RuntimeException;

/** Runtime boundary for the private, simulation-only Instagram workspace. */
final class InstagramAgentRuntime
{
    public static function directory(): string
    {
        $directory = rtrim((string) env_value('INSTAGRAM_AGENT_STORAGE_DIR', '/var/www/vhosts/perigallo.com/private/instagram-agent'), '/');
        if ($directory === '' || $directory[0] !== '/' || str_contains($directory, "\0") || in_array('..', explode('/', $directory), true)) {
            throw new RuntimeException('La carpeta privada del agente no está configurada correctamente.', 503);
        }
        $directory = '/' . implode('/', array_filter(explode('/', $directory), static fn (string $part): bool => $part !== '' && $part !== '.'));
        if ($directory === '/') {
            throw new RuntimeException('La carpeta privada del agente no está configurada correctamente.', 503);
        }
        $roots = array_filter([
            realpath(dirname(__DIR__, 2)),
            isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false,
        ]);
        $isPublic = static function (string $candidate) use ($roots): bool {
            foreach ($roots as $root) {
                if ($candidate === $root || str_starts_with($candidate, rtrim($root, '/') . '/')) {
                    return true;
                }
            }
            return false;
        };
        $cursor = $directory;
        while ($cursor !== '/' && $cursor !== '.') {
            if (is_link($cursor)) {
                throw new RuntimeException('La carpeta del agente no puede contener enlaces simbólicos.', 503);
            }
            $cursor = dirname($cursor);
        }
        if ($isPublic($directory)) {
            throw new RuntimeException('Los datos del agente deben estar fuera del directorio público.', 503);
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('El servidor no puede crear la carpeta privada del agente. Revisa sus permisos.', 503);
        }
        $resolved = realpath($directory);
        if ($resolved === false || $isPublic($resolved) || !is_writable($resolved)) {
            throw new RuntimeException('La carpeta privada del agente no está disponible.', 503);
        }
        @chmod($resolved, 0700);
        if (!env_value('INSTAGRAM_AGENT_OPENAI_KEY_FILE')) {
            putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $resolved . '/openai.key');
        }
        return $resolved;
    }

    public static function service(?string $directory = null): InstagramAgentService
    {
        $directory ??= self::directory();
        $generator = new InstagramAgentGenerator();
        return new InstagramAgentService(new InstagramAgentStore($directory), [$generator, 'generate']);
    }

    public static function saveKey(string $key, string $directory): void
    {
        if (env_value('OPENAI_API_KEY')) {
            throw new RuntimeException('El servidor ya utiliza una clave de su entorno. Su cambio corresponde al administrador del servidor.', 409);
        }
        $path = (string) env_value('INSTAGRAM_AGENT_OPENAI_KEY_FILE', $directory . '/openai.key');
        if ($path !== $directory . '/openai.key') {
            throw new RuntimeException('La clave se gestiona en una ubicación del servidor. Actualízala mediante su configuración privada.', 409);
        }
        $key = trim($key);
        if (strlen($key) < 20 || strlen($key) > 1024 || !preg_match('/^sk-[A-Za-z0-9_-]+$/D', $key)) {
            throw new \InvalidArgumentException('La clave no tiene un formato válido. Utiliza el formulario seguro, sin compartirla en el chat.');
        }
        if (is_link($path) || is_link($directory)) {
            throw new RuntimeException('La ubicación privada de la clave no es válida.', 503);
        }
        $temporary = @tempnam($directory, '.key-');
        if ($temporary === false) {
            throw new RuntimeException('No se ha podido guardar la configuración privada.', 503);
        }
        try {
            @chmod($temporary, 0600);
            $payload = $key . "\n";
            if (@file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload) || !@rename($temporary, $path)) {
                throw new RuntimeException('No se ha podido guardar la configuración privada.', 503);
            }
            @chmod($path, 0600);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public static function capabilities(array $snapshot, array $session): array
    {
        $ai = (new InstagramAgentGenerator())->configuration();
        $ai['source'] = env_value('OPENAI_API_KEY') ? 'server_env' : ($ai['configured'] ? 'private_file' : 'missing');
        $schedule = $snapshot['state']['schedule'] ?? [];
        $heartbeat = $schedule['last_heartbeat'] ?? $schedule['last_heartbeat_at'] ?? null;
        $timestamp = is_string($heartbeat) ? strtotime($heartbeat) : false;
        return [
            'mode' => 'SIMULATION',
            'instagram_write' => false,
            'ai' => $ai,
            'scheduler' => [
                'heartbeat_at' => $heartbeat,
                'active' => $timestamp !== false && $timestamp > time() - 600,
                'installation_required' => $timestamp === false || $timestamp < time() - 600,
            ],
            'current_user' => ['name' => $session['operator'] ?? 'admin', 'is_owner' => (bool) ($session['is_owner'] ?? false)],
        ];
    }
}
