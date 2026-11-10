<?php
$headerMenus = $headerMenus ?? menu_items('header');
$visibleHeaderMenus = array_values(array_filter($headerMenus, static function(array $item): bool {
    $href = strtolower(trim((string)($item['url'] ?? '')));
    $label = mb_strtolower(trim((string)($item['label'] ?? '')), 'UTF-8');
    return !in_array($href, ['/login','login','/register','register','/logout','logout'], true)
        && !in_array($label, ['giriş yap','giris yap','oturum aç','oturum ac','kayıt ol','kayit ol','çıkış yap','cikis yap'], true);
}));
$currentUser = auth();
?>
<header class="site-header">
<a href="<?= e(url('/')) ?>" class="brand"><?= e($siteName) ?></a>
<nav class="site-main-nav" aria-label="Ana menü">
<?php foreach($visibleHeaderMenus as $item): ?>
<a href="<?= e(menu_href($item['url'])) ?>" target="<?= e($item['target']) ?>" <?= $item['target']==='_blank'?'rel="noopener noreferrer"':'' ?>><?= e($item['label']) ?></a>
<?php endforeach; ?>
</nav>
<div class="site-account">
<?php if($currentUser): ?>
<?php if(($currentUser['role']??'')==='admin'): ?><a class="site-account-link" href="<?= e(url('/admin')) ?>">Yönetim Paneli</a><?php endif; ?>
<form method="post" action="<?= e(url('/logout')) ?>" class="site-account-form"><?= csrf_field() ?><button type="submit" class="site-account-button">Çıkış Yap</button></form>
<?php else: ?>
<a class="site-account-button" href="<?= e(url('/login')) ?>">Giriş Yap</a>
<?php endif; ?>
</div>
</header>