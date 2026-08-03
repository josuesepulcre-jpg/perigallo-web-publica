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

    if ($method === 'POST' && $path === '/redsys/notification') {
        $result = $ticketing->processRedsysNotification($_POST);
        json_response($result);
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

    if ($method === 'POST' && $path === '/admin/events') {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'event' => $ticketing->adminCreateEvent(read_json_body())], 201);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/events/([0-9]+)/ticket-types$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'ticket_type' => $ticketing->adminCreateTicketType((int) $m[1], read_json_body())], 201);
        return;
    }

    if ($method === 'POST' && $path === '/admin/tickets/scan') {
        AdminAuth::requireCsrf();
        json_response(['ok' => true] + $ticketing->scanTicket(read_json_body()));
        return;
    }

    json_response(['ok' => false, 'error' => 'Endpoint no encontrado.'], 404);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Perigallo ticketing API error: ' . $e->getMessage());
    $isProd = env_value('APP_ENV', 'production') === 'production';
    json_response([
        'ok' => false,
        'error' => $isProd ? 'No se pudo procesar la solicitud.' : $e->getMessage(),
    ], 500);
}
