<?php
declare(strict_types=1);

namespace App\\Controllers;

use App\\Core\\Database;
use PDOException;
use RuntimeException;
use Throwable;

final class AuthController
{
    public function showLogin(): void
    {
        require BASE_PATH . '/resources/views/auth/login.php';
    }

    public function login(): void
    {
        requireCsrf();

        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');

        if (!$email || $password === '') {
            $_SESSION['error'] = 'E-posta veya şifre hatalı.';
            redirect('/login');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, name, email, password, role, status
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password']) || $user['status'] !== 'active') {
            $_SESSION['error'] = 'E-posta veya şifre hatalı.';
            redirect('/login');
        }

        session_regenerate_id(true);
        unset($user['password']);
        $_SESSION['user'] = $user;

        $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $update->execute(['id' => $user['id']]);

        redirect('/dashboard');
    }

    public function showRegister(): void
    {
        require BASE_PATH . '/resources/views/auth/register.php';
    }

    public function register(): void
    {
        requireCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !$email
            || strlen($password) < 12 || $password !== $confirmation) {
            $_SESSION['error'] = 'Bilgileri kontrol edin. Şifre en az 12 karakter olmalıdır.';
            redirect('/register');
        }

        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $insert = $pdo->prepare(
                "INSERT INTO users (name, email, password, role, status, created_at)
                 VALUES (:name, :email, :password, 'customer', 'active', NOW())"
            );
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT)
            ]);
            $userId = (int) $pdo->lastInsertId();

            $plan = $pdo->prepare(
                "SELECT id FROM plans WHERE slug = 'deneme' AND is_active = 1 LIMIT 1"
            );
            $plan->execute();
            $planId = $plan->fetchColumn();

            if (!$planId) {
                throw new RuntimeException('Deneme paketi bulunamadı. Önce seeders.sql dosyasını çalıştırın.');
            }

            $subscription = $pdo->prepare(
                "INSERT INTO subscriptions (user_id, plan_id, status, starts_at, ends_at, created_at)
                 VALUES (:user_id, :plan_id, 'active', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())"
            );
            $subscription->execute([
                'user_id' => $userId,
                'plan_id' => (int) $planId
            ]);

            $pdo->commit();
            $_SESSION['success'] = 'Hesabınız oluşturuldu. 7 günlük deneme paketiniz aktif. Giriş yapabilirsiniz.';
            redirect('/login');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $_SESSION['error'] = 'Bu e-posta adresi zaten kayıtlı.';
            } else {
                error_log('[AdGuard Registration] ' . $exception->getMessage());
                $_SESSION['error'] = 'Kayıt işlemi tamamlanamadı. Lütfen tekrar deneyin.';
            }
            redirect('/register');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[AdGuard Registration] ' . $exception->getMessage());
            $_SESSION['error'] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Kayıt işlemi tamamlanamadı.';
            redirect('/register');
        }
    }

    public function logout(): void
    {
        requireCsrf();

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]);
        }
        session_destroy();
        redirect('/login');
    }
}
