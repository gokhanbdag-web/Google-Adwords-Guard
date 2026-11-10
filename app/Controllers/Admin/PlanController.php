<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use PDO;
use Throwable;

final class PlanController
{
    public function index(): void
    {
        requireAdmin();
        $pdo = Database::connection();
        $plans = $pdo->query('SELECT * FROM plans ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
        $providerMappings = $pdo->query("SELECT plan_id,provider_plan_reference FROM payment_provider_plans WHERE provider='iyzico' AND is_active=1")->fetchAll(PDO::FETCH_KEY_PAIR);
        require BASE_PATH . '/resources/views/admin/plans.php';
    }

    public function saveProviderMapping(): void
    {
        requireAdmin();
        $planId=(int)($_POST['plan_id']??0); $reference=trim((string)($_POST['provider_plan_reference']??''));
        if($planId<1||$reference===''||mb_strlen($reference)>190){$_SESSION['error']='iyzico plan referans kodu geçersiz.';redirect('/admin/plans');}
        $stmt=Database::connection()->prepare("INSERT INTO payment_provider_plans (plan_id,provider,provider_plan_reference,is_active)
            VALUES (:plan,'iyzico',:reference,1) ON DUPLICATE KEY UPDATE provider_plan_reference=VALUES(provider_plan_reference),is_active=1");
        $stmt->execute(['plan'=>$planId,'reference'=>$reference]); $_SESSION['success']='iyzico abonelik planı eşleştirildi.'; redirect('/admin/plans');
    }

    public function store(): void
    {
        requireAdmin();
        $data = $this->validated($_POST);
        $stmt = Database::connection()->prepare(
            "INSERT INTO plans
             (name, slug, description, price, currency, billing_period,
              daily_visit_limit, website_limit, features, is_active, sort_order)
             VALUES
             (:name, :slug, :description, :price, :currency, :period,
              :daily_limit, :site_limit, :features, :active, :sort_order)"
        );
        $stmt->execute($data);
        $_SESSION['success'] = 'Paket oluşturuldu.';
        redirect('/admin/plans');
    }

    public function update(): void
    {
        requireAdmin();
        $data = $this->validated($_POST);
        $data['id'] = (int) ($_POST['id'] ?? 0);
        if ($data['id'] <= 0) {
            http_response_code(400);
            exit('Geçersiz paket.');
        }
        $stmt = Database::connection()->prepare(
            "UPDATE plans SET name = :name, slug = :slug, description = :description,
             price = :price, currency = :currency, billing_period = :period,
             daily_visit_limit = :daily_limit, website_limit = :site_limit,
             features = :features, is_active = :active, sort_order = :sort_order
             WHERE id = :id"
        );
        $stmt->execute($data);
        $_SESSION['success'] = 'Paket güncellendi.';
        redirect('/admin/plans');
    }

    public function delete(): void
    {
        requireAdmin();
        try {
            $stmt = Database::connection()->prepare('DELETE FROM plans WHERE id = :id');
            $stmt->execute(['id' => (int) ($_POST['id'] ?? 0)]);
            $_SESSION['success'] = 'Paket silindi.';
        } catch (Throwable $e) {
            error_log('[AdGuard Admin] Plan deletion failed: ' . $e::class);
            $_SESSION['error'] = 'Paket silinemedi. Aboneliği bulunan paketler silinemez.';
        }
        redirect('/admin/plans');
    }

    private function validated(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $description = trim((string) ($input['description'] ?? ''));
        $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'TRY')));
        $period = (string) ($input['billing_period'] ?? 'monthly');
        $daily = filter_var($input['daily_visit_limit'] ?? null, FILTER_VALIDATE_INT);
        $sites = filter_var($input['website_limit'] ?? null, FILTER_VALIDATE_INT);
        $active = isset($input['is_active']) ? 1 : 0;
        $order = filter_var($input['sort_order'] ?? 0, FILTER_VALIDATE_INT);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 ||
            !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) ||
            mb_strlen($slug) > 140 || $price === false || $price < 0 ||
            !preg_match('/^[A-Z]{3}$/', $currency) ||
            !in_array($period, ['monthly', 'yearly'], true) ||
            $daily === false || $daily < 1 || $sites === false || $sites < 1 ||
            $order === false) {
            $_SESSION['error'] = 'Paket alanlarını kontrol edin.';
            redirect('/admin/plans');
        }

        $features = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', (string) ($input['features'] ?? '')) ?: []
        ), static fn(string $v): bool => $v !== ''));
        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'price' => number_format((float) $price, 2, '.', ''),
            'currency' => $currency,
            'period' => $period,
            'daily_limit' => $daily,
            'site_limit' => $sites,
            'features' => json_encode($features, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'active' => $active,
            'sort_order' => $order
        ];
    }
}
