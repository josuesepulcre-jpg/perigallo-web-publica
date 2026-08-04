<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

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
        if ($account === null) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['role'] = $account['role'];
        $_SESSION['operator'] = $account['username'];
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
        ];
    }

    private static function isAuthenticated(): bool
    {
        return !empty($_SESSION['admin']);
    }

    private static function isAdmin(): bool
    {
        return self::isAuthenticated() && self::role() === 'admin';
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
