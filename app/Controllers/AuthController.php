<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

final class AuthController
{
    public function showLogin(): void
    {
        if (auth()) {
            redirect(isAdmin() ? '/admin' : '/dashboard');
        }

        require BASE_PATH . '/resources/views/auth/login.php';
    }

    public function login(): void
    {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');

        if (!$email || $password === '') {
            $_SESSION['error'] = 'E-posta veya şifre hatalı.';
            redirect('/login');
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, name, email, password, role, status
             FROM users
             WHERE email = :email
             LIMIT 1'
        );

        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (
            !$user ||
            !password_verify($password, $user['password']) ||
            $user['status'] !== 'active'
        ) {
            $_SESSION['error'] = 'E-posta veya şifre hatalı.';
            redirect('/login');
        }

        session_regenerate_id(true);

        unset($user['password']);
        $_SESSION['user'] = $user;

        $update = Database::connection()->prepare(
            'UPDATE users SET last_login_at = NOW() WHERE id = :id'
        );
        $update->execute(['id' => $user['id']]);

        redirect('/dashboard');
    }

    public function showRegister(): void
    {
        require BASE_PATH . '/resources/views/auth/register.php';
    }

    public function register(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (
            mb_strlen($name) < 2 ||
            mb_strlen($name) > 120 ||
            !$email ||
            strlen($password) < 12 ||
            $password !== $confirmation
        ) {
            $_SESSION['error'] = 'Bilgileri kontrol edin. Şifre en az 12 karakter olmalıdır.';
            redirect('/register');
        }

        $pdo = Database::connection();

        $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $check->execute(['email' => $email]);

        if ($check->fetch()) {
            $_SESSION['error'] = 'Bu e-posta adresi zaten kayıtlı.';
            redirect('/register');
        }

        $insert = $pdo->prepare(
            "INSERT INTO users (name, email, password, role, status, created_at)
             VALUES (:name, :email, :password, 'customer', 'active', NOW())"
        );

        $insert->execute([
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $_SESSION['success'] = 'Hesabınız oluşturuldu. Giriş yapabilirsiniz.';
        redirect('/login');
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        redirect('/');
    }
}