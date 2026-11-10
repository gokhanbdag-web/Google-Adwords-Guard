<?php
declare(strict_types=1);

$headerMenus = $headerMenus ?? menu_items('header');
$user = auth();
$visibleHeaderMenus = array_values(array_filter($headerMenus, static function (array $item): bool {
    $url = strtolower(trim((string)($item['url'] ?? '')));
    return !in_array($url, ['/login', '/register', '/logout'], true);
}));
?>
<header class="site-header">
    <a class="brand" href="<?= e(url('/')) ?>"><?= e($siteName ?? 'AdGuard Pro') ?></a>
    <nav class="site-main-nav" aria-label="Ana menü">
        <?php foreach ($visibleHeaderMenus as $item): ?>
            <a href="<?= e(menu_href((string)($item['url'] ?? '/'))) ?>"
               target="<?= e(($item['target'] ?? '_self') === '_blank' ? '_blank' : '_self') ?>"
               <?= ($item['target'] ?? '_self') === '_blank' ? 'rel="noopener noreferrer"' : '' ?>>
                <?= e($item['label'] ?? '') ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="site-account">
        <?php if ($user): ?>
            <?php if (($user['role'] ?? '') === 'admin'): ?>
                <a class="site-account-link" href="<?= e(url('/admin')) ?>">Yönetim</a>
            <?php else: ?>
                <a class="site-account-link" href="<?= e(url('/dashboard')) ?>">Hesabım</a>
            <?php endif; ?>
            <form class="site-account-form" method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_field() ?>
                <button class="site-account-button" type="submit">Çıkış Yap</button>
            </form>
        <?php else: ?>
            <a class="site-account-link" href="<?= e(url('/login')) ?>">Giriş Yap</a>
            <a class="site-account-button" href="<?= e(url('/register')) ?>">Kayıt Ol</a>
        <?php endif; ?>
    </div>
</header>
