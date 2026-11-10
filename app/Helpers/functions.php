<?php
declare(strict_types=1);
use App\Core\Database;
function env(string $key, mixed $default = null): mixed { return \App\Core\Env::get($key, $default); }
function db(): PDO { return Database::connection(); }
function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = '/'): string {
    $path = trim($path);
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim((string)env('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}
function redirect(string $path, int $status = 302): never {
    header('Location: ' . url($path), true, $status); exit;
}
function setting(string $key, mixed $default = null): mixed {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]); $value = $stmt->fetchColumn();
    return $cache[$key] = ($value === false ? $default : $value);
}
function save_setting(string $key, ?string $value): void {
    db()->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$value]);
}
function menu_items(string $location): array {
    static $cache = [];
    if (!isset($cache[$location])) {
        $stmt=db()->prepare('SELECT label,url,target FROM menus WHERE location=? AND is_active=1 ORDER BY sort_order,id');
        $stmt->execute([$location]); $cache[$location]=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $cache[$location];
}
function menu_href(string $path): string { return preg_match('#^https?://#i',$path) ? $path : url($path); }
function auth(): ?array { return $_SESSION['user'] ?? null; }
function isAdmin(): bool { return (auth()['role'] ?? null) === 'admin'; }
function requireAuth(): void { if (!auth()) redirect('/login'); }
function requireAdmin(): void { requireAuth(); if (!isAdmin()) { http_response_code(403); exit('Bu işlem için yetkiniz bulunmuyor.'); } }
function csrf_field(): string { return '<input type="hidden" name="_token" value="' . e(\App\Core\Security::csrfToken()) . '">'; }
function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
function sanitize_html(string $html): string {
    if (!class_exists(\DOMDocument::class)) throw new RuntimeException('The PHP DOM extension is required for safe CMS rendering.');
    $allowedTags=['div','section','article','header','footer','nav','span','small','figure','figcaption','details','summary','dl','dt','dd','p','h1','h2','h3','h4','h5','h6','ul','ol','li','strong','b','em','i','u','br','hr','a','blockquote','pre','code','table','thead','tbody','tr','th','td','img'];
    $previous=libxml_use_internal_errors(true); $dom=new \DOMDocument('1.0','UTF-8');
    $dom->loadHTML('<?xml encoding="utf-8" ?><div id="adguard-cms-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($previous); $root=$dom->getElementById('adguard-cms-root'); if(!$root)return '';
    $walk=static function(\DOMNode $node) use (&$walk,$allowedTags):void {
        for($child=$node->firstChild;$child!==null;){$next=$child->nextSibling;
            if($child instanceof \DOMElement){$tag=strtolower($child->tagName);if(!in_array($tag,$allowedTags,true)){$node->removeChild($child);$child=$next;continue;}
                $allowed=['class','id'];
                if($tag==='a')$allowed=array_merge($allowed,['href','title','target']);
                if($tag==='img')$allowed=array_merge($allowed,['src','alt','loading','width','height']);
                if(in_array($tag,['th','td'],true))$allowed=array_merge($allowed,['colspan','rowspan']);
                $remove=[];
                foreach($child->attributes as $attribute){$name=strtolower($attribute->name);$value=trim($attribute->value);
                    if(!in_array($name,$allowed,true)){$remove[]=$name;continue;}
                    if($name==='class'&&!preg_match('/^[A-Za-z0-9 _-]{1,240}$/',$value))$remove[]=$name;
                    if($name==='id'&&!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/',$value))$remove[]=$name;
                    if($name==='href'){$scheme=parse_url($value,PHP_URL_SCHEME);if($scheme!==null&&!in_array(strtolower((string)$scheme),['http','https','mailto'],true))$remove[]=$name;}
                    if($name==='src'){$scheme=parse_url($value,PHP_URL_SCHEME);$local=str_starts_with($value,'/')&&!str_starts_with($value,'//');$remote=$scheme!==null&&in_array(strtolower((string)$scheme),['http','https'],true);if(!$local&&!$remote)$remove[]=$name;}
                    if($name==='target'&&!in_array($value,['_blank','_self'],true))$remove[]=$name;
                    if(in_array($name,['width','height','colspan','rowspan'],true)&&(!ctype_digit($value)||(int)$value<1||(int)$value>3000))$remove[]=$name;
                    if($name==='loading'&&!in_array($value,['lazy','eager'],true))$remove[]=$name;
                }
                foreach($remove as $name)$child->removeAttribute($name);
                if($tag==='a'&&$child->getAttribute('target')==='_blank')$child->setAttribute('rel','noopener noreferrer');
                if($tag==='img'&&!$child->hasAttribute('src')){$node->removeChild($child);$child=$next;continue;}
                $walk($child);
            } $child=$next;
        }
    };
    $walk($root);$result='';foreach($root->childNodes as $child)$result.=$dom->saveHTML($child);return $result;
}
