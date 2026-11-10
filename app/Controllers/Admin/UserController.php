<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use PDO;
use Throwable;

final class UserController
{
    public function index(): void
    {
        requireAdmin();
        $pdo = Database::connection();
        $search = trim((string) ($_GET['q'] ?? ''));
        $status = (string) ($_GET['status'] ?? '');
        $role = (string) ($_GET['role'] ?? '');
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(u.name LIKE :q_name OR u.email LIKE :q_email OR CAST(u.id AS CHAR) = :q_id)';
            $params['q_name'] = '%' . $search . '%';
            $params['q_email'] = '%' . $search . '%';
            $params['q_id'] = $search;
        }
        if (in_array($status, ['active', 'suspended', 'pending'], true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }
        if (in_array($role, ['admin', 'customer'], true)) {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }
        $sql = "SELECT u.id,u.name,u.email,u.role,u.status,u.last_login_at,u.created_at,
                    (SELECT COUNT(*) FROM websites w WHERE w.user_id=u.id) AS website_count,
                    (SELECT COUNT(*) FROM subscriptions s WHERE s.user_id=u.id) AS subscription_count,
                    (SELECT p.name FROM subscriptions s INNER JOIN plans p ON p.id=s.plan_id
                     WHERE s.user_id=u.id AND s.status='active' ORDER BY s.id DESC LIMIT 1) AS active_plan
                FROM users u";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY u.id DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $counts = $pdo->query("SELECT COUNT(*) total, SUM(status='active') active, SUM(status='suspended') suspended, SUM(role='admin' AND status='active') admins FROM users")->fetch(PDO::FETCH_ASSOC) ?: [];
        require BASE_PATH . '/resources/views/admin/users.php';
    }

    public function store(): void
    {
        requireAdmin();
        $name = trim((string)($_POST['name'] ?? ''));
        $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'customer');
        $status = (string)($_POST['status'] ?? 'active');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !$email ||
            strlen($password) < 12 || !in_array($role, ['admin','customer'], true) ||
            !in_array($status, ['active','suspended','pending'], true)) {
            $_SESSION['error'] = 'Kullanıcı bilgilerini kontrol edin. Şifre en az 12 karakter olmalıdır.';
            redirect('/admin/users');
        }
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO users (name,email,password,role,status,created_at) VALUES (:name,:email,:password,:role,:status,NOW())'
            );
            $stmt->execute(['name'=>$name,'email'=>$email,'password'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role,'status'=>$status]);
            $_SESSION['success'] = 'Kullanıcı oluşturuldu.';
        } catch (Throwable $e) {
            error_log('[AdGuard Admin] User creation failed: ' . $e::class);
            $_SESSION['error'] = 'Kullanıcı oluşturulamadı. E-posta adresi daha önce kullanılmış olabilir.';
        }
        redirect('/admin/users');
    }

    public function update(): void
    {
        requireAdmin();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $role = (string)($_POST['role'] ?? '');
        $status = (string)($_POST['status'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if ($id < 1 || mb_strlen($name) < 2 || mb_strlen($name) > 120 || !$email ||
            !in_array($role, ['admin','customer'], true) ||
            !in_array($status, ['active','suspended','pending'], true) ||
            ($password !== '' && strlen($password) < 12)) {
            $_SESSION['error'] = 'Kullanıcı bilgilerini kontrol edin. Yeni şifre en az 12 karakter olmalıdır.';
            redirect('/admin/users');
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id,role,status FROM users WHERE id=:id FOR UPDATE');
            $lock->execute(['id'=>$id]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new \RuntimeException('Kullanıcı bulunamadı.');
            $isSelf = $id === (int)(auth()['id'] ?? 0);
            if ($isSelf && ($role !== 'admin' || $status !== 'active')) {
                throw new \RuntimeException('Kendi yönetici hesabınızın rolünü veya durumunu değiştiremezsiniz.');
            }
            if ($current['role'] === 'admin' && $current['status'] === 'active' &&
                ($role !== 'admin' || $status !== 'active')) {
                $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active' FOR UPDATE")->fetchColumn();
                if ($admins <= 1) throw new \RuntimeException('Sistemde en az bir aktif yönetici bulunmalıdır.');
            }
            $sql = 'UPDATE users SET name=:name,email=:email,role=:role,status=:status';
            $params = ['name'=>$name,'email'=>$email,'role'=>$role,'status'=>$status,'id'=>$id];
            if ($password !== '') {
                $sql .= ',password=:password';
                $params['password'] = password_hash($password,PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id=:id';
            $pdo->prepare($sql)->execute($params);
            $pdo->commit();
            if ($isSelf) {
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;
            }
            $_SESSION['success'] = 'Kullanıcı bilgileri güncellendi.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[AdGuard Admin] User update failed: ' . $e::class);
            $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : 'Kullanıcı güncellenemedi. E-posta adresi başka bir hesapta kullanılıyor olabilir.';
        }
        redirect('/admin/users');
    }

    public function status(): void
    {
        requireAdmin();
        $id = (int)($_POST['id'] ?? 0);
        $newStatus = (string)($_POST['status'] ?? '');
        if ($id < 1 || !in_array($newStatus, ['active','suspended'], true)) {
            $_SESSION['error'] = 'Geçersiz kullanıcı işlemi.';
            redirect('/admin/users');
        }
        if ($id === (int)(auth()['id'] ?? 0) && $newStatus !== 'active') {
            $_SESSION['error'] = 'Kendi hesabınızı askıya alamazsınız.';
            redirect('/admin/users');
        }
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT role,status FROM users WHERE id=:id FOR UPDATE');
            $stmt->execute(['id'=>$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new \RuntimeException('Kullanıcı bulunamadı.');
            if ($user['role'] === 'admin' && $user['status'] === 'active' && $newStatus !== 'active') {
                $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active' FOR UPDATE")->fetchColumn();
                if ($admins <= 1) throw new \RuntimeException('Sistemde en az bir aktif yönetici bulunmalıdır.');
            }
            $pdo->prepare('UPDATE users SET status=:status WHERE id=:id')->execute(['status'=>$newStatus,'id'=>$id]);
            $pdo->commit();
            $_SESSION['success'] = $newStatus === 'active' ? 'Kullanıcı yeniden etkinleştirildi.' : 'Kullanıcı askıya alındı.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[AdGuard Admin] User status update failed: ' . $e::class);
            $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : 'Kullanıcı durumu değiştirilemedi.';
        }
        redirect('/admin/users');
    }
}
