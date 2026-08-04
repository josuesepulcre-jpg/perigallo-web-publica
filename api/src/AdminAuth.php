<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDOException;
use RuntimeException;

final class AdminAuth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // Aisla el panel de las cookies de sesiones heredadas (por ejemplo, WordPress).
        session_name('perigallo_ticketing_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function login(string $username, string $password): bool
    {
        self::start();
        $accounts = [
            ['username' => env_value('ADMIN_USERNAME'), 'hash' => env_value('ADMIN_PASSWORD_HASH'), 'role' => 'admin'],
            ['username' => env_value('ACCESS_USERNAME'), 'hash' => env_value('ACCESS_PASSWORD_HASH'), 'role' => 'control_acceso'],
        ];
        $account = null;
        foreach ($accounts as $candidate) {
            if (!$candidate['username'] || !$candidate['hash']) {
                continue;
            }
            if (hash_equals($candidate['username'], $username) && password_verify($password, $candidate['hash'])) {
                $account = $candidate;
                break;
            }
        }
        $isOwner = $account !== null && $account['role'] === 'admin' && hash_equals((string) env_value('ADMIN_USERNAME'), $account['username']);
        if ($account === null) {
            foreach (self::managedAccounts() as $candidate) {
                if (hash_equals($candidate['username'], $username) && password_verify($password, $candidate['password_hash'])) {
                    $account = ['username' => $candidate['username'], 'role' => $candidate['role'], 'id' => (int) $candidate['id']];
                    self::touchManagedAccount((int) $candidate['id']);
                    break;
                }
            }
        }
        if ($account === null) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['role'] = $account['role'];
        $_SESSION['operator'] = $account['username'];
        $_SESSION['is_owner'] = $isOwner;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function require(): void
    {
        self::start();
        if (!self::isAdmin()) {
            json_response(['ok' => false, 'error' => 'No autorizado.'], 401);
            exit;
        }
    }

    public static function requireAccess(): void
    {
        self::start();
        if (!self::isAuthenticated()) {
            json_response(['ok' => false, 'error' => 'No autorizado.'], 401);
            exit;
        }
    }

    public static function requireCsrf(): void
    {
        self::require();
        self::verifyCsrf();
    }

    public static function requireAccessCsrf(): void
    {
        self::requireAccess();
        self::verifyCsrf();
    }

    public static function requireOwner(): void
    {
        self::requireCsrf();
        self::assertOwner();
    }

    public static function requireOwnerSession(): void
    {
        self::require();
        self::assertOwner();
    }

    private static function assertOwner(): void
    {
        if (!self::isOwner()) {
            json_response(['ok' => false, 'error' => 'Esta acción está reservada para la cuenta propietaria.'], 403);
            exit;
        }
    }

    public static function operatorName(): string
    {
        self::start();
        return (string) ($_SESSION['operator'] ?? (self::isAdmin() ? 'admin' : 'control_acceso'));
    }

    private static function verifyCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$token || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            json_response(['ok' => false, 'error' => 'CSRF invalido.'], 419);
            exit;
        }
    }

    public static function sessionPayload(): array
    {
        self::start();
        return [
            'authenticated' => self::isAuthenticated(),
            'csrf' => $_SESSION['csrf'] ?? null,
            'role' => self::role(),
            'operator' => self::isAuthenticated() ? self::operatorName() : null,
            'can_scan' => self::isAuthenticated(),
            'can_revert' => self::isAdmin(),
            'is_owner' => self::isOwner(),
            'can_manage_users' => self::isOwner(),
            'can_purge_test_data' => self::isOwner(),
        ];
    }

    public static function listManagedUsers(): array
    {
        try {
            return Database::pdo()->query('SELECT id, username, role, is_active, created_at, updated_at, last_login_at FROM ticket_admin_users ORDER BY created_at ASC, id ASC')->fetchAll();
        } catch (PDOException $error) {
            throw new RuntimeException('La gestión de usuarios requiere ejecutar la migración 011 en la base de datos.');
        }
    }

    public static function createManagedUser(array $data): array
    {
        $username = self::validUsername((string) ($data['username'] ?? ''));
        $password = self::validPassword((string) ($data['password'] ?? ''));
        $role = self::validRole((string) ($data['role'] ?? 'control_acceso'));
        try {
            $statement = Database::pdo()->prepare('INSERT INTO ticket_admin_users (username, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())');
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
            $userId = (int) Database::pdo()->lastInsertId();
            self::audit('user_created', 'admin_user', $userId, ['username' => $username, 'role' => $role]);
            return self::managedUser($userId);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                throw new RuntimeException('Ya existe una cuenta con ese usuario.');
            }
            throw new RuntimeException('No se ha podido crear la cuenta. Ejecuta primero la migración 011.');
        }
    }

    public static function updateManagedUser(int $id, array $data): array
    {
        $username = self::validUsername((string) ($data['username'] ?? ''));
        $role = self::validRole((string) ($data['role'] ?? 'control_acceso'));
        $active = !empty($data['is_active']) ? 1 : 0;
        try {
            $statement = Database::pdo()->prepare('UPDATE ticket_admin_users SET username = ?, role = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
            $statement->execute([$username, $role, $active, $id]);
            if ($statement->rowCount() === 0 && self::managedUser($id) === []) {
                throw new RuntimeException('La cuenta no existe.');
            }
            self::audit('user_updated', 'admin_user', $id, ['username' => $username, 'role' => $role, 'is_active' => (bool) $active]);
            return self::managedUser($id);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                throw new RuntimeException('Ya existe una cuenta con ese usuario.');
            }
            throw new RuntimeException('No se ha podido actualizar la cuenta.');
        }
    }

    public static function updateManagedUserPassword(int $id, string $password): void
    {
        $password = self::validPassword($password);
        $statement = Database::pdo()->prepare('UPDATE ticket_admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
        $statement->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        if ($statement->rowCount() === 0 && self::managedUser($id) === []) {
            throw new RuntimeException('La cuenta no existe.');
        }
        self::audit('user_password_changed', 'admin_user', $id, []);
    }

    private static function isAuthenticated(): bool
    {
        return !empty($_SESSION['admin']);
    }

    private static function isAdmin(): bool
    {
        return self::isAuthenticated() && self::role() === 'admin';
    }

    private static function isOwner(): bool
    {
        return self::isAdmin() && !empty($_SESSION['is_owner']);
    }

    private static function managedAccounts(): array
    {
        try {
            return Database::pdo()->query('SELECT id, username, password_hash, role FROM ticket_admin_users WHERE is_active = 1')->fetchAll();
        } catch (PDOException $error) {
            // El acceso original por .env debe seguir disponible antes de aplicar la migración.
            return [];
        }
    }

    private static function touchManagedAccount(int $id): void
    {
        try {
            Database::pdo()->prepare('UPDATE ticket_admin_users SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
        } catch (PDOException $error) {
            // Nunca impide un inicio de sesión válido por un dato auxiliar.
        }
    }

    private static function managedUser(int $id): array
    {
        $statement = Database::pdo()->prepare('SELECT id, username, role, is_active, created_at, updated_at, last_login_at FROM ticket_admin_users WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        return $statement->fetch() ?: [];
    }

    private static function validUsername(string $username): string
    {
        $username = trim($username);
        if (!preg_match('/^[a-zA-Z0-9._-]{3,120}$/', $username)) {
            throw new RuntimeException('El usuario debe tener entre 3 y 120 caracteres: letras, números, punto, guion o guion bajo.');
        }
        return $username;
    }

    private static function validPassword(string $password): string
    {
        if (mb_strlen($password) < 12) {
            throw new RuntimeException('La contraseña debe tener al menos 12 caracteres.');
        }
        return $password;
    }

    private static function validRole(string $role): string
    {
        if (!in_array($role, ['admin', 'control_acceso'], true)) {
            throw new RuntimeException('El permiso seleccionado no es válido.');
        }
        return $role;
    }

    private static function audit(string $action, string $entityType, ?int $entityId, array $context): void
    {
        try {
            $statement = Database::pdo()->prepare('INSERT INTO ticket_admin_audit_logs (actor, action, entity_type, entity_id, context_json, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $statement->execute([self::operatorName(), $action, $entityType, $entityId, json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (PDOException $error) {
            // La auditoría requiere la misma migración; no debe dejar operaciones a medias.
        }
    }

    private static function role(): ?string
    {
        if (!self::isAuthenticated()) {
            return null;
        }
        // Las sesiones emitidas antes de esta mejora se conservan como administración.
        return (string) ($_SESSION['role'] ?? 'admin');
    }
}
