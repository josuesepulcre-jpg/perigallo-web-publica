<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Analítica first-party con datos mínimos: identificadores aleatorios de navegador,
 * rutas y eventos de navegación. Nunca recibe ni persiste IP, user-agent completo
 * ni campos de formulario/compra.
 */
final class Analytics
{
    private const EVENT_NAMES = ['page_view', 'section_view', 'scroll', 'click', 'checkout_start', 'payment_start', 'session_ping'];

    public function __construct(private PDO $pdo, private Mailer $mailer)
    {
    }

    public static function isBot(string $userAgent): bool
    {
        return $userAgent !== '' && (bool) preg_match('/bot|crawler|spider|slurp|curl|wget|headless|lighthouse|uptime|monitor|facebookexternalhit|preview/i', $userAgent);
    }

    public function track(array $payload): array
    {
        $visitorId = $this->identifier((string) ($payload['visitor_id'] ?? ''), 'visitor');
        $sessionId = $this->identifier((string) ($payload['session_id'] ?? ''), 'sesión');
        $events = $payload['events'] ?? [];
        if (!is_array($events) || $events === [] || count($events) > 12) {
            throw new InvalidArgumentException('El lote de analítica no es válido.');
        }

        $device = $this->enum((string) ($payload['device'] ?? ''), ['mobile', 'desktop', 'tablet'], 'desktop');
        $language = $this->text((string) ($payload['language'] ?? ''), 16);
        $source = $this->text((string) ($payload['source'] ?? ''), 80);
        $medium = $this->text((string) ($payload['medium'] ?? ''), 80);
        $campaign = $this->text((string) ($payload['campaign'] ?? ''), 120);
        $content = $this->text((string) ($payload['content'] ?? ''), 120);
        $term = $this->text((string) ($payload['term'] ?? ''), 120);
        $referrerHost = $this->host((string) ($payload['referrer_host'] ?? ''));
        $normalisedEvents = array_map(fn ($event) => $this->normaliseEvent(is_array($event) ? $event : []), $events);
        $pageviews = count(array_filter($normalisedEvents, static fn (array $event) => $event['event_name'] === 'page_view'));

        $this->pdo->beginTransaction();
        try {
            $visitor = $this->pdo->prepare(
                'INSERT INTO analytics_visitors (visitor_id, first_seen_at, last_seen_at, visit_count, last_device, last_language)
                 VALUES (?, NOW(), NOW(), 0, ?, ?)
                 ON DUPLICATE KEY UPDATE last_seen_at = NOW(), last_device = VALUES(last_device), last_language = VALUES(last_language)'
            );
            $visitor->execute([$visitorId, $device, $language ?: null]);

            $session = $this->pdo->prepare(
                'INSERT IGNORE INTO analytics_sessions (session_id, visitor_id, started_at, last_activity_at, pageviews, event_count, device, language, source, medium, campaign, content, term, referrer_host)
                 VALUES (?, ?, NOW(), NOW(), 0, 0, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $session->execute([$sessionId, $visitorId, $device, $language ?: null, $source ?: null, $medium ?: null, $campaign ?: null, $content ?: null, $term ?: null, $referrerHost ?: null]);
            if ($session->rowCount() === 1) {
                $this->pdo->prepare('UPDATE analytics_visitors SET visit_count = visit_count + 1, last_seen_at = NOW() WHERE visitor_id = ?')->execute([$visitorId]);
            }

            $this->pdo->prepare(
                'UPDATE analytics_sessions SET last_activity_at = NOW(), pageviews = pageviews + ?, event_count = event_count + ? WHERE session_id = ?'
            )->execute([$pageviews, count($normalisedEvents), $sessionId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO analytics_events (visitor_id, session_id, event_name, page_path, page_title, page_type, section_id, click_id, experience_slug, scroll_depth, device, source, medium, campaign, referrer_host, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            foreach ($normalisedEvents as $event) {
                $insert->execute([
                    $visitorId, $sessionId, $event['event_name'], $event['page_path'], $event['page_title'] ?: null,
                    $event['page_type'] ?: null, $event['section_id'] ?: null, $event['click_id'] ?: null,
                    $event['experience_slug'] ?: null, $event['scroll_depth'], $device, $source ?: null,
                    $medium ?: null, $campaign ?: null, $referrerHost ?: null,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        return ['accepted' => count($normalisedEvents)];
    }

    public function dashboard(array $query = []): array
    {
        [$from, $until, $previousFrom, $previousUntil, $group] = $this->range($query);
        $current = $this->metricTotals($from, $until);
        $previous = $this->metricTotals($previousFrom, $previousUntil);
        $current['conversion_rate'] = $current['visitors'] ? round(($current['conversions'] / $current['visitors']) * 100, 2) : 0.0;
        $previous['conversion_rate'] = $previous['visitors'] ? round(($previous['conversions'] / $previous['visitors']) * 100, 2) : 0.0;

        return [
            'range' => ['from' => substr($from, 0, 10), 'to' => substr($until, 0, 10), 'group' => $group],
            'kpis' => $this->kpis($current, $previous),
            'timeline' => $this->timeline($from, $until, $group),
            'funnel' => $this->funnel($from, $until, $current['visitors']),
            'pages' => $this->topPages($from, $until),
            'sections' => $this->topSections($from, $until, $current['sessions']),
            'scroll_depths' => $this->scrollDepths($from, $until, $current['visitors']),
            'sources' => $this->sources($from, $until),
            'devices' => $this->devices($from, $until),
            'actions' => $this->actions($from, $until),
            'experiences' => $this->experiences($from, $until),
            'realtime' => $this->realtime(),
            'insights' => $this->insights($current, $previous),
        ];
    }

    public function settings(): array
    {
        try {
            $row = $this->pdo->query('SELECT report_email, daily_enabled, weekly_enabled, monthly_enabled, report_hour, timezone, updated_at FROM analytics_settings WHERE id = 1')->fetch() ?: [];
        } catch (Throwable $error) {
            throw new RuntimeException('La analítica requiere ejecutar la migración 016.');
        }
        return [
            'report_email' => $row['report_email'] ?? (env_value('ANALYTICS_REPORT_EMAIL') ?: ''),
            'daily_enabled' => !empty($row['daily_enabled']),
            'weekly_enabled' => !empty($row['weekly_enabled']),
            'monthly_enabled' => !empty($row['monthly_enabled']),
            'report_hour' => (int) ($row['report_hour'] ?? 8),
            'timezone' => (string) ($row['timezone'] ?? 'Europe/Madrid'),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public function saveSettings(array $data): array
    {
        $email = trim((string) ($data['report_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Introduce un correo válido para los informes.');
        $hour = max(0, min(23, (int) ($data['report_hour'] ?? 8)));
        $timezone = (string) ($data['timezone'] ?? 'Europe/Madrid');
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) throw new InvalidArgumentException('La zona horaria indicada no es válida.');
        $statement = $this->pdo->prepare(
            'INSERT INTO analytics_settings (id, report_email, daily_enabled, weekly_enabled, monthly_enabled, report_hour, timezone, updated_at)
             VALUES (1, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE report_email = VALUES(report_email), daily_enabled = VALUES(daily_enabled), weekly_enabled = VALUES(weekly_enabled), monthly_enabled = VALUES(monthly_enabled), report_hour = VALUES(report_hour), timezone = VALUES(timezone), updated_at = NOW()'
        );
        $statement->execute([$email ?: null, !empty($data['daily_enabled']) ? 1 : 0, !empty($data['weekly_enabled']) ? 1 : 0, !empty($data['monthly_enabled']) ? 1 : 0, $hour, $timezone]);
        return $this->settings();
    }

    public function sendTestReport(): array
    {
        $settings = $this->settings();
        if (!$settings['report_email']) throw new RuntimeException('Configura primero el destinatario del informe.');
        $today = new DateTimeImmutable('today', new DateTimeZone($settings['timezone']));
        return $this->sendReport('test', $today->sub(new DateInterval('P1D')), $today, true);
    }

    public function sendDueReports(): array
    {
        $settings = $this->settings();
        if (!$settings['report_email']) return ['sent' => [], 'message' => 'No hay destinatario configurado.'];
        $now = new DateTimeImmutable('now', new DateTimeZone($settings['timezone']));
        if ((int) $now->format('G') !== (int) $settings['report_hour']) return ['sent' => [], 'message' => 'Fuera de la hora configurada.'];
        $sent = [];
        $today = $now->setTime(0, 0);
        if ($settings['daily_enabled']) $sent[] = $this->sendReport('daily', $today->sub(new DateInterval('P1D')), $today);
        if ($settings['weekly_enabled'] && $now->format('N') === '1') $sent[] = $this->sendReport('weekly', $today->sub(new DateInterval('P7D')), $today);
        if ($settings['monthly_enabled'] && $now->format('j') === '1') {
            $start = $today->modify('first day of last month');
            $sent[] = $this->sendReport('monthly', $start, $today->modify('first day of this month'));
        }
        return ['sent' => $sent];
    }

    private function sendReport(string $type, DateTimeImmutable $start, DateTimeImmutable $end, bool $force = false): array
    {
        $settings = $this->settings();
        $recipient = (string) $settings['report_email'];
        if (!$recipient) throw new RuntimeException('No hay destinatario configurado.');
        $periodStart = $start->format('Y-m-d');
        $periodEnd = $end->format('Y-m-d');
        $existing = $this->pdo->prepare('SELECT id, status FROM analytics_reports WHERE report_type = ? AND period_start = ? AND period_end = ? LIMIT 1');
        $existing->execute([$type, $periodStart, $periodEnd]);
        $report = $existing->fetch();
        if ($report && !$force) return ['type' => $type, 'status' => 'skipped', 'reason' => 'already_sent'];

        $dashboard = $this->dashboard(['from' => $periodStart, 'to' => $end->modify('-1 day')->format('Y-m-d')]);
        $subject = 'Perigallo · Informe ' . ($type === 'test' ? 'de prueba' : ($type === 'daily' ? 'diario' : ($type === 'weekly' ? 'semanal' : 'mensual'))) . ' · ' . $start->format('d/m/Y');
        $html = $this->reportHtml($dashboard, $type, $start, $end);
        $text = $this->reportText($dashboard, $type, $start, $end);
        $success = $this->mailer->sendAnalyticsReport($recipient, $subject, $text, $html);

        if ($report) {
            $this->pdo->prepare('UPDATE analytics_reports SET recipient_email = ?, status = ?, summary_json = ?, error_message = ?, generated_at = NOW(), sent_at = IF(? = "sent", NOW(), sent_at) WHERE id = ?')
                ->execute([$recipient, $success ? 'sent' : 'failed', json_encode($dashboard, JSON_UNESCAPED_UNICODE), $success ? null : 'mail() devolvió false.', $success ? 'sent' : 'failed', $report['id']]);
        } else {
            $this->pdo->prepare('INSERT INTO analytics_reports (report_type, period_start, period_end, recipient_email, status, summary_json, error_message, generated_at, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), IF(? = "sent", NOW(), NULL))')
                ->execute([$type, $periodStart, $periodEnd, $recipient, $success ? 'sent' : 'failed', json_encode($dashboard, JSON_UNESCAPED_UNICODE), $success ? null : 'mail() devolvió false.', $success ? 'sent' : 'failed']);
        }
        return ['type' => $type, 'status' => $success ? 'sent' : 'failed', 'recipient' => $recipient];
    }

    private function metricTotals(string $from, string $until): array
    {
        $events = $this->pdo->prepare('SELECT COUNT(DISTINCT visitor_id) visitors, COUNT(DISTINCT session_id) sessions, SUM(event_name = "page_view") pageviews FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ?');
        $events->execute([$from, $until]);
        $row = $events->fetch() ?: [];
        $orders = $this->pdo->prepare('SELECT COUNT(DISTINCT id) conversions, COALESCE(SUM(total_cents), 0) revenue_cents FROM ticket_orders WHERE is_test = 0 AND status = "paid" AND paid_at >= ? AND paid_at < ?');
        $orders->execute([$from, $until]);
        $paid = $orders->fetch() ?: [];
        $duration = $this->pdo->prepare('SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, last_activity_at)) average_session_seconds FROM analytics_sessions WHERE started_at >= ? AND started_at < ?');
        $duration->execute([$from, $until]);
        $engagement = $this->pdo->prepare('SELECT COUNT(*) engaged FROM analytics_sessions WHERE started_at >= ? AND started_at < ? AND (pageviews > 1 OR TIMESTAMPDIFF(SECOND, started_at, last_activity_at) >= 30)');
        $engagement->execute([$from, $until]);
        $totalSessions = (int) ($row['sessions'] ?? 0);
        return [
            'visitors' => (int) ($row['visitors'] ?? 0), 'sessions' => $totalSessions, 'pageviews' => (int) ($row['pageviews'] ?? 0),
            'conversions' => (int) ($paid['conversions'] ?? 0), 'revenue_cents' => (int) ($paid['revenue_cents'] ?? 0),
            'average_session_seconds' => (int) round((float) ($duration->fetchColumn() ?: 0)),
            'pages_per_session' => $totalSessions ? round(((int) ($row['pageviews'] ?? 0)) / $totalSessions, 2) : 0,
            'engagement_rate' => $totalSessions ? round(((int) $engagement->fetchColumn() / $totalSessions) * 100, 2) : 0,
        ];
    }

    private function kpis(array $current, array $previous): array
    {
        $labels = ['visitors' => 'Visitantes', 'sessions' => 'Sesiones', 'pageviews' => 'Páginas vistas', 'conversions' => 'Conversiones', 'average_session_seconds' => 'Tiempo medio', 'pages_per_session' => 'Páginas / sesión', 'engagement_rate' => 'Engagement'];
        $result = [];
        foreach ($labels as $key => $label) {
            $value = (float) ($current[$key] ?? 0);
            $before = (float) ($previous[$key] ?? 0);
            $result[$key] = ['label' => $label, 'value' => $current[$key] ?? 0, 'previous' => $previous[$key] ?? 0, 'change' => $before ? round((($value - $before) / $before) * 100, 1) : null];
        }
        $result['conversion_rate'] = ['label' => 'Conversión', 'value' => $current['conversion_rate'], 'previous' => $previous['conversion_rate'], 'change' => round($current['conversion_rate'] - $previous['conversion_rate'], 2), 'unit' => 'points'];
        $result['revenue_cents'] = ['label' => 'Ingresos', 'value' => $current['revenue_cents'], 'previous' => $previous['revenue_cents'], 'change' => $previous['revenue_cents'] ? round((($current['revenue_cents'] - $previous['revenue_cents']) / $previous['revenue_cents']) * 100, 1) : null];
        return $result;
    }

    private function timeline(string $from, string $until, string $group): array
    {
        $format = $group === 'hour' ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
        $statement = $this->pdo->prepare('SELECT DATE_FORMAT(occurred_at, ?) bucket, COUNT(DISTINCT visitor_id) visitors, COUNT(DISTINCT session_id) sessions, SUM(event_name = "page_view") pageviews FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? GROUP BY bucket ORDER BY bucket ASC');
        $statement->execute([$format, $from, $until]);
        $rows = $statement->fetchAll();
        $orders = $this->pdo->prepare('SELECT DATE_FORMAT(paid_at, ?) bucket, COUNT(*) conversions FROM ticket_orders WHERE is_test = 0 AND status = "paid" AND paid_at >= ? AND paid_at < ? GROUP BY bucket');
        $orders->execute([$format, $from, $until]);
        $conversion = [];
        foreach ($orders->fetchAll() as $row) $conversion[$row['bucket']] = (int) $row['conversions'];
        return array_map(static fn (array $row) => ['bucket' => $row['bucket'], 'visitors' => (int) $row['visitors'], 'sessions' => (int) $row['sessions'], 'pageviews' => (int) $row['pageviews'], 'conversions' => $conversion[$row['bucket']] ?? 0], $rows);
    }

    private function funnel(string $from, string $until, int $visitors): array
    {
        $lookup = function (string $sql, array $params = []) use ($from, $until): int {
            $statement = $this->pdo->prepare($sql);
            $statement->execute(array_merge([$from, $until], $params));
            return (int) $statement->fetchColumn();
        };
        $experienceViews = $lookup('SELECT COUNT(DISTINCT visitor_id) FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? AND event_name = "page_view" AND page_type = "experience"');
        $cta = $lookup('SELECT COUNT(DISTINCT session_id) FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? AND event_name = "click" AND click_id = "comprar-entrada"');
        $checkout = $lookup('SELECT COUNT(DISTINCT session_id) FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? AND event_name = "checkout_start"');
        $payment = $lookup('SELECT COUNT(DISTINCT session_id) FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? AND event_name = "payment_start"');
        $paid = $lookup('SELECT COUNT(DISTINCT id) FROM ticket_orders WHERE paid_at >= ? AND paid_at < ? AND status = "paid" AND is_test = 0');
        $steps = [['id' => 'visit', 'label' => 'Visitantes', 'value' => $visitors], ['id' => 'experience', 'label' => 'Visitan una experiencia', 'value' => $experienceViews], ['id' => 'cta', 'label' => 'Clic “Comprar entradas”', 'value' => $cta], ['id' => 'checkout', 'label' => 'Llegan a checkout', 'value' => $checkout], ['id' => 'payment', 'label' => 'Pago iniciado', 'value' => $payment], ['id' => 'purchase', 'label' => 'Compra completada', 'value' => $paid]];
        return array_map(static fn (array $step) => $step + ['rate' => $visitors ? round(($step['value'] / $visitors) * 100, 2) : 0], $steps);
    }

    private function topPages(string $from, string $until): array
    {
        $statement = $this->pdo->prepare('SELECT page_path, MAX(page_title) page_title, COUNT(*) views, COUNT(DISTINCT visitor_id) visitors FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? AND event_name = "page_view" GROUP BY page_path ORDER BY views DESC LIMIT 12');
        $statement->execute([$from, $until]);
        $scroll = $this->pdo->prepare('SELECT page_path, AVG(scroll_depth) average_scroll FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? AND event_name = "scroll" GROUP BY page_path');
        $scroll->execute([$from, $until]);
        $scrollByPath = [];
        foreach ($scroll->fetchAll() as $row) $scrollByPath[$row['page_path']] = round((float) $row['average_scroll']);
        return array_map(static fn (array $row) => ['path' => $row['page_path'], 'title' => $row['page_title'] ?: $row['page_path'], 'views' => (int) $row['views'], 'visitors' => (int) $row['visitors'], 'average_scroll' => $scrollByPath[$row['page_path']] ?? 0], $statement->fetchAll());
    }

    private function topSections(string $from, string $until, int $totalSessions): array
    {
        $statement = $this->pdo->prepare('SELECT section_id, COUNT(DISTINCT session_id) sessions, COUNT(*) views FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? AND event_name = "section_view" AND section_id IS NOT NULL GROUP BY section_id ORDER BY sessions DESC LIMIT 12');
        $statement->execute([$from, $until]);
        return array_map(static fn (array $row) => [
            'id' => $row['section_id'],
            'sessions' => (int) $row['sessions'],
            'views' => (int) $row['views'],
            'retention_rate' => $totalSessions ? round(((int) $row['sessions'] / $totalSessions) * 100, 2) : 0,
        ], $statement->fetchAll());
    }

    private function scrollDepths(string $from, string $until, int $totalVisitors): array
    {
        $statement = $this->pdo->prepare(
            'SELECT scroll_depth, COUNT(DISTINCT visitor_id) visitors
             FROM analytics_events
             WHERE occurred_at >= ? AND occurred_at < ? AND event_name = "scroll"
             GROUP BY scroll_depth ORDER BY scroll_depth ASC'
        );
        $statement->execute([$from, $until]);
        $byDepth = [];
        foreach ($statement->fetchAll() as $row) $byDepth[(int) $row['scroll_depth']] = (int) $row['visitors'];
        return array_map(static fn (int $depth) => [
            'depth' => $depth,
            'visitors' => $byDepth[$depth] ?? 0,
            'rate' => $totalVisitors ? round((($byDepth[$depth] ?? 0) / $totalVisitors) * 100, 2) : 0,
        ], [25, 50, 75, 90, 100]);
    }

    private function sources(string $from, string $until): array
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(NULLIF(source, ""), "Directo") source, COUNT(*) sessions FROM analytics_sessions WHERE started_at >= ? AND started_at < ? GROUP BY source ORDER BY sessions DESC LIMIT 8');
        $statement->execute([$from, $until]);
        return array_map(static fn (array $row) => ['label' => $row['source'], 'value' => (int) $row['sessions']], $statement->fetchAll());
    }

    private function devices(string $from, string $until): array
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(NULLIF(device, ""), "unknown") device, COUNT(*) sessions FROM analytics_sessions WHERE started_at >= ? AND started_at < ? GROUP BY device ORDER BY sessions DESC');
        $statement->execute([$from, $until]);
        return array_map(static fn (array $row) => ['label' => $row['device'], 'value' => (int) $row['sessions']], $statement->fetchAll());
    }

    private function actions(string $from, string $until): array
    {
        $statement = $this->pdo->prepare('SELECT click_id, COUNT(*) clicks, COUNT(DISTINCT visitor_id) visitors FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? AND event_name = "click" AND click_id IS NOT NULL GROUP BY click_id ORDER BY clicks DESC LIMIT 12');
        $statement->execute([$from, $until]);
        return array_map(static fn (array $row) => ['id' => $row['click_id'], 'clicks' => (int) $row['clicks'], 'visitors' => (int) $row['visitors']], $statement->fetchAll());
    }

    private function experiences(string $from, string $until): array
    {
        $statement = $this->pdo->prepare('SELECT experience_slug, COUNT(DISTINCT visitor_id) visitors, SUM(event_name = "page_view") views, COUNT(DISTINCT CASE WHEN event_name = "click" AND click_id = "comprar-entrada" THEN session_id END) ticket_clicks, COUNT(DISTINCT CASE WHEN event_name = "checkout_start" THEN session_id END) checkouts FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? AND experience_slug IS NOT NULL GROUP BY experience_slug ORDER BY views DESC LIMIT 20');
        $statement->execute([$from, $until]);
        $rows = $statement->fetchAll();
        $titles = [];
        foreach ($this->pdo->query('SELECT slug, title FROM events')->fetchAll() as $event) $titles[$event['slug']] = $event['title'];
        $sales = $this->pdo->prepare('SELECT e.slug, COUNT(DISTINCT o.id) purchases FROM ticket_orders o JOIN ticket_order_items i ON i.order_id = o.id JOIN events e ON e.id = i.event_id WHERE o.is_test = 0 AND o.status = "paid" AND o.paid_at >= ? AND o.paid_at < ? GROUP BY e.slug');
        $sales->execute([$from, $until]);
        $purchases = [];
        foreach ($sales->fetchAll() as $row) $purchases[$row['slug']] = (int) $row['purchases'];
        return array_map(static fn (array $row) => ['slug' => $row['experience_slug'], 'title' => $titles[$row['experience_slug']] ?? $row['experience_slug'], 'visitors' => (int) $row['visitors'], 'views' => (int) $row['views'], 'ticket_clicks' => (int) $row['ticket_clicks'], 'checkouts' => (int) $row['checkouts'], 'purchases' => $purchases[$row['experience_slug']] ?? 0, 'conversion_rate' => (int) $row['visitors'] ? round((($purchases[$row['experience_slug']] ?? 0) / (int) $row['visitors']) * 100, 2) : 0], $rows);
    }

    private function realtime(): array
    {
        $statement = $this->pdo->query('SELECT COALESCE(NULLIF((SELECT page_path FROM analytics_events e WHERE e.session_id = s.session_id ORDER BY e.id DESC LIMIT 1), ""), "/") page_path, COUNT(*) sessions FROM analytics_sessions s WHERE s.last_activity_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) GROUP BY page_path ORDER BY sessions DESC LIMIT 6');
        $pages = array_map(static fn (array $row) => ['path' => $row['page_path'], 'sessions' => (int) $row['sessions']], $statement->fetchAll());
        return ['active_sessions' => array_sum(array_column($pages, 'sessions')), 'pages' => $pages];
    }

    private function insights(array $current, array $previous): array
    {
        $insights = [];
        if ($previous['visitors'] > 0) {
            $change = round((($current['visitors'] - $previous['visitors']) / $previous['visitors']) * 100, 1);
            if (abs($change) >= 15) $insights[] = ['tone' => $change > 0 ? 'positive' : 'warning', 'text' => 'Las visitas ' . ($change > 0 ? 'aumentaron ' : 'descendieron ') . abs($change) . ' % respecto al período anterior.'];
        }
        if ($current['conversions'] === 0 && $current['sessions'] >= 10) $insights[] = ['tone' => 'warning', 'text' => 'No se registraron compras confirmadas pese a haber sesiones activas.'];
        if ($current['conversion_rate'] > $previous['conversion_rate'] && $current['conversions'] > 0) $insights[] = ['tone' => 'positive', 'text' => 'La conversión mejoró hasta ' . $current['conversion_rate'] . ' %.'];
        return $insights;
    }

    private function range(array $query): array
    {
        $preset = (string) ($query['range'] ?? '7d');
        $today = new DateTimeImmutable('today');
        if ($preset === 'today') { $start = $today; $end = $today->add(new DateInterval('P1D')); $group = 'hour'; }
        elseif ($preset === '30d') { $start = $today->sub(new DateInterval('P29D')); $end = $today->add(new DateInterval('P1D')); $group = 'day'; }
        elseif ($preset === 'custom' && !empty($query['from']) && !empty($query['to'])) { $start = new DateTimeImmutable((string) $query['from']); $end = (new DateTimeImmutable((string) $query['to']))->add(new DateInterval('P1D')); $group = 'day'; }
        else { $start = $today->sub(new DateInterval('P6D')); $end = $today->add(new DateInterval('P1D')); $group = 'day'; }
        if ($end <= $start || $start < $today->sub(new DateInterval('P730D'))) throw new InvalidArgumentException('El intervalo de analítica no es válido.');
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        $previousEnd = $start;
        $previousStart = $start->sub(new DateInterval('PT' . $seconds . 'S'));
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $previousStart->format('Y-m-d H:i:s'), $previousEnd->format('Y-m-d H:i:s'), $group];
    }

    private function normaliseEvent(array $event): array
    {
        $name = (string) ($event['name'] ?? '');
        if (!in_array($name, self::EVENT_NAMES, true)) throw new InvalidArgumentException('Evento de analítica no permitido.');
        $depth = isset($event['scroll_depth']) ? (int) $event['scroll_depth'] : null;
        if ($name === 'scroll' && !in_array($depth, [25, 50, 75, 90, 100], true)) throw new InvalidArgumentException('Profundidad de scroll no válida.');
        return ['event_name' => $name, 'page_path' => $this->path((string) ($event['page_path'] ?? '/')), 'page_title' => $this->text((string) ($event['page_title'] ?? ''), 180), 'page_type' => $this->text((string) ($event['page_type'] ?? ''), 40), 'section_id' => $this->text((string) ($event['section_id'] ?? ''), 120), 'click_id' => $this->text((string) ($event['click_id'] ?? ''), 120), 'experience_slug' => $this->text((string) ($event['experience_slug'] ?? ''), 160), 'scroll_depth' => $depth];
    }

    private function identifier(string $value, string $label): string
    {
        if (!preg_match('/^[A-Za-z0-9-]{16,36}$/', $value)) throw new InvalidArgumentException('El identificador de ' . $label . ' no es válido.');
        return $value;
    }

    private function path(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH) ?: '/';
        if (!str_starts_with($path, '/')) $path = '/';
        return mb_substr($path, 0, 512);
    }

    private function host(string $value): string
    {
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        return preg_match('/^[a-z0-9.-]{1,190}$/', $host) ? $host : '';
    }

    private function text(string $value, int $max): string { return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? ''), 0, $max); }
    private function enum(string $value, array $allowed, string $default): string { return in_array($value, $allowed, true) ? $value : $default; }

    private function reportText(array $dashboard, string $type, DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $kpis = $dashboard['kpis'];
        $lines = [
            'PERIGALLO', 'Informe ' . $type, $start->format('d/m/Y') . ' – ' . $end->modify('-1 day')->format('d/m/Y'), '',
            'RESUMEN',
            'Visitantes: ' . $kpis['visitors']['value'], 'Sesiones: ' . $kpis['sessions']['value'], 'Páginas vistas: ' . $kpis['pageviews']['value'],
            'Compras confirmadas: ' . $kpis['conversions']['value'], 'Ingresos: ' . number_format(((int) $kpis['revenue_cents']['value']) / 100, 2, ',', '.') . ' €', 'Conversión: ' . $kpis['conversion_rate']['value'] . ' %', '',
            'EMBUDO',
        ];
        foreach ($dashboard['funnel'] as $step) $lines[] = $step['label'] . ': ' . $step['value'] . ' (' . $step['rate'] . ' %)';
        $lines[] = '';
        $lines[] = 'PÁGINAS MÁS VISTAS';
        foreach (array_slice($dashboard['pages'], 0, 5) as $page) $lines[] = '• ' . $page['title'] . ': ' . $page['views'] . ' vistas';
        $lines[] = '';
        $lines[] = 'FUENTES';
        foreach (array_slice($dashboard['sources'], 0, 5) as $source) $lines[] = '• ' . $source['label'] . ': ' . $source['value'] . ' sesiones';
        foreach ($dashboard['insights'] as $insight) $lines[] = '• ' . $insight['text'];
        return implode("\n", $lines) . "\n";
    }

    private function reportHtml(array $dashboard, string $type, DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $kpis = $dashboard['kpis'];
        $items = [['Visitantes', $kpis['visitors']['value']], ['Sesiones', $kpis['sessions']['value']], ['Páginas vistas', $kpis['pageviews']['value']], ['Compras', $kpis['conversions']['value']], ['Ingresos', number_format(((int) $kpis['revenue_cents']['value']) / 100, 2, ',', '.') . ' €'], ['Conversión', $kpis['conversion_rate']['value'] . ' %']];
        $cards = implode('', array_map(static fn (array $item) => '<td style="padding:13px;border:1px solid #516369"><span style="display:block;color:#cdb197;font-size:10px;letter-spacing:1px;text-transform:uppercase">' . htmlspecialchars($item[0]) . '</span><strong style="display:block;margin-top:7px;color:#f5f1e5;font:300 28px Georgia,serif">' . htmlspecialchars((string) $item[1]) . '</strong></td>', $items));
        $insights = $dashboard['insights'] ? '<ul style="padding-left:18px;color:#d7d4cb;line-height:1.7">' . implode('', array_map(static fn (array $item) => '<li>' . htmlspecialchars($item['text']) . '</li>', $dashboard['insights'])) . '</ul>' : '<p style="color:#d7d4cb">Aún no hay suficientes datos para generar observaciones.</p>';
        $funnel = '<ol style="margin:0;padding-left:20px;color:#d7d4cb;line-height:1.8">' . implode('', array_map(static fn (array $item) => '<li>' . htmlspecialchars($item['label']) . ': <strong style="color:#f5f1e5">' . htmlspecialchars((string) $item['value']) . '</strong> <span style="color:#cdb197">(' . htmlspecialchars((string) $item['rate']) . ' %)</span></li>', $dashboard['funnel'])) . '</ol>';
        $pages = '<ol style="margin:0;padding-left:20px;color:#d7d4cb;line-height:1.8">' . implode('', array_map(static fn (array $item) => '<li>' . htmlspecialchars($item['title']) . ' <span style="color:#cdb197">· ' . htmlspecialchars((string) $item['views']) . ' vistas</span></li>', array_slice($dashboard['pages'], 0, 5))) . '</ol>';
        $sources = '<p style="margin:0;color:#d7d4cb;line-height:1.8">' . implode(' · ', array_map(static fn (array $item) => htmlspecialchars($item['label']) . ' <strong style="color:#f5f1e5">' . htmlspecialchars((string) $item['value']) . '</strong>', array_slice($dashboard['sources'], 0, 5))) . '</p>';
        return '<!doctype html><html lang="es"><body style="margin:0;background:#edf0ed;padding:28px 14px;font-family:Arial,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#173236;color:#f5f1e5"><tr><td style="padding:30px 34px;border-bottom:1px solid #516369"><span style="color:#cdb197;font-size:10px;letter-spacing:2px;text-transform:uppercase">Perigallo · Analítica</span><h1 style="margin:13px 0 0;font:300 34px Georgia,serif">Informe ' . htmlspecialchars($type) . '</h1><p style="margin:10px 0 0;color:#d7d4cb">' . $start->format('d/m/Y') . ' – ' . $end->modify('-1 day')->format('d/m/Y') . '</p></td></tr><tr><td style="padding:28px 34px"><table role="presentation" width="100%" cellspacing="8" cellpadding="0"><tr>' . $cards . '</tr></table><h2 style="margin:30px 0 12px;font:300 25px Georgia,serif">Embudo</h2>' . $funnel . '<h2 style="margin:30px 0 12px;font:300 25px Georgia,serif">Páginas más visitadas</h2>' . $pages . '<h2 style="margin:30px 0 12px;font:300 25px Georgia,serif">Fuentes</h2>' . $sources . '<h2 style="margin:30px 0 12px;font:300 25px Georgia,serif">Insights</h2>' . $insights . '<p style="margin:30px 0 0"><a href="' . htmlspecialchars(app_base_url() . '/admin/analitica/', ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:14px 19px;background:#cdb197;color:#173236;text-decoration:none;font-size:11px;letter-spacing:1.4px;text-transform:uppercase">Abrir analítica →</a></p></td></tr></table></td></tr></table></body></html>';
    }
}
