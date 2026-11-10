<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/api/src/bootstrap.php';

use Perigallo\Ticketing\AdminAuth;
use Perigallo\Ticketing\InstagramAgentRuntime;

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
AdminAuth::require();
$session = AdminAuth::sessionPayload();
$operator = AdminAuth::operatorName();
$csrf = $session['csrf'];
$operation = (string) ($_GET['op'] ?? 'state');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$reads = ['state', 'report', 'asset', 'export'];
$writes = ['generate_content', 'save_draft', 'review_draft', 'simulate_publish', 'simulate_conversation', 'save_task', 'pause', 'resume', 'save_settings', 'run_cycle', 'configure_key', 'upload'];
if (!in_array($operation, array_merge($reads, $writes), true)) {
    json_response(['ok' => false, 'error' => 'Esta operación no está disponible. El agente solo trabaja en simulación.'], 403);
    exit;
}
if (($method !== 'GET' && in_array($operation, $reads, true)) || ($method !== 'POST' && in_array($operation, $writes, true))) {
    header('Allow: ' . (in_array($operation, $reads, true) ? 'GET' : 'POST'));
    json_response(['ok' => false, 'error' => 'Método no permitido.'], 405);
    exit;
}
if ($method === 'POST') {
    AdminAuth::requireCsrf();
    if (in_array($operation, ['configure_key', 'save_settings'], true)) {
        AdminAuth::requireOwner();
    }
}
// The session lock must never remain held while waiting for the model.
session_write_close();

try {
    $directory = InstagramAgentRuntime::directory();
    if ($operation === 'asset') {
        $id = (string) ($_GET['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $id)) {
            throw new InvalidArgumentException('Recurso no válido.');
        }
        $path = $directory . '/assets/' . $id;
        if (!is_file($path) || is_link($path) || is_link(dirname($path))) {
            json_response(['ok' => false, 'error' => 'Recurso no encontrado.'], 404);
            exit;
        }
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        header('Content-Type: ' . $types[pathinfo($id, PATHINFO_EXTENSION)]);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . $id . '"');
        readfile($path);
        exit;
    }
    if ($operation === 'upload') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 9 * 1024 * 1024) {
            throw new RuntimeException('La imagen debe ocupar menos de 8 MB.', 413);
        }
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('No se ha recibido una imagen válida.');
        }
        if ((int) $file['size'] > 8 * 1024 * 1024) {
            throw new RuntimeException('La imagen debe ocupar menos de 8 MB.', 413);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $dimensions = @getimagesize($file['tmp_name']);
        if (!isset($extensions[$mime]) || $dimensions === false || $dimensions[0] > 10000 || $dimensions[1] > 10000 || $dimensions[0] * $dimensions[1] > 50000000) {
            throw new InvalidArgumentException('Utiliza una imagen JPEG, PNG o WebP de hasta 10.000 píxeles por lado.');
        }
        $assetDirectory = $directory . '/assets';
        if (is_link($assetDirectory) || (!is_dir($assetDirectory) && !@mkdir($assetDirectory, 0700))) {
            throw new RuntimeException('La carpeta privada de imágenes no está disponible.', 503);
        }
        $id = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'], $assetDirectory . '/' . $id)) {
            throw new RuntimeException('La imagen no se ha podido guardar.', 503);
        }
        @chmod($assetDirectory . '/' . $id, 0600);
        json_response(['ok' => true, 'asset_url' => '/admin/instagram/agent/api.php?op=asset&id=' . $id, 'note' => 'Recurso privado para simulación. Confirma sus derechos al guardar la pieza.']);
        exit;
    }
    $service = InstagramAgentRuntime::service($directory);
    if ($operation === 'state') {
        $snapshot = $service->snapshot();
        json_response(['ok' => true, 'csrf' => $csrf, 'snapshot' => $snapshot, 'capabilities' => InstagramAgentRuntime::capabilities($snapshot, $session)]);
        exit;
    }
    if ($operation === 'report') {
        json_response(['ok' => true, 'report' => $service->snapshot()['report']]);
        exit;
    }
    if ($operation === 'export') {
        $snapshot = $service->snapshot();
        $id = (string) ($_GET['id'] ?? '');
        $draft = null;
        foreach ($snapshot['state']['drafts'] as $candidate) {
            if ($candidate['id'] === $id) {
                $draft = $candidate;
                break;
            }
        }
        if ($draft === null) {
            throw new RuntimeException('La pieza no existe.', 404);
        }
        header('Content-Disposition: attachment; filename="perigallo-simulacion-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id) . '.json"');
        json_response(['environment' => 'SIMULATION', 'account' => 'somosperigallo', 'draft' => $draft, 'integration_note' => 'Paquete de revisión. No importa automáticamente los borradores del estudio anterior ni publica en Instagram.']);
        exit;
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 150000) {
        throw new RuntimeException('La petición es demasiado grande.', 413);
    }
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        throw new RuntimeException('Se necesita una petición JSON.', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 150001);
    if ($raw === false || strlen($raw) > 150000) {
        throw new RuntimeException('La petición es demasiado grande o no se ha podido leer.', 413);
    }
    try {
        $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new InvalidArgumentException('La petición JSON no es válida.');
    }
    if (!is_array($input)) {
        throw new InvalidArgumentException('La petición JSON debe ser un objeto.');
    }
    if ($operation === 'configure_key') {
        if (!is_string($input['key'] ?? null)) {
            throw new InvalidArgumentException('No se ha recibido una clave válida.');
        }
        InstagramAgentRuntime::saveKey($input['key'], $directory);
        unset($input['key']);
        $snapshot = $service->snapshot();
        json_response(['ok' => true, 'message' => 'Clave guardada en la configuración privada. Su acceso se comprobará en la primera generación.', 'snapshot' => $snapshot, 'capabilities' => InstagramAgentRuntime::capabilities($snapshot, $session)]);
        exit;
    }
    $result = $service->command($operation, $input, $operator);
    json_response($result);
} catch (InvalidArgumentException $error) {
    json_response(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (RuntimeException $error) {
    $status = $error->getCode();
    if (!in_array($status, [400,401,403,404,409,413,415,422,423,429,500,502,503,504], true)) {
        $status = 409;
    }
    json_response(['ok' => false, 'error' => $error->getMessage()], $status);
} catch (Throwable $error) {
    // Never expose provider bodies, filesystem paths or credentials to the browser.
    json_response(['ok' => false, 'error' => 'No se ha podido completar la operación. Los borradores guardados se conservan.'], 500);
}
