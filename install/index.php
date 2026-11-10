<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$lockFile = $base . '/storage/installed.lock';
$envFile = $base . '/.env';
$errors = [];
$success = false;
$requirements = [
    'PHP 8.2+' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'PDO' => extension_loaded('pdo'),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'OpenSSL' => extension_loaded('openssl'),
    'cURL' => extension_loaded('curl'),
    'JSON' => extension_loaded('json'),
    'mbstring' => extension_loaded('mbstring'),
    'DOM' => extension_loaded('dom'),
];
$storagePath = $base . '/storage';
$writable = is_writable($base) && (!is_dir($storagePath) || is_writable($storagePath));

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function sqlStatements(string $sql): array {
    $statements = [];
    $buffer = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        $next = $sql[$i + 1] ?? '';
        if ($lineComment) {
            if ($ch === "\n") { $lineComment = false; $buffer .= "\n"; }
            continue;
        }
        if ($blockComment) {
            if ($ch === '*' && $next === '/') { $blockComment = false; $i++; }
            continue;
        }
        if ($quote === null && $ch === '-' && $next === '-' && (($sql[$i + 2] ?? ' ') <= ' ')) {
            $lineComment = true; $i++; continue;
        }
        if ($quote === null && $ch === '/' && $next === '*') { $blockComment = true; $i++; continue; }
        if ($quote !== null) {
            $buffer .= $ch;
            if ($ch === '\\' && $quote !== '`' && isset($sql[$i + 1])) { $buffer .= $sql[++$i]; continue; }
            if ($ch === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) { $buffer .= $sql[++$i]; continue; }
                $quote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buffer .= $ch; continue; }
        if ($ch === ';') {
            if (trim($buffer) !== '') { $statements[] = trim($buffer); }
            $buffer = '';
            continue;
        }
        $buffer .= $ch;
    }
    if (trim($buffer) !== '') { $statements[] = trim($buffer); }
    return $statements;
}

function runSqlFile(PDO $pdo, string $file, bool $schema = false): void {
    $sql = file_get_contents($file);
    if ($sql === false) { throw new RuntimeException('SQL dosyası okunamadı: ' . basename($file)); }
    // The installer is already connected to the database selected in the form.
    // Ignore dump-level USE statements so hosting database prefixes work.
    $sql = preg_replace('/^\\s*USE\\s+[^;]+;\\s*/im', '', $sql);
    if ($schema) {
        $sql = preg_replace('/^\\s*CREATE\\s+DATABASE\\b[^;]*;\\s*/im', '', $sql);
    }
    foreach (sqlStatements($sql) as $statement) { $pdo->exec($statement); }
}

if (is_file($lockFile) || is_file($envFile)) {
    http_response_code(403);
    exit('Kurulum daha önce tamamlanmış. Güvenlik nedeniyle kurulum sihirbazı kapalı.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['installer_token'] ?? '', (string)($_POST['_token'] ?? ''))) {
        $errors[] = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyin.';
    } elseif (in_array(false, $requirements, true)) {
        $errors[] = 'Sunucuda gerekli PHP sürümü veya eklentiler eksik.';
    } elseif (!$writable) {
        $errors[] = 'Proje ana dizini yazılabilir değil. Hosting dosya izinlerini kontrol edin.';
    } else {
        $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
        $dbPort = (int)($_POST['db_port'] ?? 3306);
        $dbName = trim((string)($_POST['db_name'] ?? ''));
        $dbUser = trim((string)($_POST['db_user'] ?? ''));
        $dbPass = (string)($_POST['db_pass'] ?? '');
        $adminName = trim((string)($_POST['admin_name'] ?? ''));
        $adminEmail = strtolower(trim((string)($_POST['admin_email'] ?? '')));
        $adminPass = (string)($_POST['admin_pass'] ?? '');
        $appUrl = rtrim(trim((string)($_POST['app_url'] ?? '')), '/');
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbName)) $errors[] = 'Veritabanı adı geçersiz.';
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,190}$/', $dbHost) || !preg_match('/^[a-zA-Z0-9_@.$-]{1,190}$/', $dbUser) || $dbPort < 1 || $dbPort > 65535) $errors[] = 'Veritabanı sunucusu, kullanıcı adı veya port bilgilerini kontrol edin.';
        if ($adminName === '' || mb_strlen($adminName) > 120 || strlen($adminEmail) > 190 || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminPass) < 12) $errors[] = 'Yönetici adı, e-posta veya parola geçersiz. Parola en az 12 karakter olmalı.';
        if (!filter_var($appUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($appUrl, PHP_URL_SCHEME), ['http','https'], true)) $errors[] = 'Site adresi http:// veya https:// ile başlamalı.';
        if (!$errors) {
            $pdo = null;
            try {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                runSqlFile($pdo, $base . '/database/schema.sql', true);
                runSqlFile($pdo, $base . '/database/seeders.sql');

                $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                    migration VARCHAR(190) NOT NULL PRIMARY KEY,
                    checksum CHAR(64) NOT NULL,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB");

                $migrations = glob($base . '/database/migrations/*.sql') ?: [];
                sort($migrations, SORT_STRING);
                $lookup = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?');
                $record = $pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
                foreach ($migrations as $migration) {
                    $migrationName = basename($migration);
                    $checksum = hash_file('sha256', $migration);
                    $lookup->execute([$migrationName]);
                    $appliedChecksum = $lookup->fetchColumn();
                    if ($appliedChecksum !== false) {
                        if (!hash_equals((string) $appliedChecksum, $checksum)) {
                            throw new RuntimeException('Uygulanmış migrasyon dosyası değiştirilmiş: ' . $migrationName);
                        }
                        continue;
                    }
                    runSqlFile($pdo, $migration);
                    $record->execute([$migrationName, $checksum]);
                }
                $stmt = $pdo->prepare('INSERT INTO users (name,email,password,role,status,email_verified_at) VALUES (?,?,?,"admin","active",NOW())');
                $stmt->execute([$adminName, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);
                $key = bin2hex(random_bytes(32));
                $env = [
                    'APP_NAME="AdGuard Pro"', 'APP_ENV=production', 'APP_DEBUG=false',
                    'APP_URL=' . $appUrl, 'APP_KEY=' . $key,
                    'DB_HOST=' . $dbHost, 'DB_PORT=' . $dbPort, 'DB_DATABASE=' . $dbName,
                    'DB_USERNAME=' . $dbUser, 'DB_PASSWORD="' . str_replace(['\\','"',"\n","\r"], ['\\\\','\\"','',''], $dbPass) . '"',
                    'SESSION_NAME=adguard_session', 'SESSION_SECURE=' . (parse_url($appUrl, PHP_URL_SCHEME) === 'https' ? 'true' : 'false'),
                    'SESSION_SAMESITE=Lax', 'GOOGLE_CLIENT_ID=', 'GOOGLE_CLIENT_SECRET=',
                    'GOOGLE_REDIRECT_URI=' . $appUrl . '/google/callback', 'GOOGLE_ADS_API_VERSION=v26',
                    'GOOGLE_ADS_WRITES_ENABLED=false', 'RISK_CANDIDATE_THRESHOLD=80',
                    'RISK_CANDIDATE_MIN_EVENTS=3', 'RISK_CANDIDATE_MIN_SIGNALS=3',
                    'VISIT_RETENTION_DAYS=90', 'TRUSTED_PROXIES=', 'LOG_LEVEL=warning',
                    'PAYTR_MERCHANT_ID=', 'PAYTR_MERCHANT_KEY=', 'PAYTR_MERCHANT_SALT=',
                    'PAYTR_TEST_MODE=1', 'IYZICO_MERCHANT_ID=', 'IYZICO_API_KEY=',
                    'IYZICO_SECRET_KEY=', 'IYZICO_BASE_URL=https://sandbox-api.iyzipay.com',
                ];
                if (file_put_contents($envFile, implode("\n", $env) . "\n", LOCK_EX) === false) {
                    throw new RuntimeException('.env dosyası yazılamadı. Ana dizin izinlerini kontrol edin.');
                }
                if (!is_dir($base . '/storage')) mkdir($base . '/storage', 0750, true);
                if (file_put_contents($lockFile, "installed_at=" . date(DATE_ATOM) . "\n", LOCK_EX) === false) {
                    @unlink($envFile);
                    throw new RuntimeException('Kurulum kilidi oluşturulamadı.');
                }
                $success = true;
            } catch (Throwable $e) {
                error_log('AdGuard installer: ' . $e->getMessage());
                $errors[] = 'Kurulum tamamlanamadı. Veritabanı bilgilerini, boş veritabanı kullandığınızı ve dosya izinlerini kontrol edin. Ayrıntı sunucu hata kaydına yazıldı.';
            }
        }
    }
}

if (empty($_SESSION['installer_token'])) $_SESSION['installer_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['installer_token'];
$scriptDirectory = str_replace('\\\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
// The installer is loaded internally by the public front controller. Never
// expose /public in the site's canonical URL when installed in a subdirectory.
$scriptDirectory = preg_replace('#/public$#', '', rtrim($scriptDirectory, '/'));
$basePath = $scriptDirectory === '' || $scriptDirectory === '.' ? '' : $scriptDirectory;
$defaultUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $basePath;
if ($defaultUrl === '') $defaultUrl = 'http://localhost';
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AdGuard Pro Kurulumu</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f6fb;color:#182230;font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:760px;margin:42px auto;padding:0 18px}.panel{background:#fff;border:1px solid #e4e9f0;border-radius:16px;padding:30px;box-shadow:0 12px 40px #1b2a4410}h1{margin:0 0 6px;font-size:27px}h2{font-size:17px;margin:24px 0 10px}.muted{color:#667085}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{margin:12px 0}.field label{display:block;font-weight:600;margin-bottom:5px}.field input{width:100%;padding:12px;border:1px solid #ccd5e0;border-radius:8px;font:inherit}.checks{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:18px 0}.check{padding:9px 11px;border-radius:8px;background:#f7f9fc}.ok{color:#087443}.bad{color:#b42318}.alert{padding:12px 14px;border-radius:9px;background:#fff1f0;color:#9d2520;margin:16px 0}.success{background:#ecfdf3;color:#067647;padding:18px;border-radius:10px}.btn{display:block;width:100%;border:0;border-radius:9px;background:#175cd3;color:white;padding:14px;font-size:16px;font-weight:700;margin-top:22px}.hint{font-size:13px;color:#667085}.req{font-size:13px;color:#667085}@media(max-width:600px){.panel{padding:20px}.grid,.checks{grid-template-columns:1fr}}
</style></head><body><main class="wrap"><section class="panel">
<h1>AdGuard Pro kurulumu</h1><div class="muted">WordPress benzeri kurulum sihirbazı · SSH veya Composer gerekmez</div>
<?php if ($success): ?><div class="success"><h2>Kurulum tamamlandı</h2>Veritabanı hazırlandı ve yönetici hesabı oluşturuldu. Güvenlik kilidi etkinleştirildi.<p><a href="<?=h($appUrl)?>/login">Yönetici girişi</a></p></div>
<?php else: ?>
<div class="checks"><?php foreach($requirements as $label=>$ok): ?><div class="check <?= $ok?'ok':'bad' ?>"><?= $ok?'✓':'✕' ?> <?=h($label)?></div><?php endforeach; ?><div class="check <?= $writable?'ok':'bad' ?>"><?= $writable?'✓':'✕' ?> Dosya yazma izni</div></div>
<?php foreach($errors as $error): ?><div class="alert"><?=h($error)?></div><?php endforeach; ?>
<p class="hint">Önce hosting panelinden MySQL veritabanı ve kullanıcı oluşturup kullanıcıya bu veritabanı için tüm gerekli yetkileri verin.</p>
<form method="post"><input type="hidden" name="_token" value="<?=h($token)?>">
<h2>Site ve veritabanı</h2><div class="field"><label>Site adresi</label><input name="app_url" type="url" required value="<?=h($_POST['app_url']??$defaultUrl)?>"></div>
<div class="grid"><div class="field"><label>Veritabanı sunucusu</label><input name="db_host" required value="<?=h($_POST['db_host']??'localhost')?>"></div><div class="field"><label>Port</label><input name="db_port" type="number" required value="<?=h($_POST['db_port']??'3306')?>"></div>
<div class="field"><label>Veritabanı adı</label><input name="db_name" required value="<?=h($_POST['db_name']??'')?>"></div><div class="field"><label>Veritabanı kullanıcı adı</label><input name="db_user" required value="<?=h($_POST['db_user']??'')?>"></div></div>
<div class="field"><label>Veritabanı parolası</label><input name="db_pass" type="password" autocomplete="new-password"></div>
<h2>Yönetici hesabı</h2><div class="field"><label>Ad soyad</label><input name="admin_name" required value="<?=h($_POST['admin_name']??'')?>"></div>
<div class="field"><label>E-posta</label><input name="admin_email" type="email" required value="<?=h($_POST['admin_email']??'')?>"></div>
<div class="field"><label>Parola (en az 12 karakter)</label><input name="admin_pass" type="password" minlength="12" required autocomplete="new-password"></div>
<button class="btn" type="submit">AdGuard Pro'yu kur</button></form>
<p class="req">Kurulum, seçtiğiniz veritabanına tabloları ve başlangıç ayarlarını ekler. Mevcut tabloları silmez; boş bir veritabanı kullanın.</p>
<?php endif; ?></section></main></body></html>
