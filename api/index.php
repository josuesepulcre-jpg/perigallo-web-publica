<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Perigallo\Ticketing\AdminAuth;
use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\Mailer;
use Perigallo\Ticketing\Redsys;
use Perigallo\Ticketing\Ticketing;

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(self), geolocation=(), microphone=()');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/api/', PHP_URL_PATH) ?: '/api/';
$path = preg_replace('#^/api#', '', $path) ?: '/';
$path = rtrim($path, '/') ?: '/';

try {
    $ticketing = new Ticketing(Database::pdo(), new Redsys(), new Mailer());

    if ($method === 'GET' && $path === '/events') {
        json_response(['ok' => true, 'events' => $ticketing->listEvents()]);
        return;
    }

    if ($method === 'GET' && preg_match('#^/events/([a-z0-9-]+)$#', $path, $m)) {
        $event = $ticketing->getEventBySlug($m[1]);
        if (!$event) {
            json_response(['ok' => false, 'error' => 'Evento no encontrado.'], 404);
            return;
        }
        json_response(['ok' => true, 'event' => $event]);
        return;
    }

    if ($method === 'POST' && $path === '/orders') {
        $result = $ticketing->createOrder(read_json_body());
        json_response(['ok' => true] + $result, 201);
        return;
    }

    if ($method === 'GET' && preg_match('#^/orders/([A-Za-z0-9_-]+)$#', $path, $m)) {
        $order = $ticketing->getOrderByToken($m[1]);
        if (!$order) {
            json_response(['ok' => false, 'error' => 'Pedido no encontrado.'], 404);
            return;
        }
        json_response(['ok' => true, 'order' => $order]);
        return;
    }

    if ($method === 'POST' && preg_match('#^/orders/([A-Za-z0-9_-]+)/resend-email$#', $path, $m)) {
        json_response(['ok' => true] + $ticketing->resendOrderEmail($m[1]));
        return;
    }

    if ($method === 'POST' && preg_match('#^/orders/([A-Za-z0-9_-]+)/resend-whatsapp$#', $path, $m)) {
        json_response(['ok' => true] + $ticketing->resendOrderWhatsApp($m[1]));
        return;
    }

    if ($method === 'POST' && $path === '/redsys/notification') {
        $notification = $_POST;
        if (!$notification) {
            parse_str((string) file_get_contents('php://input'), $notification);
        }
        error_log('Perigallo Redsys notification received: remote=' . client_ip() . ' keys=' . implode(',', array_keys($notification)));
        $result = $ticketing->processRedsysNotification($notification);
        json_response($result);
        return;
    }

    // API privada de Suite. Nunca se expone al navegador ni comparte la base de datos.
    if (str_starts_with($path, '/internal/')) {
        require_suite_service();
        $sourceApp = 'suite';
        $idempotencyKey = (string) ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '');

        if ($method === 'GET' && $path === '/internal/experiences') {
            $type = isset($_GET['type']) ? (string) $_GET['type'] : null;
            json_response(['ok' => true, 'experiences' => $ticketing->integrationListExperiences($type)]);
            return;
        }

        if ($method === 'POST' && $path === '/internal/experiences') {
            json_response(['ok' => true, 'experience' => $ticketing->integrationCreateExperience(read_json_body(), $sourceApp, $idempotencyKey)], 201);
            return;
        }

        if (preg_match('#^/internal/experiences/([a-f0-9-]{36})$#i', $path, $m)) {
            if ($method === 'GET') {
                $experience = $ticketing->integrationGetExperience($m[1]);
                if (!$experience) {
                    json_response(['ok' => false, 'error' => 'Experiencia no encontrada.'], 404);
                    return;
                }
                json_response(['ok' => true, 'experience' => $experience]);
                return;
            }
            if ($method === 'PATCH') {
                json_response(['ok' => true, 'experience' => $ticketing->integrationUpdateExperience($m[1], read_json_body(), $sourceApp, $idempotencyKey)]);
                return;
            }
        }

        if ($method === 'POST' && preg_match('#^/internal/experiences/([a-f0-9-]{36})/(publish|unpublish)$#i', $path, $m)) {
            json_response(['ok' => true, 'experience' => $ticketing->integrationSetPublication($m[1], $m[2] === 'publish', $sourceApp, $idempotencyKey)]);
            return;
        }

        if ($method === 'GET' && preg_match('#^/internal/experiences/([a-f0-9-]{36})/sales-summary$#i', $path, $m)) {
            json_response(['ok' => true, 'summary' => $ticketing->integrationSalesSummary($m[1])]);
            return;
        }

        if ($method === 'GET' && preg_match('#^/internal/experiences/([a-f0-9-]{36})/orders$#i', $path, $m)) {
            json_response(['ok' => true, 'orders' => $ticketing->integrationOrders($m[1])]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/internal/experiences/([a-f0-9-]{36})/ticket-types$#i', $path, $m)) {
            json_response(['ok' => true, 'ticket_type' => $ticketing->integrationCreateTicketType($m[1], read_json_body(), $sourceApp, $idempotencyKey)], 201);
            return;
        }

        if ($method === 'PATCH' && preg_match('#^/internal/experiences/([a-f0-9-]{36})/ticket-types/([0-9]+)$#i', $path, $m)) {
            json_response(['ok' => true, 'ticket_type' => $ticketing->integrationUpdateTicketType($m[1], (int) $m[2], read_json_body(), $sourceApp, $idempotencyKey)]);
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/internal/experiences/([a-f0-9-]{36})/ticket-types/([0-9]+)$#i', $path, $m)) {
            json_response(['ok' => true] + $ticketing->integrationArchiveTicketType($m[1], (int) $m[2], $sourceApp, $idempotencyKey));
            return;
        }

        json_response(['ok' => false, 'error' => 'Endpoint interno no encontrado.'], 404);
        return;
    }

    if ($method === 'GET' && $path === '/admin/session') {
        json_response(['ok' => true] + AdminAuth::sessionPayload());
        return;
    }

    if ($method === 'POST' && $path === '/admin/login') {
        $data = read_json_body();
        require_fields($data, ['username', 'password']);
        if (!AdminAuth::login((string) $data['username'], (string) $data['password'])) {
            json_response(['ok' => false, 'error' => 'Credenciales invalidas.'], 401);
            return;
        }
        json_response(['ok' => true] + AdminAuth::sessionPayload());
        return;
    }

    if ($method === 'POST' && $path === '/admin/logout') {
        AdminAuth::logout();
        json_response(['ok' => true]);
        return;
    }

    if ($method === 'GET' && $path === '/admin/summary') {
        AdminAuth::require();
        json_response(['ok' => true, 'summary' => $ticketing->adminSummary()]);
        return;
    }

    if ($method === 'GET' && $path === '/admin/orders') {
        AdminAuth::require();
        json_response(['ok' => true, 'orders' => $ticketing->adminOrders()]);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/events/([0-9]+)/test-orders$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true] + $ticketing->createTestOrder((int) $m[1], read_json_body()), 201);
        return;
    }

    if ($method === 'GET' && $path === '/admin/events') {
        AdminAuth::requireAccess();
        json_response(['ok' => true, 'events' => $ticketing->adminListEvents()]);
        return;
    }

    if ($method === 'GET' && preg_match('#^/admin/events/([0-9]+)$#', $path, $m)) {
        AdminAuth::require();
        $event = $ticketing->adminGetEvent((int) $m[1]);
        if (!$event) {
            json_response(['ok' => false, 'error' => 'Evento no encontrado.'], 404);
            return;
        }
        json_response(['ok' => true, 'event' => $event]);
        return;
    }

    if ($method === 'GET' && preg_match('#^/admin/events/([0-9]+)/preview$#', $path, $m)) {
        AdminAuth::require();
        $event = $ticketing->adminGetEvent((int) $m[1]);
        if (!$event) {
            json_response(['ok' => false, 'error' => 'Evento no encontrado.'], 404);
            return;
        }
        json_response(['ok' => true, 'event' => $event]);
        return;
    }

    if ($method === 'POST' && $path === '/admin/events') {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'event' => $ticketing->adminCreateEvent(read_json_body())], 201);
        return;
    }

    if ($method === 'POST' && $path === '/admin/media') {
        AdminAuth::requireCsrf();
        // Cuando post_max_size se supera, PHP descarta $_FILES antes de llegar
        // a adminUploadImage. Devolvemos 413 en vez de un 500 genérico.
        if (!isset($_FILES['file']) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            json_response(['ok' => false, 'error' => 'El archivo supera el límite del servidor. Configura upload_max_filesize y post_max_size en al menos 64M en Plesk.'], 413);
            return;
        }
        json_response(['ok' => true, 'media' => $ticketing->adminUploadImage($_FILES['file'] ?? [], (string) ($_POST['kind'] ?? 'image'))], 201);
        return;
    }

    if ($method === 'PUT' && preg_match('#^/admin/events/([0-9]+)$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'event' => $ticketing->adminUpdateEvent((int) $m[1], read_json_body())]);
        return;
    }

    if ($method === 'PATCH' && preg_match('#^/admin/events/([0-9]+)/public-information$#', $path, $m)) {
        AdminAuth::requireCsrf();
        $event = $ticketing->adminUpdatePublicInformation((int) $m[1], read_json_body());
        json_response(['ok' => true, 'event' => $event, 'public_information' => [
            'included_text' => $event['included_text'] ?? '',
            'access_conditions' => $event['access_conditions'] ?? '',
            'minor_policy' => $event['minor_policy'] ?? '',
            'refund_policy' => $event['refund_policy'] ?? '',
            'faq' => $event['faq'] ?? [],
            'contact_info' => $event['contact_info'] ?? '',
            'recommendations' => $event['recommendations'] ?? '',
            'dress_code' => $event['dress_code'] ?? '',
            'accessibility_info' => $event['accessibility_info'] ?? '',
        ]]);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/events/([0-9]+)/(publish|unpublish)$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'event' => $ticketing->adminSetEventPublication((int) $m[1], $m[2] === 'publish')]);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/events/([0-9]+)/duplicate$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'event' => $ticketing->adminDuplicateEvent((int) $m[1])], 201);
        return;
    }

    if ($method === 'DELETE' && preg_match('#^/admin/events/([0-9]+)$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true] + $ticketing->adminArchiveOrDeleteEvent((int) $m[1]));
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/events/([0-9]+)/ticket-types$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'ticket_type' => $ticketing->adminCreateTicketType((int) $m[1], read_json_body())], 201);
        return;
    }

    if ($method === 'PUT' && preg_match('#^/admin/events/([0-9]+)/ticket-types/([0-9]+)$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'ticket_type' => $ticketing->adminUpdateTicketType((int) $m[1], (int) $m[2], read_json_body())]);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/events/([0-9]+)/ticket-types/([0-9]+)/duplicate$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'ticket_type' => $ticketing->adminDuplicateTicketType((int) $m[1], (int) $m[2])], 201);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/events/([0-9]+)/ticket-types/reorder$#', $path, $m)) {
        AdminAuth::requireCsrf();
        $data = read_json_body();
        json_response(['ok' => true, 'ticket_types' => $ticketing->adminReorderTicketTypes((int) $m[1], is_array($data['ids'] ?? null) ? $data['ids'] : [])]);
        return;
    }

    if ($method === 'DELETE' && preg_match('#^/admin/events/([0-9]+)/ticket-types/([0-9]+)$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true] + $ticketing->adminArchiveOrDeleteTicketType((int) $m[1], (int) $m[2]));
        return;
    }

    if ($method === 'POST' && $path === '/admin/tickets/scan') {
        AdminAuth::requireAccessCsrf();
        json_response(['ok' => true] + $ticketing->scanTicket(read_json_body()));
        return;
    }

    if ($method === 'POST' && $path === '/admin/tickets/access-movement') {
        AdminAuth::requireAccessCsrf();
        json_response(['ok' => true] + $ticketing->registerAccessMovement(read_json_body()));
        return;
    }

    if ($method === 'GET' && preg_match('#^/admin/events/([0-9]+)/attendees$#', $path, $m)) {
        AdminAuth::requireAccess();
        json_response(['ok' => true] + $ticketing->adminEventAttendees((int) $m[1]));
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/events/([0-9]+)/tickets/([^/]+)/revert$#', $path, $m)) {
        AdminAuth::requireCsrf();
        $data = read_json_body();
        json_response(['ok' => true] + $ticketing->reverseTicketCheckIn((int) $m[1], rawurldecode($m[2]), (string) ($data['reason'] ?? '')));
        return;
    }

    json_response(['ok' => false, 'error' => 'Endpoint no encontrado.'], 404);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    if ($e->getCode() === 409) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 409);
        return;
    }
    $isRedsysNotification = $method === 'POST' && $path === '/redsys/notification';
    error_log(($isRedsysNotification ? 'Perigallo Redsys notification rejected: ' : 'Perigallo ticketing API error: ') . $e->getMessage());
    if ($isRedsysNotification) {
        json_response(['ok' => false, 'error' => 'Notificacion de pago rechazada.'], 400);
        return;
    }
    $isProd = env_value('APP_ENV', 'production') === 'production';
    json_response(['ok' => false, 'error' => $isProd ? 'No se pudo procesar la solicitud.' : $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('Perigallo ticketing API error: ' . $e->getMessage());
    $isProd = env_value('APP_ENV', 'production') === 'production';
    json_response([
        'ok' => false,
        'error' => $isProd ? 'No se pudo procesar la solicitud.' : $e->getMessage(),
    ], 500);
}
