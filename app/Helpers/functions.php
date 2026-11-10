<?php
declare(strict_types=1);

use App\Core\Database;

function env(string $key, mixed $default = null): mixed
{
    return \App\Core\Env::get($key, $default);
}

function db(): PDO
{
    return Database::connection();
}

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function url(string $path = '/'): string
{
    $base = rtrim((string) env('APP_URL', ''), '/');
    $normalizedPath = '/' . ltrim($path, '/');
    return $base . $normalizedPath;
}

function redirect(string $path, int $status = 302): never
{
    $url = str_starts_with($path, 'http') ? $path : url($path);

    header('Location: ' . $url, true, $status);
    exit;
}

function auth(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isAdmin(): bool
{
    return (auth()['role'] ?? null) === 'admin';
}

function requireAuth(): void
{
    if (!auth()) {
        redirect('/login');
    }
}

function requireAdmin(): void
{
    requireAuth();

    if (!isAdmin()) {
        http_response_code(403);
        exit('Bu işlem için yetkiniz bulunmuyor.');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' .
        e(\App\Core\Security::csrfToken()) . '">';
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function sanitize_html(string $html): string
{
    if (!class_exists(\DOMDocument::class)) {
        throw new RuntimeException('The PHP DOM extension is required for safe CMS rendering.');
    }

    $allowedTags = [
        'p','h1','h2','h3','h4','h5','h6','ul','ol','li','strong','b',
        'em','i','u','br','hr','a','blockquote','pre','code',
        'table','thead','tbody','tr','th','td'
    ];

    $previous = libxml_use_internal_errors(true);
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML(
        '<?xml encoding="utf-8" ?><div id="adguard-cms-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $dom->getElementById('adguard-cms-root');
    if (!$root) {
        return '';
    }

    $walk = static function (\DOMNode $node) use (&$walk, $allowedTags): void {
        for ($child = $node->firstChild; $child !== null;) {
            $next = $child->nextSibling;

            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowedTags, true)) {
                    $node->removeChild($child);
                    $child = $next;
                    continue;
                }

                $allowedAttributes = $tag === 'a' ? ['href','title','target'] :
                    (in_array($tag, ['th','td'], true) ? ['colspan','rowspan'] : []);
                $remove = [];
                foreach ($child->attributes as $attribute) {
                    $name = strtolower($attribute->name);
                    if (!in_array($name, $allowedAttributes, true)) {
                        $remove[] = $name;
                        continue;
                    }
                    if ($name === 'href') {
                        $value = trim($attribute->value);
                        $scheme = parse_url($value, PHP_URL_SCHEME);
                        if ($scheme !== null && !in_array(strtolower((string) $scheme), ['http','https','mailto'], true)) {
                            $remove[] = $name;
                        }
                    }
                    if ($name === 'target' && !in_array($attribute->value, ['_blank','_self'], true)) {
                        $remove[] = $name;
                    }
                }
                foreach ($remove as $name) {
                    $child->removeAttribute($name);
                }
                if ($child->tagName === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                $walk($child);
            }
            $child = $next;
        }
    };

    $walk($root);
    $result = '';
    foreach ($root->childNodes as $child) {
        $result .= $dom->saveHTML($child);
    }
    return $result;
}
