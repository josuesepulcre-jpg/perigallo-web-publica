<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/** Server-side discount rules. Amounts are always stored and calculated in cents. */
final class DiscountCodes
{
    public function __construct(private PDO $pdo)
    {
    }

    public function quote(string $rawCode, int $eventId, array $items, string $email = '', string $phone = '', bool $forUpdate = false): array
    {
        $code = $this->normalize($rawCode);
        if ($code === '') {
            return $this->emptyQuote($this->subtotal($items));
        }

        $sql = 'SELECT * FROM discount_codes WHERE code_normalized = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$code]);
        $discount = $statement->fetch();
        if (!$discount) {
            throw new InvalidArgumentException('Este código no existe. Revísalo e inténtalo de nuevo.');
        }
        $this->assertAvailable($discount, $eventId, $items, $email, $phone, $forUpdate);

        $eligible = $this->eligibleItems($discount, $items);
        $eligibleSubtotal = $this->subtotal($eligible);
        if ($eligibleSubtotal <= 0) {
            throw new InvalidArgumentException('Este código no se puede aplicar a las entradas seleccionadas.');
        }
        $eligibleQuantity = array_sum(array_map(static fn(array $item): int => (int) $item['quantity'], $eligible));
        $discountCents = (string) $discount['discount_type'] === 'percent'
            ? (int) floor($eligibleSubtotal * (int) $discount['percent_basis_points'] / 10000)
            : min(
                $eligibleSubtotal,
                (int) $discount['fixed_amount_cents'] * ((string) $discount['application_scope'] === 'per_ticket' ? $eligibleQuantity : 1)
            );
        if ($discount['maximum_discount_cents'] !== null) {
            $discountCents = min($discountCents, (int) $discount['maximum_discount_cents']);
        }
        $discountCents = max(0, min($discountCents, $eligibleSubtotal));
        if ($discountCents <= 0) {
            throw new InvalidArgumentException('Este código no genera descuento para las entradas seleccionadas.');
        }

        $subtotal = $this->subtotal($items);
        return [
            'applied' => true,
            'code_id' => (int) $discount['id'],
            'code' => (string) $discount['code'],
            'type' => (string) $discount['discount_type'],
            'value_label' => $this->valueLabel($discount),
            'subtotal_cents' => $subtotal,
            'eligible_subtotal_cents' => $eligibleSubtotal,
            'discount_cents' => $discountCents,
            'total_cents' => max(0, $subtotal - $discountCents),
            'message' => 'Código aplicado: ' . (string) $discount['code'] . '.',
            'snapshot' => [
                'code_id' => (int) $discount['id'],
                'code' => (string) $discount['code'],
                'type' => (string) $discount['discount_type'],
                'percent_basis_points' => $discount['percent_basis_points'] === null ? null : (int) $discount['percent_basis_points'],
                'fixed_amount_cents' => $discount['fixed_amount_cents'] === null ? null : (int) $discount['fixed_amount_cents'],
                'maximum_discount_cents' => $discount['maximum_discount_cents'] === null ? null : (int) $discount['maximum_discount_cents'],
                'application_scope' => (string) $discount['application_scope'],
                'eligible_ticket_type_ids' => array_map(static fn(array $item): int => (int) $item['ticket_type_id'], $eligible),
                'eligible_ticket_quantity' => $eligibleQuantity,
                'eligible_subtotal_cents' => $eligibleSubtotal,
            ],
        ];
    }

    public function reserve(array $quote, int $orderId, int $eventId, string $email, string $phone, string $expiresAt): void
    {
        if (empty($quote['applied'])) {
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO discount_code_usages
             (discount_code_id, order_id, event_id, customer_email, customer_phone, subtotal_cents, discount_cents, total_cents, status, reservation_expires_at, reserved_at, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "reserved", ?, NOW(), ?, NOW(), NOW())'
        )->execute([
            $quote['code_id'], $orderId, $eventId, $email ?: null, $phone ?: null,
            $quote['subtotal_cents'], $quote['discount_cents'], $quote['total_cents'], $expiresAt,
            json_encode($quote['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function consumeForOrder(int $orderId): void
    {
        $this->pdo->prepare(
            'UPDATE discount_code_usages SET status = "consumed", consumed_at = NOW(), updated_at = NOW()
             WHERE order_id = ? AND status = "reserved"'
        )->execute([$orderId]);
        $this->pdo->prepare('UPDATE ticket_orders SET discount_consumed_at = NOW() WHERE id = ? AND discount_code_id IS NOT NULL AND discount_consumed_at IS NULL')
            ->execute([$orderId]);
    }

    public function releaseForOrder(int $orderId, string $status): void
    {
        if (!in_array($status, ['cancelled', 'refunded'], true)) {
            return;
        }
        $this->pdo->prepare(
            'UPDATE discount_code_usages SET status = ?, cancelled_at = NOW(), updated_at = NOW()
             WHERE order_id = ? AND status IN ("reserved", "consumed")'
        )->execute([$status, $orderId]);
    }

    public function validatePublic(array $data, array $event, array $items): array
    {
        return $this->quote(
            (string) ($data['discount_code'] ?? ''),
            (int) $event['id'],
            $items,
            clean_string((string) ($data['email'] ?? ''), 190),
            clean_string((string) ($data['phone'] ?? ''), 60)
        );
    }

    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        $state = (string) ($filters['state'] ?? 'active');
        if ($state === 'active') $where[] = 'dc.is_active = 1 AND dc.is_archived = 0';
        if ($state === 'inactive') $where[] = 'dc.is_active = 0 AND dc.is_archived = 0';
        if ($state === 'archived') $where[] = 'dc.is_archived = 1';
        if (!empty($filters['event_id'])) {
            $where[] = '(dc.event_scope = "all" OR (dc.event_scope = "included" AND EXISTS (SELECT 1 FROM discount_code_events dce WHERE dce.discount_code_id = dc.id AND dce.event_id = ?)) OR (dc.event_scope = "excluded" AND NOT EXISTS (SELECT 1 FROM discount_code_events dce WHERE dce.discount_code_id = dc.id AND dce.event_id = ?)))';
            $params[] = (int) $filters['event_id'];
            $params[] = (int) $filters['event_id'];
        }
        if (!empty($filters['query'])) {
            $where[] = '(dc.code LIKE ? OR dc.internal_description LIKE ?)';
            $query = '%' . clean_string((string) $filters['query'], 64) . '%';
            $params[] = $query;
            $params[] = $query;
        }
        $sql = 'SELECT dc.*, COALESCE(usage_stats.total_uses, 0) AS total_uses, COALESCE(usage_stats.consumed_uses, 0) AS consumed_uses,
                  COALESCE(event_names.event_names, "") AS event_names
                FROM discount_codes dc
                LEFT JOIN (SELECT discount_code_id, COUNT(*) AS total_uses, SUM(status = "consumed") AS consumed_uses FROM discount_code_usages GROUP BY discount_code_id) usage_stats ON usage_stats.discount_code_id = dc.id
                LEFT JOIN (SELECT dce.discount_code_id, GROUP_CONCAT(e.title ORDER BY e.starts_at SEPARATOR " · ") AS event_names FROM discount_code_events dce JOIN events e ON e.id = dce.event_id GROUP BY dce.discount_code_id) event_names ON event_names.discount_code_id = dc.id' .
                ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY dc.is_archived ASC, dc.created_at DESC, dc.id DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map([$this, 'adminPayload'], $statement->fetchAll());
    }

    public function get(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM discount_codes WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row) throw new RuntimeException('Código de descuento no encontrado.');
        return $this->adminPayload($row);
    }

    public function save(array $data, string $operator, ?int $id = null): array
    {
        $payload = $this->cleanAdminPayload($data);
        $this->assertCodeAvailableForSave((string) $payload['columns'][1], $id);
        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $statement = $this->pdo->prepare(
                    'INSERT INTO discount_codes (code, code_normalized, internal_description, discount_type, percent_basis_points, fixed_amount_cents, maximum_discount_cents, application_scope, event_scope, minimum_order_cents, minimum_ticket_quantity, maximum_discounted_ticket_quantity, maximum_total_uses, maximum_uses_per_customer, starts_at, expires_at, is_active, is_archived, is_combinable, created_by, updated_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NOW(), NOW())'
                );
                $statement->execute([...$payload['columns'], $operator, $operator]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $statement = $this->pdo->prepare(
                    'UPDATE discount_codes SET code = ?, code_normalized = ?, internal_description = ?, discount_type = ?, percent_basis_points = ?, fixed_amount_cents = ?, maximum_discount_cents = ?, application_scope = ?, event_scope = ?, minimum_order_cents = ?, minimum_ticket_quantity = ?, maximum_discounted_ticket_quantity = ?, maximum_total_uses = ?, maximum_uses_per_customer = ?, starts_at = ?, expires_at = ?, is_active = ?, is_combinable = ?, updated_by = ?, updated_at = NOW() WHERE id = ?'
                );
                $statement->execute([...$payload['columns'], $operator, $id]);
            }
            $this->replaceRelations('discount_code_events', 'event_id', $id, $payload['event_ids']);
            $this->replaceRelations('discount_code_ticket_types', 'ticket_type_id', $id, $payload['ticket_type_ids']);
            $this->pdo->commit();
            return $this->get($id);
        } catch (PDOException $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (str_contains($error->getMessage(), 'code_normalized') || str_contains($error->getMessage(), 'uq_discount_codes_normalized')) {
                throw new InvalidArgumentException('Ya existe un código con este nombre. Elige otro o edita el código existente.');
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function duplicate(int $id, string $operator): array
    {
        $source = $this->get($id);
        $base = $this->normalize((string) $source['code']) . '-COPIA';
        $candidate = $base;
        for ($suffix = 2; $suffix < 100; $suffix++) {
            if (!$this->codeExists($candidate)) break;
            $candidate = $base . '-' . $suffix;
        }
        $source['code'] = $candidate;
        $source['is_active'] = false;
        $source['starts_at'] = null;
        $source['expires_at'] = null;
        return $this->save($source, $operator);
    }

    public function archive(int $id, string $operator): array
    {
        $statement = $this->pdo->prepare('UPDATE discount_codes SET is_active = 0, is_archived = 1, updated_by = ?, updated_at = NOW() WHERE id = ?');
        $statement->execute([$operator, $id]);
        if ($statement->rowCount() === 0) throw new RuntimeException('Código de descuento no encontrado.');
        return $this->get($id);
    }

    public function deleteUnused(int $id): array
    {
        $this->pdo->beginTransaction();
        try {
            $discountStatement = $this->pdo->prepare('SELECT * FROM discount_codes WHERE id = ? LIMIT 1 FOR UPDATE');
            $discountStatement->execute([$id]);
            $discount = $discountStatement->fetch();
            if (!$discount) throw new RuntimeException('Código de descuento no encontrado.');
            $usageStatement = $this->pdo->prepare('SELECT COUNT(*) FROM discount_code_usages WHERE discount_code_id = ?');
            $usageStatement->execute([$id]);
            if ((int) $usageStatement->fetchColumn() > 0) {
                throw new InvalidArgumentException('Este código ya tiene usos asociados y debe archivarse para conservar el histórico.');
            }
            $this->pdo->prepare('DELETE FROM discount_code_events WHERE discount_code_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM discount_code_ticket_types WHERE discount_code_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM discount_codes WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
            return ['id' => $id, 'code' => (string) $discount['code']];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function usageHistory(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.*, o.name, o.email, o.phone, o.redsys_order, o.test_reference, e.title AS event_title
             FROM discount_code_usages u JOIN ticket_orders o ON o.id = u.order_id JOIN events e ON e.id = u.event_id
             WHERE u.discount_code_id = ? ORDER BY u.id DESC LIMIT 300'
        );
        $statement->execute([$id]);
        return $statement->fetchAll();
    }

    private function assertAvailable(array $discount, int $eventId, array $items, string $email, string $phone, bool $forUpdate): void
    {
        if (empty($discount['is_active']) || !empty($discount['is_archived'])) throw new InvalidArgumentException('Este código ya no está activo.');
        $now = date('Y-m-d H:i:s');
        if ($discount['starts_at'] && (string) $discount['starts_at'] > $now) throw new InvalidArgumentException('Este código todavía no está disponible.');
        if ($discount['expires_at'] && (string) $discount['expires_at'] < $now) throw new InvalidArgumentException('Este código ha caducado.');
        if (!$this->eventAllowed($discount, $eventId)) throw new InvalidArgumentException('Este código no es válido para esta experiencia.');
        $subtotal = $this->subtotal($items);
        $quantity = array_sum(array_map(static fn(array $item): int => (int) $item['quantity'], $items));
        if ($discount['minimum_order_cents'] !== null && $subtotal < (int) $discount['minimum_order_cents']) throw new InvalidArgumentException('Este código requiere un importe mínimo de compra.');
        if ($discount['minimum_ticket_quantity'] !== null && $quantity < (int) $discount['minimum_ticket_quantity']) throw new InvalidArgumentException('Este código requiere un número mínimo de entradas.');
        $this->assertUsageAvailability($discount, $email, $phone, $forUpdate);
    }

    private function assertUsageAvailability(array $discount, string $email, string $phone, bool $forUpdate): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM discount_code_usages u JOIN ticket_orders o ON o.id = u.order_id
             WHERE u.discount_code_id = ? AND (u.status = "consumed" OR (u.status = "reserved" AND o.reservation_expires_at > NOW() AND o.status IN ("pending", "payment_processing")))'
        );
        $statement->execute([(int) $discount['id']]);
        if ($discount['maximum_total_uses'] !== null && (int) $statement->fetchColumn() >= (int) $discount['maximum_total_uses']) throw new InvalidArgumentException('Este código ha alcanzado su límite de usos.');
        if ($discount['maximum_uses_per_customer'] === null || ($email === '' && $phone === '')) return;
        $where = [];
        $params = [(int) $discount['id']];
        if ($email !== '') { $where[] = 'u.customer_email = ?'; $params[] = mb_strtolower($email); }
        if ($phone !== '') { $where[] = 'u.customer_phone = ?'; $params[] = $phone; }
        $sql = 'SELECT COUNT(*) FROM discount_code_usages u JOIN ticket_orders o ON o.id = u.order_id WHERE u.discount_code_id = ? AND (' . implode(' OR ', $where) . ') AND (u.status = "consumed" OR (u.status = "reserved" AND o.reservation_expires_at > NOW() AND o.status IN ("pending", "payment_processing")))';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        if ((int) $statement->fetchColumn() >= (int) $discount['maximum_uses_per_customer']) throw new InvalidArgumentException('Este código ya se ha utilizado con estos datos de contacto.');
    }

    private function eventAllowed(array $discount, int $eventId): bool
    {
        $scope = (string) $discount['event_scope'];
        if ($scope === 'all') return true;
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM discount_code_events WHERE discount_code_id = ? AND event_id = ?');
        $statement->execute([(int) $discount['id'], $eventId]);
        $listed = (int) $statement->fetchColumn() > 0;
        return $scope === 'included' ? $listed : !$listed;
    }

    private function eligibleItems(array $discount, array $items): array
    {
        if ((string) $discount['application_scope'] !== 'ticket_types') return $this->limitDiscountedQuantity($items, $discount['maximum_discounted_ticket_quantity']);
        $statement = $this->pdo->prepare('SELECT ticket_type_id FROM discount_code_ticket_types WHERE discount_code_id = ?');
        $statement->execute([(int) $discount['id']]);
        $allowed = array_flip(array_map('intval', array_column($statement->fetchAll(), 'ticket_type_id')));
        if (!$allowed) throw new InvalidArgumentException('Este código todavía no tiene tipos de entrada configurados.');
        $eligible = array_values(array_filter($items, static fn(array $item): bool => isset($allowed[(int) $item['ticket_type_id']])));
        return $this->limitDiscountedQuantity($eligible, $discount['maximum_discounted_ticket_quantity']);
    }

    private function limitDiscountedQuantity(array $items, mixed $maximum): array
    {
        $remaining = $maximum === null ? PHP_INT_MAX : max(0, (int) $maximum);
        $result = [];
        foreach ($items as $item) {
            if ($remaining <= 0) break;
            $quantity = min((int) $item['quantity'], $remaining);
            if ($quantity <= 0) continue;
            $item['quantity'] = $quantity;
            $result[] = $item;
            $remaining -= $quantity;
        }
        return $result;
    }

    private function subtotal(array $items): int
    {
        return array_sum(array_map(static fn(array $item): int => (int) $item['quantity'] * (int) $item['unit_price_cents'], $items));
    }

    private function emptyQuote(int $subtotal): array
    {
        return ['applied' => false, 'subtotal_cents' => $subtotal, 'eligible_subtotal_cents' => 0, 'discount_cents' => 0, 'total_cents' => $subtotal, 'message' => '', 'snapshot' => null];
    }

    private function normalize(string $code): string
    {
        return mb_substr(preg_replace('/[^A-Z0-9_-]/', '', mb_strtoupper(trim($code))) ?? '', 0, 64);
    }

    private function valueLabel(array $discount): string
    {
        return (string) $discount['discount_type'] === 'percent'
            ? rtrim(rtrim(number_format((int) $discount['percent_basis_points'] / 100, 2, '.', ''), '0'), '.') . '%'
            : number_format((int) $discount['fixed_amount_cents'] / 100, 2, ',', '.') . ' €';
    }

    private function cleanAdminPayload(array $data): array
    {
        $code = $this->normalize((string) ($data['code'] ?? ''));
        if ($code === '' || strlen($code) < 3) throw new InvalidArgumentException('Indica un código de al menos 3 caracteres.');
        $type = (string) ($data['discount_type'] ?? 'percent');
        if (!in_array($type, ['percent', 'fixed'], true)) throw new InvalidArgumentException('Tipo de descuento no válido.');
        $percent = $type === 'percent' ? max(0, min(10000, (int) ($data['percent_basis_points'] ?? 0))) : null;
        $fixed = $type === 'fixed' ? max(1, (int) ($data['fixed_amount_cents'] ?? 0)) : null;
        if (($type === 'percent' && !$percent) || ($type === 'fixed' && !$fixed)) throw new InvalidArgumentException('Indica un valor de descuento válido.');
        if ($type === 'percent' && $percent < 100) throw new InvalidArgumentException('El descuento mínimo es del 1 %. Escribe, por ejemplo, 18 para aplicar un 18 %.');
        $scope = (string) ($data['application_scope'] ?? 'order');
        $eventScope = (string) ($data['event_scope'] ?? 'all');
        if (!in_array($scope, ['order', 'per_ticket', 'ticket_types'], true) || !in_array($eventScope, ['all', 'included', 'excluded'], true)) throw new InvalidArgumentException('La configuración de alcance no es válida.');
        $eventIds = $this->positiveIds($data['event_ids'] ?? []);
        $ticketTypeIds = $this->positiveIds($data['ticket_type_ids'] ?? []);
        if ($eventScope !== 'all' && !$eventIds) throw new InvalidArgumentException('Selecciona al menos una experiencia para este alcance.');
        if ($scope === 'ticket_types' && !$ticketTypeIds) throw new InvalidArgumentException('Selecciona al menos un tipo de entrada para este descuento.');
        $starts = $this->nullableDate($data['starts_at'] ?? null);
        $ends = $this->nullableDate($data['expires_at'] ?? null);
        if ($starts && $ends && $starts > $ends) throw new InvalidArgumentException('La fecha de fin debe ser posterior a la fecha de inicio.');
        $nullablePositive = static function (mixed $value): ?int { $number = (int) $value; return $number > 0 ? $number : null; };
        return [
            'columns' => [
                $code, $code, clean_string((string) ($data['internal_description'] ?? ''), 500) ?: null, $type, $percent, $fixed,
                $nullablePositive($data['maximum_discount_cents'] ?? null), $scope, $eventScope,
                $nullablePositive($data['minimum_order_cents'] ?? null), $nullablePositive($data['minimum_ticket_quantity'] ?? null),
                $nullablePositive($data['maximum_discounted_ticket_quantity'] ?? null), $nullablePositive($data['maximum_total_uses'] ?? null),
                $nullablePositive($data['maximum_uses_per_customer'] ?? null), $starts, $ends, !empty($data['is_active']) ? 1 : 0, !empty($data['is_combinable']) ? 1 : 0,
            ],
            'event_ids' => $eventIds,
            'ticket_type_ids' => $ticketTypeIds,
        ];
    }

    private function replaceRelations(string $table, string $column, int $discountId, array $ids): void
    {
        $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE discount_code_id = ?')->execute([$discountId]);
        if (!$ids) return;
        $statement = $this->pdo->prepare('INSERT INTO ' . $table . ' (discount_code_id, ' . $column . ') VALUES (?, ?)');
        foreach ($ids as $id) $statement->execute([$discountId, $id]);
    }

    private function adminPayload(array $row): array
    {
        $id = (int) $row['id'];
        $events = $this->pdo->prepare('SELECT event_id FROM discount_code_events WHERE discount_code_id = ? ORDER BY event_id');
        $events->execute([$id]);
        $types = $this->pdo->prepare('SELECT ticket_type_id FROM discount_code_ticket_types WHERE discount_code_id = ? ORDER BY ticket_type_id');
        $types->execute([$id]);
        $row['id'] = $id;
        $row['event_ids'] = array_map('intval', array_column($events->fetchAll(), 'event_id'));
        $row['ticket_type_ids'] = array_map('intval', array_column($types->fetchAll(), 'ticket_type_id'));
        return $row;
    }

    private function positiveIds(mixed $values): array
    {
        if (!is_array($values)) return [];
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0)));
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $timestamp = strtotime($value);
        if ($timestamp === false) throw new InvalidArgumentException('Indica una fecha válida.');
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function assertCodeAvailableForSave(string $normalizedCode, ?int $currentId): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM discount_codes WHERE code_normalized = ? LIMIT 1');
        $statement->execute([$normalizedCode]);
        $existingId = $statement->fetchColumn();
        if ($existingId !== false && (int) $existingId !== $currentId) {
            throw new InvalidArgumentException('Ya existe un código con este nombre. Elige otro o edita el código existente.');
        }
    }

    private function codeExists(string $code): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM discount_codes WHERE code_normalized = ?');
        $statement->execute([$this->normalize($code)]);
        return (int) $statement->fetchColumn() > 0;
    }
}
