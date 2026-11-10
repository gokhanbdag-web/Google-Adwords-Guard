<?php
declare(strict_types=1);

use App\Core\Database;

function env(string $key, mixed $default = null): mixed
{
    return \App\Core\Env::get($key, $default);
}

function db(): PDO
{
    return Database::connection();
}

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function redirect(string $path, int $status = 302): never
{
    $url = str_starts_with($path, 'http')
        ? $path
        : rtrim((string) env('APP_URL', ''), '/') . '/' . ltrim($path, '/');

    header('Location: ' . $url, true, $status);
    exit;
}

function auth(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isAdmin(): bool
{
    return (auth()['role'] ?? null) === 'admin';
}

function requireAuth(): void
{
    if (!auth()) {
        redirect('/login');
    }
}

function requireAdmin(): void
{
    requireAuth();

    if (!isAdmin()) {
        http_response_code(403);
        exit('Bu işlem için yetkiniz bulunmuyor.');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' .
        e(\App\Core\Security::csrfToken()) . '">';
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireCsrf(): void
{
    $token = isset($_POST['_token']) ? (string) $_POST['_token'] : null;

    if (!\\App\\Core\\Security::verifyCsrf($token)) {
        http_response_code(419);
        exit('Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
    }
}
