<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

final class PageController
{
    public function privacy(): void
    {
        $stmt = Database::connection()->prepare(
            "SELECT title, content FROM pages
             WHERE slug = 'privacy' AND status = 'published' LIMIT 1"
        );
        $stmt->execute();
        $page = $stmt->fetch();
        if (!$page) {
            http_response_code(404);
            exit('Sayfa bulunamadı.');
        }
        require BASE_PATH . '/resources/views/frontend/page.php';
    }
}
