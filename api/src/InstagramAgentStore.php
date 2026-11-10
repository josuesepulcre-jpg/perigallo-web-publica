<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use RuntimeException;
use Throwable;

/** Private, single-host state storage. Never falls back to an empty state on corruption. */
final class InstagramAgentStore
{
    private string $directory;

    public function __construct(string $directory)
    {
        if ($directory === '' || $directory[0] !== '/' || str_contains($directory, "\0") || rtrim($directory, '/') === '') {
            throw new RuntimeException('El almacén del agente requiere una carpeta privada absoluta.', 500);
        }
        $directory = rtrim($directory, '/');
        if (is_link($directory)) {
            throw new RuntimeException('El almacén del agente no puede ser un enlace simbólico.', 500);
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el almacén privado del agente.', 500);
        }
        $resolved = realpath($directory);
        if ($resolved === false || !is_writable($resolved) || !chmod($resolved, 0700)) {
            throw new RuntimeException('El almacén privado del agente no está disponible.', 500);
        }
        $this->directory = $resolved;
    }

    public static function initialState(): array
    {
        return [
            'schema_version' => 1,
            'mode' => 'SIMULATION',
            'account' => 'somosperigallo',
            'paused' => false,
            'settings' => [
                'brand_context' => 'Perigallo: bodas, celebraciones, catering y experiencias gastronómicas. Finca La Llaguna, Crevillent, Alicante. Escucha, creación y compromiso. Confirmar precios, disponibilidad y condiciones con el propietario.',
                'schedule_enabled' => false,
                'schedule_time' => '09:00',
                'daily_generation_limit' => 10,
                'timezone' => 'Europe/Madrid',
            ],
            'drafts' => [],
            'conversations' => [],
            'tasks' => [],
            'runs' => [],
            'audit' => [],
            'schedule' => ['last_run' => null, 'last_run_day' => null, 'last_run_id' => null, 'last_heartbeat' => null],
            'updated_at' => gmdate('c'),
        ];
    }

    public function read(): array
    {
        $lock = $this->openLock();
        try {
            if (!flock($lock, LOCK_SH)) {
                throw new RuntimeException('No se pudo leer el estado del agente.', 503);
            }
            return $this->readUnlocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The callback receives the state by reference; an exception leaves the stored state unchanged. */
    public function transaction(callable $callback): mixed
    {
        $lock = $this->openLock();
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear el estado del agente.', 503);
            }
            $state = $this->readUnlocked();
            $result = $callback($state);
            $state['updated_at'] = gmdate('c');
            $this->validate($state);
            $this->writeAtomic($state);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function openLock()
    {
        $path = $this->directory . '/state.lock';
        if (is_link($path)) {
            throw new RuntimeException('Archivo de bloqueo inválido.', 500);
        }
        $lock = fopen($path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('No se pudo abrir el almacén del agente.', 500);
        }
        if (!chmod($path, 0600)) {
            fclose($lock);
            throw new RuntimeException('No se pudieron proteger los datos del agente.', 500);
        }
        return $lock;
    }

    private function readUnlocked(): array
    {
        $path = $this->directory . '/state.json';
        if (is_link($path)) {
            throw new RuntimeException('Archivo de estado inválido.', 500);
        }
        if (!file_exists($path)) {
            return self::initialState();
        }
        if (!is_file($path) || filesize($path) > 64 * 1024 * 1024) {
            throw new RuntimeException('El estado del agente necesita revisión; no se ha sobrescrito.', 500);
        }
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('No se pudo leer el estado del agente; no se ha sobrescrito.', 500);
        }
        try {
            $state = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('El estado del agente está dañado; no se ha sobrescrito.', 500, $error);
        }
        if (!is_array($state)) {
            throw new RuntimeException('El estado del agente está dañado; no se ha sobrescrito.', 500);
        }
        $this->validate($state);
        return $state;
    }

    private function validate(array $state): void
    {
        if (($state['schema_version'] ?? null) !== 1 || ($state['mode'] ?? null) !== 'SIMULATION'
            || ($state['account'] ?? null) !== 'somosperigallo' || !is_bool($state['paused'] ?? null)) {
            throw new RuntimeException('Estado o modo del agente inválido; requiere revisión.', 500);
        }
        foreach (['drafts', 'conversations', 'tasks', 'runs', 'audit'] as $field) {
            if (!isset($state[$field]) || !is_array($state[$field]) || !array_is_list($state[$field])) {
                throw new RuntimeException('Estructura de estado inválida; requiere revisión.', 500);
            }
        }
        $settings = $state['settings'] ?? null;
        if (!is_array($settings) || !is_string($settings['brand_context'] ?? null)
            || !is_bool($settings['schedule_enabled'] ?? null) || !is_int($settings['daily_generation_limit'] ?? null)
            || $settings['daily_generation_limit'] < 0 || $settings['daily_generation_limit'] > 50
            || ($settings['timezone'] ?? null) !== 'Europe/Madrid'
            || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', (string) ($settings['schedule_time'] ?? ''))
            || !is_array($state['schedule'] ?? null)) {
            throw new RuntimeException('Configuración del agente inválida; requiere revisión.', 500);
        }
    }

    private function writeAtomic(array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 64 * 1024 * 1024) {
            throw new RuntimeException('El almacén necesita archivado antes de añadir más registros.', 507);
        }
        $temporary = tempnam($this->directory, '.state-');
        if ($temporary === false) {
            throw new RuntimeException('No se pudo preparar el guardado del agente.', 500);
        }
        $handle = null;
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('No se pudo proteger el estado temporal.', 500);
            }
            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException('No se pudo abrir el estado temporal.', 500);
            }
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Guardado incompleto; se conserva el estado anterior.', 500);
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('No se pudo confirmar el guardado del agente.', 500);
            }
            fclose($handle);
            $handle = null;
            if (!rename($temporary, $this->directory . '/state.json')) {
                throw new RuntimeException('No se pudo activar el estado guardado.', 500);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
