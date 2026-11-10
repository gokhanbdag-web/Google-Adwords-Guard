<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use PDO;

final class SubscriptionController
{
    public function index(): void
    {
        requireAdmin();
        $pdo = Database::connection();
        $subscriptions = $pdo->query(
            "SELECT s.id, s.user_id, u.name AS user_name, u.email,
                    s.plan_id, p.name AS plan_name, s.status, s.starts_at,
                    s.ends_at, s.payment_provider, s.created_at
             FROM subscriptions s
             INNER JOIN users u ON u.id = s.user_id
             INNER JOIN plans p ON p.id = s.plan_id
             ORDER BY s.id DESC LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);
        $users = $pdo->query("SELECT id, name, email FROM users ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        $plans = $pdo->query("SELECT id, name, billing_period, price, currency FROM plans WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
        require BASE_PATH . '/resources/views/admin/subscriptions.php';
    }

    public function activate(): void
    {
        requireAdmin();
        $userId = (int) ($_POST['user_id'] ?? 0);
        $planId = (int) ($_POST['plan_id'] ?? 0);
        if ($userId <= 0 || $planId <= 0) {
            $_SESSION['error'] = 'Kullanıcı ve paket seçilmelidir.';
            redirect('/admin/subscriptions');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $plan = $pdo->prepare("SELECT id, billing_period FROM plans WHERE id = :id AND is_active = 1 LIMIT 1 FOR UPDATE");
            $plan->execute(['id' => $planId]);
            $planRow = $plan->fetch(PDO::FETCH_ASSOC);
            $user = $pdo->prepare("SELECT id FROM users WHERE id = :id AND status = 'active' LIMIT 1 FOR UPDATE");
            $user->execute(['id' => $userId]);
            if (!$planRow || !$user->fetchColumn()) {
                throw new \RuntimeException('Aktif kullanıcı veya paket bulunamadı.');
            }

            $cancel = $pdo->prepare(
                "UPDATE subscriptions SET status = 'expired', ends_at = NOW()
                 WHERE user_id = :user_id AND status = 'active'"
            );
            $cancel->execute(['user_id' => $userId]);

            $insert = $pdo->prepare(
                "INSERT INTO subscriptions
                 (user_id, plan_id, status, starts_at, ends_at, payment_provider)
                 VALUES (
                    :user_id, :plan_id, 'active', NOW(),
                    CASE WHEN :period = 'yearly'
                         THEN DATE_ADD(NOW(), INTERVAL 1 YEAR)
                         ELSE DATE_ADD(NOW(), INTERVAL 1 MONTH) END,
                    'manual'
                 )"
            );
            $insert->execute([
                'user_id' => $userId,
                'plan_id' => $planId,
                'period' => $planRow['billing_period']
            ]);
            $pdo->commit();
            $_SESSION['success'] = 'Abonelik manuel olarak etkinleştirildi.';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[AdGuard Admin] Subscription activation failed: ' . $e::class);
            $_SESSION['error'] = 'Abonelik etkinleştirilemedi. Kullanıcı ve paket bilgilerini kontrol edin.';
        }
        redirect('/admin/subscriptions');
    }

    public function cancel(): void
    {
        requireAdmin();
        $stmt = Database::connection()->prepare(
            "UPDATE subscriptions SET status = 'cancelled', ends_at = NOW()
             WHERE id = :id AND status = 'active'"
        );
        $stmt->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        $_SESSION['success'] = $stmt->rowCount() === 1 ? 'Abonelik iptal edildi.' : 'Aktif abonelik bulunamadı.';
        redirect('/admin/subscriptions');
    }
}
