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
        $expectedUser = env_value('ADMIN_USERNAME');
        $expectedHash = env_value('ADMIN_PASSWORD_HASH');
        if (!$expectedUser || !$expectedHash) {
            return false;
        }
        if (!hash_equals($expectedUser, $username)) {
            return false;
        }
        if (!password_verify($password, $expectedHash)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
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
        if (empty($_SESSION['admin'])) {
            json_response(['ok' => false, 'error' => 'No autorizado.'], 401);
            exit;
        }
    }

    public static function requireCsrf(): void
    {
        self::require();
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
            'authenticated' => !empty($_SESSION['admin']),
            'csrf' => $_SESSION['csrf'] ?? null,
        ];
    }
}
