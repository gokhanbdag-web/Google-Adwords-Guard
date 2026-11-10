<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

final class SiteController
{
    public function index(): void
    {
        requireAuth();

        $stmt = Database::connection()->prepare(
            'SELECT id, name, domain, site_key, status, created_at
             FROM websites
             WHERE user_id = :user_id
             ORDER BY id DESC'
        );

        $stmt->execute(['user_id' => auth()['id']]);
        $websites = $stmt->fetchAll();

        require BASE_PATH . '/resources/views/dashboard/sites.php';
    }

    public function create(): void
    {
        requireAuth();
        require BASE_PATH . '/resources/views/dashboard/site-create.php';
    }

    public function store(): void
    {
        requireAuth();
        requireCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = rtrim(explode('/', $domain)[0] ?? '', '.');

        if (
            mb_strlen($name) < 2 ||
            mb_strlen($name) > 120 ||
            strlen($domain) > 253 ||
            !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        ) {
            $_SESSION['error'] = 'Site adı veya alan adı geçersiz.';
            redirect('/sites/create');
        }

        $pdo = Database::connection();
        $userId = (int) auth()['id'];

        $subscription = $pdo->prepare(
            "SELECT p.website_limit
             FROM subscriptions s
             INNER JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = :user_id
               AND s.status = 'active'
               AND (s.ends_at IS NULL OR s.ends_at > NOW())
             ORDER BY s.id DESC
             LIMIT 1"
        );
        $subscription->execute(['user_id' => $userId]);
        $plan = $subscription->fetch();

        if (!$plan) {
            $_SESSION['error'] = 'Site eklemek için aktif bir aboneliğiniz olmalıdır.';
            redirect('/dashboard');
        }

        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM websites WHERE user_id = :user_id'
        );
        $count->execute(['user_id' => $userId]);

        if ((int) $count->fetchColumn() >= (int) $plan['website_limit']) {
            $_SESSION['error'] = 'Paketinizin site ekleme limitine ulaştınız.';
            redirect('/sites');
        }

        $duplicate = $pdo->prepare(
            'SELECT id FROM websites WHERE user_id = :user_id AND domain = :domain LIMIT 1'
        );
        $duplicate->execute([
            'user_id' => $userId,
            'domain' => $domain,
        ]);

        if ($duplicate->fetch()) {
            $_SESSION['error'] = 'Bu alan adı zaten eklenmiş.';
            redirect('/sites/create');
        }

        $insert = $pdo->prepare(
            "INSERT INTO websites (user_id, name, domain, site_key, status, created_at)
             VALUES (:user_id, :name, :domain, :site_key, 'active', NOW())"
        );

        $insert->execute([
            'user_id' => $userId,
            'name' => $name,
            'domain' => $domain,
            'site_key' => bin2hex(random_bytes(32)),
        ]);

        $_SESSION['success'] = 'Web sitesi eklendi.';
        redirect('/sites');
    }
}