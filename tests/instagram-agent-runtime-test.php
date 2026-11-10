<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/src/bootstrap.php';

use Perigallo\Ticketing\InstagramAgentRuntime;

$checks = 0;

function iga_runtime_assert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function iga_runtime_reject(string $path, string $message): void
{
    putenv('INSTAGRAM_AGENT_STORAGE_DIR=' . $path);
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE');
    try {
        InstagramAgentRuntime::directory();
    } catch (RuntimeException $error) {
        iga_runtime_assert($error->getCode() === 503, $message . ': código inesperado.');
        iga_runtime_assert(getenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE') === false, $message . ': se configuró una ruta de clave antes de rechazar el almacén.');
        return;
    }
    throw new RuntimeException($message . ': se aceptó el directorio.');
}

/** Solo se utiliza sobre las dos raíces aleatorias creadas por esta prueba. */
function iga_runtime_cleanup(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            iga_runtime_cleanup($path . '/' . $name);
        }
    }
    rmdir($path);
}

$originalEnvironment = [];
foreach (['INSTAGRAM_AGENT_STORAGE_DIR', 'INSTAGRAM_AGENT_OPENAI_KEY_FILE'] as $name) {
    $originalEnvironment[$name] = getenv($name);
}
$originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
$repo = realpath(dirname(__DIR__));
if ($repo === false) {
    throw new RuntimeException('No se encontró el checkout de la prueba.');
}
$suffix = bin2hex(random_bytes(8));
$fixture = sys_get_temp_dir() . '/perigallo-instagram-runtime-' . $suffix;
$siblingProbe = dirname($repo) . '/instagram-agent-public-probe-' . $suffix;

try {
    iga_runtime_assert(mkdir($fixture, 0700), 'No se pudo crear la carpeta temporal de pruebas.');
    $documentRoot = $fixture . '/public';
    iga_runtime_assert(mkdir($documentRoot, 0700), 'No se pudo preparar el docroot de pruebas.');

    // Un checkout puede vivir dentro de un docroot más amplio. El hermano del
    // checkout sigue siendo público y debe rechazarse antes de crear nada.
    $_SERVER['DOCUMENT_ROOT'] = dirname($repo);
    iga_runtime_reject($siblingProbe, 'Almacén público fuera del checkout');
    clearstatcache(true);
    iga_runtime_assert(!file_exists($siblingProbe), 'Se creó el almacén antes de validar el docroot padre.');

    // La forma textual no coincide con el prefijo del docroot hasta normalizarla.
    // Rechazar solo tras realpath sería tarde: ya se habría creado la carpeta.
    $_SERVER['DOCUMENT_ROOT'] = $documentRoot;
    $normalizedPublic = $fixture . '/./public//new-parent//agent';
    iga_runtime_reject($normalizedPublic, 'Ruta pública con puntos y separadores repetidos');
    clearstatcache(true);
    iga_runtime_assert(!file_exists($documentRoot . '/new-parent'), 'La normalización permitió crear una carpeta pública antes de rechazarla.');

    $repoProbe = dirname($repo) . '/./' . basename($repo) . '//runtime-public-probe-' . $suffix;
    iga_runtime_reject($repoProbe, 'Ruta normalizada bajo el checkout');
    clearstatcache(true);
    iga_runtime_assert(!file_exists($repo . '/runtime-public-probe-' . $suffix), 'Se creó una carpeta pública dentro del checkout.');

    $realPrivate = $fixture . '/real-private';
    iga_runtime_assert(mkdir($realPrivate, 0700), 'No se creó la carpeta privada de destino.');
    $link = $fixture . '/private-link';
    iga_runtime_assert(symlink($realPrivate, $link), 'No se pudo crear el enlace de prueba.');
    iga_runtime_reject($link . '/nested/agent', 'Enlace simbólico en un antecesor');
    clearstatcache(true);
    iga_runtime_assert(!file_exists($realPrivate . '/nested'), 'Se escribió a través del antecesor enlazado.');

    iga_runtime_reject('relative/agent', 'Ruta relativa');
    iga_runtime_reject($fixture . '/private/../public/agent', 'Traversal en la ruta');
    iga_runtime_reject('/', 'Raíz del sistema');
    iga_runtime_reject('/.', 'Raíz del sistema tras normalizar un punto');
    iga_runtime_reject('/./', 'Raíz del sistema tras normalizar punto y separador');

    $expectedPrivate = $fixture . '/private/nested/agent';
    putenv('INSTAGRAM_AGENT_STORAGE_DIR=' . $fixture . '//./private//nested/agent/');
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE');
    $resolved = InstagramAgentRuntime::directory();
    iga_runtime_assert($resolved === $expectedPrivate && is_dir($resolved), 'No se creó y normalizó el almacén privado válido.');
    clearstatcache(true);
    iga_runtime_assert((fileperms($resolved) & 0777) === 0700, 'El almacén privado no tiene permisos 0700.');
    iga_runtime_assert(getenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE') === $expectedPrivate . '/openai.key', 'No se configuró la ruta privada predeterminada de la clave.');
    iga_runtime_assert(!file_exists($expectedPrivate . '/openai.key'), 'La preparación del directorio creó una clave inesperadamente.');

    $managedKey = $fixture . '/managed/openai.key';
    putenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE=' . $managedKey);
    iga_runtime_assert(InstagramAgentRuntime::directory() === $expectedPrivate, 'Una ruta de clave administrada alteró el almacén.');
    iga_runtime_assert(getenv('INSTAGRAM_AGENT_OPENAI_KEY_FILE') === $managedKey, 'Se sobrescribió la ubicación de clave administrada por el servidor.');
    iga_runtime_assert(!file_exists($managedKey), 'Se creó un archivo en la ubicación administrada.');

    echo 'InstagramAgentRuntime: ' . $checks . " comprobaciones correctas; ninguna llamada de red.\n";
} finally {
    foreach ($originalEnvironment as $name => $value) {
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
    iga_runtime_cleanup($fixture);
    iga_runtime_cleanup($siblingProbe);
    iga_runtime_cleanup($repo . '/runtime-public-probe-' . $suffix);
}
