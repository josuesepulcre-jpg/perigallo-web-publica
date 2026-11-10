<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Domain service for a deliberately isolated simulation. No Instagram transport exists here. */
final class InstagramAgentService
{
    private InstagramAgentStore $store;
    private Closure $generator;
    private bool $trustedGenerator;

    public function __construct(InstagramAgentStore $store, callable $generator)
    {
        $this->store = $store;
        $this->generator = Closure::fromCallable($generator);
        $this->trustedGenerator = is_array($generator) && is_object($generator[0] ?? null)
            && get_class($generator[0]) === __NAMESPACE__ . '\\InstagramAgentGenerator' && ($generator[1] ?? null) === 'generate';
    }

    public function snapshot(): array
    {
        $this->reconcileStaleRuns();
        $state = $this->store->read();
        return ['state' => $state, 'report' => $this->makeReport($state)];
    }

    public function command(string $op, array $input, string $actor): array
    {
        $this->reconcileStaleRuns();
        $actor = $this->text($actor, 120, 'responsable', true);
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 120000) {
            throw new InvalidArgumentException('La petición es demasiado grande.', 413);
        }
        if (in_array($op, ['publish_real', 'send_real', 'set_mode'], true)
            || (isset($input['mode']) && $input['mode'] !== 'SIMULATION')) {
            throw new RuntimeException('Las operaciones reales están deshabilitadas. Solo está disponible SIMULACIÓN.', 403);
        }
        $result = match ($op) {
            'generate_content', 'simulate_conversation', 'run_cycle' => $this->generate($op, $input, $actor),
            'save_draft' => $this->saveDraft($input, $actor),
            'review_draft' => $this->reviewDraft($input, $actor),
            'simulate_publish' => $this->simulatePublish($input, $actor),
            'save_task' => $this->saveTask($input, $actor),
            'pause' => $this->setPaused(true, $actor),
            'resume' => $this->setPaused(false, $actor),
            'save_settings' => $this->saveSettings($input, $actor),
            'report' => ['report' => $this->makeReport($this->store->read(), $this->reportDays($input))],
            default => throw new InvalidArgumentException('Operación del agente no reconocida.', 400),
        };
        return $this->response($op, $result);
    }

    public function runScheduled(string $actor = 'scheduler'): array
    {
        $this->reconcileStaleRuns();
        $actor = $this->text($actor, 120, 'responsable', true);
        $now = $this->localNow();
        $check = $this->store->transaction(function (array &$state) use ($now): array {
            $state['schedule']['last_heartbeat'] = $this->now();
            if ($state['paused']) {
                return ['skipped' => true, 'reason' => 'paused'];
            }
            if (!$state['settings']['schedule_enabled']) {
                return ['skipped' => true, 'reason' => 'schedule_disabled'];
            }
            if ($now->format('H:i') < $state['settings']['schedule_time']) {
                return ['skipped' => true, 'reason' => 'not_due'];
            }
            if (($state['schedule']['last_run_day'] ?? null) === $now->format('Y-m-d')) {
                return ['skipped' => true, 'reason' => 'already_attempted_today', 'run_id' => $state['schedule']['last_run_id'] ?? null];
            }
            return ['skipped' => false];
        });
        if ($check['skipped']) {
            return $this->response('run_scheduled', $check);
        }
        $result = $this->generate('run_cycle', [
            'request_id' => 'scheduled-' . $now->format('Y-m-d'),
            'brief' => 'Prepara una propuesta para el piloto de Instagram de Perigallo. Utiliza únicamente el contexto confirmado; evita precios, disponibilidad o eventos no verificados. Deja el contenido listo para revisión humana en simulación.',
            'format' => 'photo',
        ], $actor, true);
        return $this->response('run_scheduled', $result);
    }

    private function response(string $op, array $result): array
    {
        return array_merge(['ok' => true, 'mode' => 'SIMULATION', 'operation' => $op], $result, ['snapshot' => $this->snapshot()]);
    }

    private function generate(string $op, array $input, string $actor, bool $scheduled = false): array
    {
        $requestId = $this->requestId($input['request_id'] ?? null);
        $kind = $op === 'simulate_conversation' ? 'conversation' : 'content';
        if ($kind === 'conversation') {
            $normalized = [
                'message' => $this->text($input['message'] ?? '', 5000, 'mensaje', true),
                'label' => $this->text($input['label'] ?? 'Conversación de prueba', 180, 'nombre del caso'),
                'conversation_id' => $this->optionalId($input['conversation_id'] ?? null),
                'no_contact' => $this->boolean($input['no_contact'] ?? false, 'no contactar'),
                'handoff' => $this->boolean($input['handoff'] ?? false, 'intervención humana'),
            ];
        } else {
            $normalized = [
                'brief' => $this->text($input['brief'] ?? ($op === 'run_cycle' ? 'Prepara una propuesta de Instagram para el piloto de Perigallo con el contexto disponible.' : ''), 6000, 'encargo', true),
                'format' => $this->format($input['format'] ?? 'photo'),
                'objective' => $this->text($input['objective'] ?? '', 1000, 'objetivo'),
            ];
        }
        $fingerprint = $this->hash(['operation' => $op, 'input' => $normalized, 'scheduled' => $scheduled]);
        // Reserve a daily budget slot and idempotency key before releasing the lock for the network call.
        $reservation = $this->store->transaction(function (array &$state) use ($op, $kind, $normalized, $requestId, $fingerprint, $actor, $scheduled): array {
            foreach ($state['runs'] as $run) {
                if (($run['request_id'] ?? null) === $requestId) {
                    if (!hash_equals((string) ($run['fingerprint'] ?? ''), $fingerprint)) {
                        throw new RuntimeException('Ese identificador de petición ya se utilizó con otros datos.', 409);
                    }
                    return ['existing' => true, 'run' => $run];
                }
            }
            $this->requireRunning($state);
            $day = $this->localNow()->format('Y-m-d');
            if ($scheduled && (!$state['settings']['schedule_enabled']
                || $this->localNow()->format('H:i') < $state['settings']['schedule_time']
                || ($state['schedule']['last_run_day'] ?? null) === $day)) {
                return ['skipped' => true, 'reason' => 'schedule_changed_or_already_reserved'];
            }
            $used = count(array_filter($state['runs'], static fn(array $run): bool => !empty($run['generation_reserved']) && ($run['local_day'] ?? '') === $day));
            if ($used >= $state['settings']['daily_generation_limit']) {
                throw new RuntimeException('Se ha alcanzado el límite diario de generaciones. Los reintentos fallidos también consumen una reserva.', 429);
            }
            $now = $this->now();
            $runId = $this->id('run');
            $payload = array_merge($normalized, [
                'brand_context' => $state['settings']['brand_context'],
                'mode' => 'SIMULATION',
                'account' => 'somosperigallo',
                'timezone' => 'Europe/Madrid',
                'current_date' => $day,
            ]);
            $conversationId = null;
            if ($kind === 'conversation') {
                $conversationId = $normalized['conversation_id'] ?: $this->id('conversation');
                $index = $this->index($state['conversations'], $conversationId);
                if ($normalized['conversation_id'] !== null && $index === null) {
                    throw new InvalidArgumentException('La conversación no existe.', 404);
                }
                $conversation = $index !== null ? $state['conversations'][$index] : [
                    'id' => $conversationId, 'revision' => 0, 'label' => $normalized['label'],
                    'source' => 'SIMULATED', 'messages' => [], 'no_contact' => false, 'handoff' => false,
                    'classification' => 'PENDING', 'reply' => '', 'decision' => 'ESCALATE',
                    'next_action' => '', 'lead_status' => 'NEW', 'pending_facts' => [],
                    'created_at' => $now,
                ];
                if (!empty($conversation['pending_run_id'])) {
                    throw new RuntimeException('Esta conversación tiene una generación pendiente. Comprueba su resultado antes de enviar otra.', 409);
                }
                $conversation['no_contact'] = $conversation['no_contact'] || $normalized['no_contact'] || $this->detectNoContact($normalized['message']);
                $conversation['handoff'] = $conversation['handoff'] || $normalized['handoff'] || $this->detectHandoff($normalized['message']);
                if ($conversation['no_contact'] || $conversation['handoff']) {
                    $conversation['decision'] = $conversation['no_contact'] ? 'NO_CONTACT' : 'HUMAN_TAKEOVER';
                    $conversation['reply'] = '';
                }
                $conversation['revision']++;
                $conversation['updated_at'] = $now;
                $conversation['last_inbound_at'] = $now;
                $conversation['pending_run_id'] = $runId;
                $conversation['status'] = 'GENERATING';
                $conversation['messages'][] = ['id' => $this->id('message'), 'role' => 'user', 'text' => $normalized['message'], 'source' => 'SIMULATED', 'created_at' => $now, 'request_id' => $requestId];
                if ($index === null) {
                    $state['conversations'][] = $conversation;
                } else {
                    $state['conversations'][$index] = $conversation;
                }
                if ($conversation['no_contact'] || $conversation['handoff']) {
                    $this->cancelFollowUps($state, $conversationId, $actor);
                }
                $payload['no_contact'] = $conversation['no_contact'];
                $payload['handoff'] = $conversation['handoff'];
                // Preserve current input and brand facts; only bound the optional historical context.
                $historyBudget = max(0, 60000 - strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
                $history = [];
                foreach (array_reverse(array_slice($conversation['messages'], -20)) as $message) {
                    $bytes = strlen(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) + 1;
                    if ($bytes > $historyBudget) {
                        break;
                    }
                    $history[] = $message;
                    $historyBudget -= $bytes;
                }
                $payload['history'] = array_reverse($history);
            }
            $run = [
                'id' => $runId, 'request_id' => $requestId, 'fingerprint' => $fingerprint,
                'operation' => $op, 'kind' => $kind, 'status' => 'RUNNING', 'mode' => 'SIMULATION',
                'generation_reserved' => true, 'local_day' => $day, 'actor' => $actor,
                'created_at' => $now, 'updated_at' => $now, 'completed_at' => null,
                'conversation_id' => $conversationId, 'draft_id' => null,
                'context_hash' => $this->hash($state['settings']['brand_context']),
                'scheduled' => $scheduled, 'error' => null, 'error_code' => null, 'generation' => null,
                'cancellation_requested' => false,
            ];
            $state['runs'][] = $run;
            if ($scheduled) {
                $state['schedule']['last_run'] = $now;
                $state['schedule']['last_run_day'] = $day;
                $state['schedule']['last_run_id'] = $runId;
            }
            $this->audit($state, $actor, 'generation_reserved', $runId, ['operation' => $op, 'request_id' => $requestId]);
            return ['existing' => false, 'run' => $run, 'payload' => $payload];
        });
        if (!empty($reservation['skipped'])) {
            return $reservation;
        }
        if ($reservation['existing']) {
            return $this->replay($reservation['run']);
        }
        $runId = $reservation['run']['id'];
        $generation = null;
        try {
            $generated = ($this->generator)($kind, $reservation['payload']);
            if (!is_array($generated)) {
                throw new RuntimeException('La IA devolvió un formato no válido.', 502);
            }
            $generation = $this->generationMetadata($generated['_generation'] ?? null);
            $generated = $kind === 'content' ? $this->contentFields($generated, $normalized['format']) : $this->conversationFields($generated);
        } catch (Throwable $error) {
            $code = $error instanceof RuntimeException && $error->getCode() === 504 ? 504 : 502;
            $message = $code === 504
                ? 'No se pudo confirmar el resultado de la generación. El resultado es incierto y no se ha reintentado automáticamente.'
                : 'La generación no se ha completado. El intento quedó registrado; comprueba la conexión antes de iniciar un nuevo intento.';
            if ($this->trustedGenerator && $error instanceof RuntimeException && in_array($error->getCode(), [401, 422, 429, 502, 503, 504], true)) {
                $code = $error->getCode();
                $message = mb_substr($error->getMessage(), 0, 1000);
            }
            $this->failRun($runId, $actor, $code, $message);
            throw new RuntimeException($message, $code, $error);
        }
        return $this->store->transaction(function (array &$state) use ($kind, $generated, $generation, $runId, $actor): array {
            $runIndex = $this->index($state['runs'], $runId);
            if ($runIndex === null || $state['runs'][$runIndex]['status'] !== 'RUNNING') {
                throw new RuntimeException('La generación ya fue reconciliada o necesita revisión.', 409);
            }
            $run = &$state['runs'][$runIndex];
            $now = $this->now();
            $run['updated_at'] = $now;
            $run['completed_at'] = $now;
            $run['generation'] = $generation;
            if ($state['paused'] || !empty($run['cancellation_requested']) || ($run['scheduled'] && !$state['settings']['schedule_enabled'])) {
                $run['status'] = 'DISCARDED';
                $run['error'] = 'La ejecución se pausó o desactivó durante la generación. No se creó una salida.';
                $this->clearConversationRun($state, $runId, 'PAUSED');
                $this->audit($state, $actor, 'generation_discarded', $runId, []);
                return ['run' => $run, 'discarded' => true];
            }
            $run['status'] = 'SUCCEEDED';
            if ($kind === 'content') {
                $draft = $this->newDraft($generated, $actor);
                $draft['generation_run_id'] = $runId;
                $draft['generation_context_hash'] = $run['context_hash'];
                $state['drafts'][] = $draft;
                $run['draft_id'] = $draft['id'];
                $this->audit($state, $actor, 'draft_generated', $draft['id'], ['run_id' => $runId]);
                return ['run' => $run, 'draft' => $draft];
            }
            $index = $this->index($state['conversations'], (string) $run['conversation_id']);
            if ($index === null || ($state['conversations'][$index]['pending_run_id'] ?? null) !== $runId) {
                throw new RuntimeException('La conversación cambió durante la generación; requiere revisión.', 409);
            }
            $conversation = &$state['conversations'][$index];
            $conversation['no_contact'] = $conversation['no_contact'] || $generated['decision'] === 'NO_CONTACT';
            $conversation['handoff'] = $conversation['handoff'] || $generated['decision'] === 'HUMAN_TAKEOVER';
            if ($conversation['no_contact']) {
                $generated = array_merge($generated, ['decision' => 'NO_CONTACT', 'classification' => 'NO_CONTACT', 'reply' => '', 'next_action' => 'Conservar la preferencia de no contactar. Seguimientos comerciales cancelados; no enviar respuesta automática.', 'lead_status' => 'CLOSED']);
            } elseif ($conversation['handoff']) {
                $generated = array_merge($generated, ['decision' => 'HUMAN_TAKEOVER', 'reply' => '', 'next_action' => 'Revisión y respuesta por una persona del equipo. Seguimientos automáticos cancelados.', 'lead_status' => 'HANDED_OFF']);
            }
            foreach ($generated as $field => $value) {
                $conversation[$field] = $value;
            }
            $conversation['pending_run_id'] = null;
            $conversation['status'] = $conversation['no_contact'] ? 'DO_NOT_CONTACT' : ($conversation['handoff'] || $conversation['decision'] === 'ESCALATE' ? 'NEEDS_HUMAN' : 'REVIEW_REPLY');
            $conversation['reply_status'] = 'DRAFT';
            $conversation['updated_at'] = $now;
            if ($conversation['reply'] !== '') {
                $conversation['messages'][] = ['id' => $this->id('message'), 'role' => 'assistant', 'text' => $conversation['reply'], 'source' => 'SIMULATED', 'status' => 'DRAFT', 'created_at' => $now, 'run_id' => $runId];
            }
            if ($conversation['no_contact'] || $conversation['handoff']) {
                $this->cancelFollowUps($state, $conversation['id'], $actor);
            }
            if (!$conversation['no_contact'] && $conversation['next_action'] !== '') {
                $task = $this->newTask(['title' => $conversation['next_action'], 'owner' => $actor, 'conversation_id' => $conversation['id'], 'type' => $conversation['decision'] === 'REPLY' ? 'FOLLOW_UP' : 'INTERNAL'], $actor);
                $state['tasks'][] = $task;
            }
            $this->audit($state, $actor, 'conversation_simulated', $conversation['id'], ['run_id' => $runId, 'decision' => $conversation['decision']]);
            return ['run' => $run, 'conversation' => $conversation];
        });
    }

    private function failRun(string $id, string $actor, int $code, string $message): void
    {
        $this->store->transaction(function (array &$state) use ($id, $actor, $code, $message): void {
            $index = $this->index($state['runs'], $id);
            if ($index !== null && $state['runs'][$index]['status'] === 'RUNNING') {
                $state['runs'][$index]['status'] = $code === 504 ? 'UNKNOWN' : 'FAILED';
                $state['runs'][$index]['error'] = $message;
                $state['runs'][$index]['error_code'] = $code;
                $state['runs'][$index]['updated_at'] = $this->now();
                $state['runs'][$index]['completed_at'] = $this->now();
                $this->clearConversationRun($state, $id, $code === 504 ? 'GENERATION_UNKNOWN' : 'GENERATION_FAILED');
                $this->audit($state, $actor, $code === 504 ? 'generation_unknown' : 'generation_failed', $id, []);
            }
        });
    }

    private function clearConversationRun(array &$state, string $runId, string $status): void
    {
        foreach ($state['conversations'] as &$conversation) {
            if (($conversation['pending_run_id'] ?? null) === $runId) {
                $conversation['pending_run_id'] = null;
                $conversation['status'] = $status;
                $conversation['updated_at'] = $this->now();
            }
        }
    }

    /** A dead PHP worker must not leave a permanent lock or trigger an automatic retry. */
    private function reconcileStaleRuns(): void
    {
        $deadline = time() - 120;
        $stale = static function (array $run) use ($deadline): bool {
            $started = strtotime((string) ($run['created_at'] ?? ''));
            return ($run['status'] ?? '') === 'RUNNING' && !empty($run['generation_reserved'])
                && $started !== false && $started < $deadline;
        };
        $state = $this->store->read();
        if (array_filter($state['runs'], $stale) === []) {
            return;
        }
        $this->store->transaction(function (array &$state) use ($stale): void {
            $expired = [];
            foreach ($state['runs'] as &$run) {
                if ($stale($run)) {
                    $run['status'] = 'UNKNOWN';
                    $run['error_code'] = 504;
                    $run['error'] = 'La ejecución dejó de responder y su resultado no está confirmado. No se ha reintentado automáticamente.';
                    $run['updated_at'] = $this->now();
                    $run['completed_at'] = $this->now();
                    $expired[] = $run['id'];
                }
            }
            unset($run);
            foreach ($expired as $runId) {
                $this->clearConversationRun($state, $runId, 'GENERATION_UNKNOWN');
                $this->audit($state, 'system', 'stale_generation_reconciled', $runId, ['status' => 'UNKNOWN']);
            }
        });
    }

    private function generationMetadata(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $safe = [];
        if (is_string($value['response_id'] ?? null) && preg_match('/^resp_[A-Za-z0-9_-]{1,190}$/D', $value['response_id'])) {
            $safe['response_id'] = $value['response_id'];
        }
        if (is_string($value['model'] ?? null) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value['model'])) {
            $safe['model'] = $value['model'];
        }
        if (is_array($value['usage'] ?? null)) {
            $usage = [];
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
                $number = $value['usage'][$key] ?? null;
                if (is_int($number) && $number >= 0 && $number <= 1000000000) {
                    $usage[$key] = $number;
                }
            }
            foreach (['input_tokens_details' => 'cached_tokens', 'output_tokens_details' => 'reasoning_tokens'] as $group => $key) {
                $number = is_array($value['usage'][$group] ?? null) ? ($value['usage'][$group][$key] ?? null) : null;
                if (is_int($number) && $number >= 0 && $number <= 1000000000) {
                    $usage[$group] = [$key => $number];
                }
            }
            $safe['usage'] = $usage === [] ? null : $usage;
        }
        return $safe === [] ? null : $safe;
    }

    private function replay(array $run): array
    {
        $state = $this->store->read();
        $result = ['run' => $run, 'duplicate' => true, 'pending' => $run['status'] === 'RUNNING'];
        if (in_array($run['status'], ['FAILED', 'UNKNOWN'], true)) {
            $result['ok'] = false;
            $result['error'] = $run['error'];
            $result['error_code'] = $run['error_code'] ?? null;
            $result['unknown'] = $run['status'] === 'UNKNOWN';
        }
        foreach (['draft_id' => 'drafts', 'conversation_id' => 'conversations'] as $key => $collection) {
            if (!empty($run[$key])) {
                $index = $this->index($state[$collection], $run[$key]);
                if ($index !== null) {
                    $result[$key === 'draft_id' ? 'draft' : 'conversation'] = $state[$collection][$index];
                }
            }
        }
        return $result;
    }

    private function saveDraft(array $input, string $actor): array
    {
        return $this->store->transaction(function (array &$state) use ($input, $actor): array {
            $id = $this->optionalId($input['id'] ?? null);
            if ($id === null) {
                $draft = $this->newDraft($this->contentFields($input, 'photo'), $actor);
                $draft['asset_url'] = $this->assetUrl($input['asset_url'] ?? '');
                $draft['asset_rights_confirmed'] = $this->boolean($input['asset_rights_confirmed'] ?? false, 'derechos de imagen');
                $draft['scheduled_at'] = $this->dateValue($input['scheduled_at'] ?? null);
                $draft['history'] = [$this->draftHistory($draft, $actor, 'created')];
                $state['drafts'][] = $draft;
            } else {
                $index = $this->requiredIndex($state['drafts'], $id, 'pieza');
                $draft = $state['drafts'][$index];
                $this->requireRevision($draft, $input);
                $fields = $this->contentFields(array_merge($draft, $input), $draft['format']);
                $candidate = array_merge($draft, $fields, [
                    'asset_url' => $this->assetUrl($input['asset_url'] ?? $draft['asset_url']),
                    'asset_rights_confirmed' => $this->boolean($input['asset_rights_confirmed'] ?? $draft['asset_rights_confirmed'], 'derechos de imagen'),
                    'scheduled_at' => $this->dateValue(array_key_exists('scheduled_at', $input) ? $input['scheduled_at'] : $draft['scheduled_at']),
                ]);
                if ($this->hash($this->draftMaterial($candidate)) === $this->hash($this->draftMaterial($draft))) {
                    return ['draft' => $draft, 'unchanged' => true];
                }
                $candidate['revision']++;
                $candidate['approval'] = null;
                $candidate['editorial_status'] = 'DRAFT';
                $candidate['execution_status'] = 'NOT_SCHEDULED';
                $candidate['updated_at'] = $this->now();
                $candidate['history'][] = $this->draftHistory($candidate, $actor, 'edited_approval_invalidated');
                $state['drafts'][$index] = $candidate;
                $draft = $candidate;
            }
            $this->audit($state, $actor, 'draft_saved', $draft['id'], ['revision' => $draft['revision']]);
            return ['draft' => $draft];
        });
    }

    private function reviewDraft(array $input, string $actor): array
    {
        $id = $this->text($input['id'] ?? '', 100, 'pieza', true);
        $decision = strtolower($this->text($input['decision'] ?? '', 20, 'decisión', true));
        if (!in_array($decision, ['approve', 'changes', 'reject'], true)) {
            throw new InvalidArgumentException('Decisión de revisión inválida.', 400);
        }
        $feedback = $this->text($input['feedback'] ?? '', 4000, 'comentarios');
        return $this->store->transaction(function (array &$state) use ($id, $input, $decision, $feedback, $actor): array {
            $index = $this->requiredIndex($state['drafts'], $id, 'pieza');
            $draft = &$state['drafts'][$index];
            $this->requireRevision($draft, $input);
            if ($decision === 'approve' && $draft['pending_facts'] !== []) {
                throw new RuntimeException('Resuelve los datos pendientes antes de aprobar esta versión.', 409);
            }
            if ($decision === 'approve' && $draft['asset_url'] !== '' && !$draft['asset_rights_confirmed']) {
                throw new RuntimeException('Confirma los derechos del recurso visual antes de aprobar.', 409);
            }
            $draft['editorial_status'] = ['approve' => 'APPROVED', 'changes' => 'CHANGES_REQUESTED', 'reject' => 'REJECTED'][$decision];
            $draft['approval'] = $decision === 'approve' ? [
                'id' => $this->id('approval'), 'draft_id' => $id, 'revision' => $draft['revision'],
                'content_hash' => $this->hash($this->draftMaterial($draft)), 'account' => 'somosperigallo',
                'mode' => 'SIMULATION', 'action' => 'simulate_publish', 'actor' => $actor, 'approved_at' => $this->now(),
            ] : null;
            $draft['updated_at'] = $this->now();
            $entry = $this->draftHistory($draft, $actor, 'review_' . $decision);
            $entry['feedback'] = $feedback;
            $draft['history'][] = $entry;
            $this->audit($state, $actor, 'draft_reviewed', $id, ['decision' => $decision, 'revision' => $draft['revision']]);
            return ['draft' => $draft];
        });
    }

    private function simulatePublish(array $input, string $actor): array
    {
        $id = $this->text($input['id'] ?? '', 100, 'pieza', true);
        return $this->store->transaction(function (array &$state) use ($id, $input, $actor): array {
            $index = $this->requiredIndex($state['drafts'], $id, 'pieza');
            $draft = &$state['drafts'][$index];
            $this->requireRevision($draft, $input);
            $hash = $this->hash($this->draftMaterial($draft));
            $key = 'simulation:' . $id . ':' . $draft['revision'] . ':' . $hash;
            foreach ($state['runs'] as $run) {
                if (($run['request_id'] ?? '') === $key && $run['status'] === 'SUCCEEDED') {
                    return ['draft' => $draft, 'run' => $run, 'duplicate' => true];
                }
            }
            $this->requireRunning($state);
            $approval = $draft['approval'];
            if ($draft['editorial_status'] !== 'APPROVED' || !is_array($approval)
                || ($approval['revision'] ?? null) !== $draft['revision'] || ($approval['draft_id'] ?? '') !== $id
                || ($approval['account'] ?? '') !== 'somosperigallo' || ($approval['mode'] ?? '') !== 'SIMULATION'
                || ($approval['action'] ?? '') !== 'simulate_publish' || !hash_equals($hash, (string) ($approval['content_hash'] ?? ''))) {
                throw new RuntimeException('La pieza necesita aprobación de esta versión para simular su publicación.', 409);
            }
            $now = $this->now();
            $run = [
                'id' => $this->id('run'), 'request_id' => $key, 'operation' => 'simulate_publish',
                'kind' => 'simulation', 'status' => 'SUCCEEDED', 'mode' => 'SIMULATION', 'generation_reserved' => false,
                'actor' => $actor, 'draft_id' => $id, 'revision' => $draft['revision'], 'content_hash' => $hash,
                'approval_id' => $approval['id'], 'created_at' => $now, 'updated_at' => $now, 'completed_at' => $now,
                'local_day' => $this->localNow()->format('Y-m-d'), 'external_action' => false,
            ];
            $draft['execution_status'] = 'SIMULATED';
            $draft['updated_at'] = $now;
            $state['runs'][] = $run;
            $this->audit($state, $actor, 'publication_simulated', $id, ['run_id' => $run['id'], 'revision' => $draft['revision']]);
            return ['draft' => $draft, 'run' => $run];
        });
    }

    private function saveTask(array $input, string $actor): array
    {
        return $this->store->transaction(function (array &$state) use ($input, $actor): array {
            $id = $this->optionalId($input['id'] ?? null);
            $index = $id !== null ? $this->requiredIndex($state['tasks'], $id, 'tarea') : null;
            $old = $index !== null ? $state['tasks'][$index] : [];
            if ($index !== null) {
                $this->requireRevision($old, $input);
            }
            $task = $this->newTask(array_merge($old, $input), $actor);
            if ($index !== null) {
                $task['id'] = $old['id'];
                $task['revision'] = $old['revision'] + 1;
                $task['created_at'] = $old['created_at'];
            }
            if ($task['conversation_id'] !== null) {
                $conversation = $state['conversations'][$this->requiredIndex($state['conversations'], $task['conversation_id'], 'conversación')];
                if ($task['type'] === 'FOLLOW_UP' && ($conversation['no_contact'] || $conversation['handoff']) && $task['status'] === 'OPEN') {
                    throw new RuntimeException('El seguimiento comercial está cancelado por la preferencia de contacto o la intervención humana.', 409);
                }
            }
            if ($index === null) {
                $state['tasks'][] = $task;
            } else {
                $state['tasks'][$index] = $task;
            }
            $this->audit($state, $actor, 'task_saved', $task['id'], ['status' => $task['status']]);
            return ['task' => $task];
        });
    }

    private function newTask(array $input, string $actor): array
    {
        $conversationId = $this->optionalId($input['conversation_id'] ?? null);
        $status = strtoupper($this->text($input['status'] ?? 'OPEN', 20, 'estado de tarea'));
        $type = strtoupper($this->text($input['type'] ?? ($conversationId !== null ? 'FOLLOW_UP' : 'INTERNAL'), 20, 'tipo de tarea'));
        if (!in_array($status, ['OPEN', 'DONE', 'CANCELLED'], true) || !in_array($type, ['INTERNAL', 'FOLLOW_UP'], true)) {
            throw new InvalidArgumentException('Estado o tipo de tarea inválido.', 400);
        }
        return [
            'id' => $this->id('task'), 'revision' => 1, 'source' => 'SIMULATED',
            'title' => $this->text($input['title'] ?? '', 1000, 'tarea', true),
            'owner' => $this->text($input['owner'] ?? $actor, 120, 'responsable', true),
            'notes' => $this->text($input['notes'] ?? '', 4000, 'notas'),
            'due_at' => $this->dateValue($input['due_at'] ?? null),
            'status' => $status, 'type' => $type, 'conversation_id' => $conversationId,
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ];
    }

    private function cancelFollowUps(array &$state, string $conversationId, string $actor): void
    {
        foreach ($state['tasks'] as &$task) {
            if (($task['conversation_id'] ?? null) === $conversationId && $task['status'] === 'OPEN' && ($task['type'] ?? 'FOLLOW_UP') === 'FOLLOW_UP') {
                $task['status'] = 'CANCELLED';
                $task['revision']++;
                $task['updated_at'] = $this->now();
                $task['cancellation_reason'] = 'No contactar o intervención humana.';
                $this->audit($state, $actor, 'follow_up_cancelled', $task['id'], ['conversation_id' => $conversationId]);
            }
        }
    }

    private function setPaused(bool $paused, string $actor): array
    {
        return $this->store->transaction(function (array &$state) use ($paused, $actor): array {
            $state['paused'] = $paused;
            if ($paused) {
                foreach ($state['runs'] as &$run) {
                    if (($run['status'] ?? '') === 'RUNNING') {
                        $run['cancellation_requested'] = true;
                    }
                }
                unset($run);
            }
            $this->audit($state, $actor, $paused ? 'paused' : 'resumed', null, []);
            return ['paused' => $paused];
        });
    }

    private function saveSettings(array $input, string $actor): array
    {
        if (isset($input['settings'])) {
            if (!is_array($input['settings'])) {
                throw new InvalidArgumentException('Configuración inválida.', 400);
            }
            $input = $input['settings'];
        }
        if (isset($input['timezone']) && $input['timezone'] !== 'Europe/Madrid') {
            throw new InvalidArgumentException('Este piloto utiliza la zona horaria Europe/Madrid.', 400);
        }
        return $this->store->transaction(function (array &$state) use ($input, $actor): array {
            $settings = $state['settings'];
            if (array_key_exists('brand_context', $input)) {
                $settings['brand_context'] = $this->text($input['brand_context'], 20000, 'contexto de marca');
            }
            if (array_key_exists('schedule_enabled', $input)) {
                $settings['schedule_enabled'] = $this->boolean($input['schedule_enabled'], 'programación');
            }
            if (array_key_exists('schedule_time', $input)) {
                if (!is_string($input['schedule_time']) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $input['schedule_time'])) {
                    throw new InvalidArgumentException('La hora debe tener formato HH:MM.', 400);
                }
                $settings['schedule_time'] = $input['schedule_time'];
            }
            if (array_key_exists('daily_generation_limit', $input)) {
                if (!is_int($input['daily_generation_limit']) || $input['daily_generation_limit'] < 0 || $input['daily_generation_limit'] > 50) {
                    throw new InvalidArgumentException('El límite diario debe ser un entero de 0 a 50.', 400);
                }
                $settings['daily_generation_limit'] = $input['daily_generation_limit'];
            }
            $state['settings'] = $settings;
            $this->audit($state, $actor, 'settings_saved', null, ['schedule_enabled' => $settings['schedule_enabled'], 'daily_generation_limit' => $settings['daily_generation_limit']]);
            return ['settings' => $settings];
        });
    }

    private function newDraft(array $fields, string $actor): array
    {
        $draft = array_merge($fields, [
            'id' => $this->id('draft'), 'revision' => 1, 'mode' => 'SIMULATION', 'account' => 'somosperigallo',
            'source' => 'SIMULATED', 'asset_url' => '', 'asset_rights_confirmed' => false, 'scheduled_at' => null,
            'editorial_status' => $fields['pending_facts'] === [] ? 'READY' : 'DRAFT',
            'execution_status' => 'NOT_SCHEDULED', 'approval' => null, 'history' => [],
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
        $draft['history'][] = $this->draftHistory($draft, $actor, 'created');
        return $draft;
    }

    private function contentFields(array $input, string $defaultFormat): array
    {
        $slides = $input['slides'] ?? [];
        if (!is_array($slides) || !array_is_list($slides) || count($slides) > 20) {
            throw new InvalidArgumentException('Las láminas deben ser una lista de hasta veinte elementos.', 400);
        }
        $cleanSlides = [];
        foreach ($slides as $slide) {
            if (!is_array($slide)) {
                throw new InvalidArgumentException('Lámina inválida.', 400);
            }
            $cleanSlides[] = [
                'title' => $this->text($slide['title'] ?? '', 240, 'título de lámina'),
                'body' => $this->text($slide['body'] ?? '', 2200, 'texto de lámina'),
                'visual_brief' => $this->text($slide['visual_brief'] ?? '', 3000, 'propuesta visual de lámina'),
            ];
        }
        return [
            'title' => $this->text($input['title'] ?? '', 180, 'título', true),
            'format' => $this->format($input['format'] ?? $defaultFormat),
            'objective' => $this->text($input['objective'] ?? '', 1000, 'objetivo'),
            'caption' => $this->text($input['caption'] ?? '', 2200, 'texto de publicación', true),
            'visual_brief' => $this->text($input['visual_brief'] ?? '', 6000, 'propuesta visual'),
            'slides' => $cleanSlides,
            'alt_text' => $this->text($input['alt_text'] ?? '', 1500, 'texto alternativo'),
            'cta' => $this->text($input['cta'] ?? '', 500, 'llamada a la acción'),
            'pending_facts' => $this->stringList($input['pending_facts'] ?? [], 30, 500),
        ];
    }

    private function conversationFields(array $input): array
    {
        $decision = strtoupper($this->text($input['decision'] ?? '', 40, 'decisión', true));
        if (!in_array($decision, ['REPLY', 'ESCALATE', 'NO_CONTACT', 'HUMAN_TAKEOVER'], true)) {
            throw new InvalidArgumentException('Decisión de conversación inválida.', 400);
        }
        return [
            'classification' => $this->text($input['classification'] ?? '', 100, 'clasificación', true),
            'reply' => $this->text($input['reply'] ?? '', 4000, 'respuesta'),
            'decision' => $decision,
            'next_action' => $this->text($input['next_action'] ?? '', 1000, 'siguiente acción'),
            'lead_status' => $this->text($input['lead_status'] ?? 'NEW', 100, 'estado comercial'),
            'pending_facts' => $this->stringList($input['pending_facts'] ?? [], 30, 500),
        ];
    }

    private function draftMaterial(array $draft): array
    {
        $keys = ['id', 'revision', 'account', 'mode', 'title', 'format', 'objective', 'caption', 'visual_brief', 'slides', 'alt_text', 'cta', 'pending_facts', 'asset_url', 'asset_rights_confirmed', 'scheduled_at'];
        $material = [];
        foreach ($keys as $key) {
            $material[$key] = $draft[$key] ?? null;
        }
        return $material;
    }

    private function draftHistory(array $draft, string $actor, string $action): array
    {
        return ['revision' => $draft['revision'], 'actor' => $actor, 'action' => $action, 'at' => $this->now(), 'editorial_status' => $draft['editorial_status'], 'content_hash' => $this->hash($this->draftMaterial($draft)), 'content' => $this->draftMaterial($draft)];
    }

    private function makeReport(array $state, int $days = 7): array
    {
        $now = $this->localNow();
        $from = $now->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
        $fromTimestamp = $from->getTimestamp();
        $until = $now->getTimestamp();
        $inPeriod = static function (array $record) use ($fromTimestamp, $until): bool {
            $time = strtotime((string) ($record['created_at'] ?? ''));
            return $time !== false && $time >= $fromTimestamp && $time <= $until;
        };
        $runs = array_values(array_filter($state['runs'], $inPeriod));
        $drafts = array_values(array_filter($state['drafts'], $inPeriod));
        $conversations = array_values(array_filter($state['conversations'], $inPeriod));
        $counts = [
            'drafts' => count($drafts),
            'approved' => count(array_filter($drafts, static fn(array $d): bool => $d['editorial_status'] === 'APPROVED')),
            'simulated_publications' => count(array_filter($runs, static fn(array $r): bool => ($r['operation'] ?? '') === 'simulate_publish' && $r['status'] === 'SUCCEEDED')),
            'conversations' => count($conversations),
            'escalations' => count(array_filter($conversations, static fn(array $c): bool => in_array($c['decision'], ['ESCALATE', 'HUMAN_TAKEOVER'], true))),
            'open_tasks' => count(array_filter($state['tasks'], static fn(array $t): bool => $t['status'] === 'OPEN')),
            'runs' => count($runs),
            'failed_runs' => count(array_filter($runs, static fn(array $r): bool => $r['status'] === 'FAILED')),
            'unknown_runs' => count(array_filter($runs, static fn(array $r): bool => $r['status'] === 'UNKNOWN')),
        ];
        return [
            'mode' => 'SIMULATION', 'classification' => 'ACTUAL_PANEL_ACTIVITY', 'generated_at' => $this->now(),
            'period' => ['from' => $from->format(DATE_ATOM), 'to' => $now->format(DATE_ATOM), 'days' => $days, 'timezone' => 'Europe/Madrid'],
            'counts' => $counts,
            'totals' => ['drafts' => count($state['drafts']), 'conversations' => count($state['conversations']), 'tasks' => count($state['tasks']), 'runs' => count($state['runs'])],
            'instagram' => ['connected' => false, 'reach' => null, 'views' => null, 'followers' => null, 'bookings' => null, 'revenue' => null, 'label' => 'N/D — sin conexión de métricas reales de Instagram'],
            'summary' => $counts['drafts'] . ' propuestas creadas en el periodo; ' . $counts['approved'] . ' actualmente aprobadas; ' . $counts['simulated_publications'] . ' publicaciones simuladas. ' . $counts['open_tasks'] . ' tareas abiertas en total. Ninguna publicación ni mensaje real enviado.',
            'definitions' => ['drafts' => 'Piezas creadas en el periodo, con su estado editorial actual.', 'conversations' => 'Conversaciones ficticias creadas en el periodo, con su decisión actual.', 'open_tasks' => 'Todas las tareas abiertas en este momento, aunque se crearan antes del periodo.', 'runs' => 'Ejecuciones registradas en el periodo; incluye fallos y simulaciones. No son interacciones reales en Instagram.'],
        ];
    }

    private function reportDays(array $input): int
    {
        $period = $input['period'] ?? 'week';
        return match ($period) { 'day', 'daily' => 1, 'week', 'weekly' => 7, 'month', 'monthly' => 30, default => throw new InvalidArgumentException('Periodo de informe inválido.', 400) };
    }

    private function text(mixed $value, int $maximum, string $label, bool $required = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Formato inválido: ' . $label . '.', 400);
        }
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value) > $maximum || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Revisa ' . $label . ' (máximo ' . $maximum . ' caracteres).', 400);
        }
        return $value;
    }

    private function stringList(mixed $value, int $maxItems, int $maxLength): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
            throw new InvalidArgumentException('Lista de datos pendientes inválida.', 400);
        }
        return array_values(array_filter(array_map(fn(mixed $item): string => $this->text($item, $maxLength, 'dato pendiente'), $value), static fn(string $item): bool => $item !== ''));
    }

    private function boolean(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Valor inválido: ' . $label . '.', 400);
        }
        return $value;
    }

    private function format(mixed $value): string
    {
        $format = strtolower($this->text($value, 30, 'formato', true));
        $format = $format === 'image' ? 'photo' : $format;
        if (!in_array($format, ['photo', 'carousel', 'reel', 'story'], true)) {
            throw new InvalidArgumentException('Formato de contenido no compatible.', 400);
        }
        return $format;
    }

    private function optionalId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = $this->text($value, 120, 'identificador', true);
        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $value)) {
            throw new InvalidArgumentException('Identificador inválido.', 400);
        }
        return $value;
    }

    private function requestId(mixed $value): string
    {
        $id = $this->optionalId($value);
        if ($id === null || strlen($id) < 8) {
            throw new InvalidArgumentException('Se requiere un request_id único de 8 a 120 caracteres.', 400);
        }
        return $id;
    }

    private function assetUrl(mixed $value): string
    {
        $url = $this->text($value, 2000, 'ruta de imagen');
        if ($url === '') {
            return '';
        }
        if (preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new InvalidArgumentException('Ruta de recurso no permitida.', 400);
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Ruta de recurso no permitida.', 400);
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !isset($parts['host']) && !isset($parts['scheme'])) {
            return $url;
        }
        if (($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 'perigallo.com' || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new InvalidArgumentException('Utiliza una ruta del sitio o una URL HTTPS de perigallo.com.', 400);
        }
        return $url;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = $this->text($value, 80, 'fecha');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/', $value, $matches)
            || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new InvalidArgumentException('Fecha inválida; utiliza formato ISO.', 400);
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('Europe/Madrid'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                throw new RuntimeException('Fecha inválida.');
            }
            return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (Throwable $error) {
            throw new InvalidArgumentException('Fecha inválida.', 400, $error);
        }
    }

    private function requireRevision(array $record, array $input): void
    {
        if (!is_int($input['revision'] ?? null) || $input['revision'] !== $record['revision']) {
            throw new RuntimeException('La versión ha cambiado. Recarga y revisa antes de guardar.', 409);
        }
    }

    private function requiredIndex(array $records, string $id, string $label): int
    {
        $index = $this->index($records, $id);
        if ($index === null) {
            throw new InvalidArgumentException('No existe la ' . $label . '.', 404);
        }
        return $index;
    }

    private function index(array $records, string $id): ?int
    {
        foreach ($records as $index => $record) {
            if (($record['id'] ?? '') === $id) {
                return $index;
            }
        }
        return null;
    }

    private function requireRunning(array $state): void
    {
        if ($state['paused']) {
            throw new RuntimeException('El agente está en pausa. Puedes editar y revisar; reanuda para generar o simular salidas.', 423);
        }
    }

    private function detectNoContact(string $message): bool
    {
        return (bool) preg_match('/\b(no\s+(?:me\s+)?(?:escrib[aá]is|escriban|escribas|contact[eé]is|contacten|contactes|contactar)|dejad?\s+de\s+escrib|no\s+quiero\s+(?:m[aá]s\s+)?(?:mensajes|publicidad)|darme\s+de\s+baja|dame\s+de\s+baja|unsubscribe|stop)\b/iu', $message);
    }

    private function detectHandoff(string $message): bool
    {
        return (bool) preg_match('/(?:hablar|contactar|pasadme|p[aá]same).{0,35}(?:persona|humano|responsable)|(?:human\s+agent|human\s+takeover)/iu', $message);
    }

    private function audit(array &$state, string $actor, string $action, ?string $entityId, array $details): void
    {
        $state['audit'][] = ['id' => $this->id('audit'), 'actor' => $actor, 'action' => $action, 'entity_id' => $entityId, 'details' => $details, 'mode' => 'SIMULATION', 'created_at' => $this->now()];
    }

    private function id(string $prefix): string { return $prefix . '_' . bin2hex(random_bytes(12)); }
    private function hash(mixed $value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
    private function now(): string { return gmdate('c'); }
    private function localNow(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')); }
}
