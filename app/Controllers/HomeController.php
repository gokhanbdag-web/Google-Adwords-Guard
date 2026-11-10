<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

final class HomeController
{
    public function index(): void
    {
        $stmt = Database::connection()->prepare(
            "SELECT title, content
             FROM pages
             WHERE slug = :slug AND status = 'published'
             LIMIT 1"
        );

        $stmt->execute(['slug' => 'home']);
        $page = $stmt->fetch();

        require BASE_PATH . '/resources/views/frontend/home.php';
    }
}