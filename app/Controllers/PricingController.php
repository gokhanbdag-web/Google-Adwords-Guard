<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use PDO;

final class PricingController
{
    public function index(): void
    {
        $stmt = Database::connection()->query(
            "SELECT id, name, description, price, currency, billing_period,
                    daily_visit_limit, website_limit, features
             FROM plans WHERE is_active = 1 ORDER BY sort_order, price"
        );
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($plans as &$plan) {
            $features = json_decode((string) ($plan['features'] ?? '[]'), true);
            $plan['features'] = is_array($features) ? $features : [];
        }
        unset($plan);
        require BASE_PATH . '/resources/views/frontend/pricing.php';
    }
}
