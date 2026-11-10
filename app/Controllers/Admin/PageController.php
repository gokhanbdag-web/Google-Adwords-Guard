<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use PDO;

final class PageController
{
    public function index(): void
    {
        requireAdmin();
        $pages = Database::connection()->query('SELECT * FROM pages ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        require BASE_PATH . '/resources/views/admin/pages.php';
    }

    public function store(): void
    {
        requireAdmin();
        $data = $this->validated($_POST);
        $stmt = Database::connection()->prepare(
            "INSERT INTO pages (title, slug, content, status, meta_title, meta_description)
             VALUES (:title, :slug, :content, :status, :meta_title, :meta_description)"
        );
        $stmt->execute($data);
        $_SESSION['success'] = 'Sayfa oluşturuldu.';
        redirect('/admin/pages');
    }

    public function update(): void
    {
        requireAdmin();
        $data = $this->validated($_POST);
        $data['id'] = (int) ($_POST['id'] ?? 0);
        $stmt = Database::connection()->prepare(
            "UPDATE pages SET title = :title, slug = :slug, content = :content,
             status = :status, meta_title = :meta_title, meta_description = :meta_description
             WHERE id = :id"
        );
        $stmt->execute($data);
        $_SESSION['success'] = 'Sayfa güncellendi.';
        redirect('/admin/pages');
    }

    public function delete(): void
    {
        requireAdmin();
        $stmt = Database::connection()->prepare('DELETE FROM pages WHERE id = :id');
        $stmt->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        $_SESSION['success'] = 'Sayfa silindi.';
        redirect('/admin/pages');
    }

    private function validated(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $content = (string) ($input['content'] ?? '');
        $status = (string) ($input['status'] ?? 'draft');
        $metaTitle = trim((string) ($input['meta_title'] ?? ''));
        $metaDescription = trim((string) ($input['meta_description'] ?? ''));

        if (mb_strlen($title) < 2 || mb_strlen($title) > 190 ||
            !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) ||
            mb_strlen($slug) > 190 ||
            !in_array($status, ['draft', 'published'], true) ||
            mb_strlen($metaTitle) > 190 || mb_strlen($metaDescription) > 320 ||
            strlen($content) > 1000000) {
            $_SESSION['error'] = 'Sayfa alanlarını kontrol edin.';
            redirect('/admin/pages');
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'content' => sanitize_html($content),
            'status' => $status,
            'meta_title' => $metaTitle !== '' ? $metaTitle : null,
            'meta_description' => $metaDescription !== '' ? $metaDescription : null
        ];
    }
}
