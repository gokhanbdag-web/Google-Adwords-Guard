<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

final class PageController
{
    public function privacy(): void
    {
        $statement = Database::connection()->prepare(
            "SELECT title, content, meta_title, meta_description
             FROM pages
             WHERE slug = 'privacy' AND status = 'published'
             LIMIT 1"
        );
        $statement->execute();
        $page = $statement->fetch();

        if (!$page) {
            http_response_code(404);
            $page = [
                'title' => 'Gizlilik Politikası',
                'content' => '<p>Gizlilik politikası henüz yayınlanmamıştır.</p>',
                'meta_title' => 'Gizlilik Politikası',
                'meta_description' => ''
            ];
        }

        require BASE_PATH . '/resources/views/frontend/privacy.php';
    }
}
