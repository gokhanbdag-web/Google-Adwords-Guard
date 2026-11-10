<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;

final class DashboardController
{
    public function index(): void
    {
        requireAdmin();

        $pdo = Database::connection();

        $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $websites = (int) $pdo->query('SELECT COUNT(*) FROM websites')->fetchColumn();
        $visits = (int) $pdo->query('SELECT COUNT(*) FROM visits')->fetchColumn();

        $suspicious = (int) $pdo->query(
            'SELECT COUNT(*) FROM visits WHERE is_suspicious = 1'
        )->fetchColumn();

        require BASE_PATH . '/resources/views/admin/dashboard.php';
    }
}