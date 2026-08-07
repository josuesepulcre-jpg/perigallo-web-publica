<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Perigallo\Ticketing\AdminAuth;
use Perigallo\Ticketing\Analytics;
use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\Mailer;
use Perigallo\Ticketing\LeadForms;
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
    $mailer = new Mailer();
    $ticketing = new Ticketing(Database::pdo(), new Redsys(), $mailer);
    $analytics = new Analytics(Database::pdo(), $mailer);

    if ($method === 'POST' && $path === '/analytics/events') {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > 12000) {
            json_response(['ok' => false, 'error' => 'El lote de analítica es demasiado grande.'], 413);
            return;
        }
        if (Analytics::isBot((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
            json_response(['ok' => true, 'accepted' => 0]);
            return;
        }
        json_response(['ok' => true] + $analytics->track(read_json_body()), 202);
        return;
    }

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

    if ($method === 'GET' && $path === '/payment-methods') {
        json_response(['ok' => true, 'methods' => $ticketing->publicPaymentMethods()]);
        return;
    }

    if ($method === 'POST' && $path === '/discounts/validate') {
        json_response(['ok' => true, 'discount' => $ticketing->validateDiscount(read_json_body())]);
        return;
    }

    if ($method === 'POST' && $path === '/orders') {
        $result = $ticketing->createOrder(read_json_body());
        json_response(['ok' => true] + $result, 201);
        return;
    }

    if ($method === 'GET' && $path === '/formulario/settings') {
        $leadForms = new LeadForms(Database::pdo(), $mailer);
        json_response(['ok' => true, 'settings' => $leadForms->publicSettings()]);
        return;
    }

    if ($method === 'POST' && $path === '/formulario') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 150000) {
            json_response(['ok' => false, 'error' => 'La solicitud es demasiado extensa. Reduce el contenido e inténtalo de nuevo.'], 413);
            return;
        }
        $leadForms = new LeadForms(Database::pdo(), $mailer);
        json_response(['ok' => true, 'request' => $leadForms->submit(read_json_body(), client_ip())], 201);
        return;
    }

    if ($method === 'POST' && $path === '/orders/recover') {
        json_response(['ok' => true] + $ticketing->requestOrderAccessRecovery(read_json_body()));
        return;
    }

    if ($method === 'GET' && preg_match('#^/orders/access/([A-Za-z0-9_-]+)$#', $path, $m)) {
        $order = $ticketing->getOrderByAccessLink($m[1]);
        if (!$order) {
            json_response(['ok' => false, 'error' => 'Este enlace ya no está disponible. Solicita uno nuevo para acceder a tus entradas.'], 404);
            return;
        }
        json_response(['ok' => true, 'order' => $order]);
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

    if ($method === 'GET' && $path === '/admin/analytics') {
        AdminAuth::require();
        json_response(['ok' => true, 'analytics' => $analytics->dashboard($_GET)]);
        return;
    }

    if ($method === 'GET' && $path === '/admin/analytics/settings') {
        AdminAuth::require();
        json_response(['ok' => true, 'settings' => $analytics->settings()]);
        return;
    }

    if ($method === 'PUT' && $path === '/admin/analytics/settings') {
        AdminAuth::requireOwner();
        json_response(['ok' => true, 'settings' => $analytics->saveSettings(read_json_body())]);
        return;
    }

    if ($method === 'POST' && $path === '/admin/analytics/send-test-report') {
        AdminAuth::requireOwner();
        json_response(['ok' => true, 'report' => $analytics->sendTestReport()]);
        return;
    }

    if ($method === 'GET' && $path === '/admin/orders') {
        AdminAuth::require();
        json_response(['ok' => true, 'orders' => $ticketing->adminOrders()]);
        return;
    }

    if ($method === 'GET' && $path === '/admin/formulario/settings') {
        AdminAuth::require();
        $leadForms = new LeadForms(Database::pdo(), $mailer);
        json_response(['ok' => true, 'settings' => $leadForms->adminSettings()]);
        return;
    }

    if ($method === 'PUT' && $path === '/admin/formulario/settings') {
        AdminAuth::requireCsrf();
        $leadForms = new LeadForms(Database::pdo(), $mailer);
        json_response(['ok' => true, 'settings' => $leadForms->saveSettings(read_json_body())]);
        return;
    }

    if ($method === 'GET' && $path === '/admin/formulario/solicitudes') {
        AdminAuth::require();
        $leadForms = new LeadForms(Database::pdo(), $mailer);
        json_response(['ok' => true, 'requests' => $leadForms->adminRequests($_GET)]);
        return;
    }

    if ($method === 'GET' && preg_match('#^/admin/formulario/solicitudes/([0-9]+)$#', $path, $m)) {
        AdminAuth::require();
        $leadForms = new LeadForms(Database::pdo(), $mailer);
        json_response(['ok' => true, 'request' => $leadForms->adminRequest((int) $m[1])]);
        return;
    }

    if ($method === 'PUT' && preg_match('#^/admin/formulario/solicitudes/([0-9]+)/estado$#', $path, $m)) {
        AdminAuth::requireCsrf();
        $data = read_json_body();
        $leadForms = new LeadForms(Database::pdo(), $mailer);
        json_response(['ok' => true, 'request' => $leadForms->updateStatus((int) $m[1], (string) ($data['status'] ?? ''))]);
        return;
    }

    if ($method === 'GET' && preg_match('#^/admin/orders/([0-9]+)/attendees$#', $path, $m)) {
        AdminAuth::require();
        json_response(['ok' => true] + $ticketing->adminOrderAttendees((int) $m[1]));
        return;
    }

    if ($method === 'GET' && $path === '/admin/discount-codes/meta') {
        AdminAuth::require();
        json_response(['ok' => true] + $ticketing->adminDiscountCodeMeta());
        return;
    }

    if ($method === 'GET' && $path === '/admin/discount-codes') {
        AdminAuth::require();
        json_response(['ok' => true, 'discount_codes' => $ticketing->adminDiscountCodes($_GET)]);
        return;
    }

    if ($method === 'POST' && $path === '/admin/discount-codes') {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'discount_code' => $ticketing->adminSaveDiscountCode(read_json_body(), AdminAuth::operatorName())], 201);
        return;
    }

    if ($method === 'GET' && preg_match('#^/admin/discount-codes/([0-9]+)$#', $path, $m)) {
        AdminAuth::require();
        json_response(['ok' => true, 'discount_code' => $ticketing->adminDiscountCode((int) $m[1])]);
        return;
    }

    if ($method === 'PUT' && preg_match('#^/admin/discount-codes/([0-9]+)$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'discount_code' => $ticketing->adminSaveDiscountCode(read_json_body(), AdminAuth::operatorName(), (int) $m[1])]);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/discount-codes/([0-9]+)/duplicate$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'discount_code' => $ticketing->adminDuplicateDiscountCode((int) $m[1], AdminAuth::operatorName())], 201);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/discount-codes/([0-9]+)/archive$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'discount_code' => $ticketing->adminArchiveDiscountCode((int) $m[1], AdminAuth::operatorName())]);
        return;
    }

    if ($method === 'DELETE' && preg_match('#^/admin/discount-codes/([0-9]+)$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true] + $ticketing->adminDeleteUnusedDiscountCode((int) $m[1], AdminAuth::operatorName()));
        return;
    }

    if ($method === 'GET' && preg_match('#^/admin/discount-codes/([0-9]+)/history$#', $path, $m)) {
        AdminAuth::require();
        json_response(['ok' => true, 'usages' => $ticketing->adminDiscountCodeHistory((int) $m[1])]);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/orders/([0-9]+)/cancel$#', $path, $m)) {
        AdminAuth::requireCsrf();
        $data = read_json_body();
        json_response(['ok' => true, 'order' => $ticketing->adminCancelOrder((int) $m[1], AdminAuth::operatorName(), (string) ($data['reason'] ?? ''))]);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/orders/([0-9]+)/record-refund$#', $path, $m)) {
        AdminAuth::requireCsrf();
        $data = read_json_body();
        if (empty($data['confirmed'])) {
            throw new InvalidArgumentException('Confirma que el abono se ha realizado fuera de esta aplicación.');
        }
        json_response(['ok' => true, 'order' => $ticketing->adminRecordRefund((int) $m[1], AdminAuth::operatorName(), (string) ($data['reason'] ?? ''))]);
        return;
    }

    if ($method === 'DELETE' && preg_match('#^/admin/orders/([0-9]+)/test$#', $path, $m)) {
        AdminAuth::requireOwner();
        $ticketing->adminPurgeTestOrder((int) $m[1], AdminAuth::operatorName());
        json_response(['ok' => true]);
        return;
    }

    if ($method === 'GET' && $path === '/admin/users') {
        AdminAuth::requireOwnerSession();
        json_response(['ok' => true, 'users' => AdminAuth::listManagedUsers()]);
        return;
    }

    if ($method === 'POST' && $path === '/admin/users') {
        AdminAuth::requireOwner();
        json_response(['ok' => true, 'user' => AdminAuth::createManagedUser(read_json_body())], 201);
        return;
    }

    if ($method === 'PUT' && preg_match('#^/admin/users/([0-9]+)$#', $path, $m)) {
        AdminAuth::requireOwner();
        json_response(['ok' => true, 'user' => AdminAuth::updateManagedUser((int) $m[1], read_json_body())]);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/users/([0-9]+)/password$#', $path, $m)) {
        AdminAuth::requireOwner();
        $data = read_json_body();
        AdminAuth::updateManagedUserPassword((int) $m[1], (string) ($data['password'] ?? ''));
        json_response(['ok' => true]);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/events/([0-9]+)/test-orders$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true] + $ticketing->createTestOrder((int) $m[1], read_json_body()), 201);
        return;
    }

    if ($method === 'POST' && preg_match('#^/admin/events/([0-9]+)/discounts/validate$#', $path, $m)) {
        AdminAuth::requireCsrf();
        json_response(['ok' => true, 'discount' => $ticketing->validateTestDiscount((int) $m[1], read_json_body())]);
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

    if ($method === 'POST' && $path === '/admin/tickets/access-preview') {
        AdminAuth::requireAccessCsrf();
        json_response(['ok' => true] + $ticketing->previewTicketAccess(read_json_body()));
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
    if (in_array($e->getCode(), [409, 429], true)) {
        json_response(['ok' => false, 'error' => $e->getMessage()], $e->getCode());
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
