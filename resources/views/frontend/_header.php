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
    <button class="site-mobile-toggle" type="button" aria-label="Menüyü aç" aria-expanded="false" aria-controls="site-mobile-menu">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>
    </button>
    <nav class="site-main-nav" id="site-mobile-menu" aria-label="Ana menü">
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
<script>
(() => {
    const header = document.querySelector('.site-header');
    if (!header) return;
    const toggle = header.querySelector('.site-mobile-toggle');
    if (!toggle) return;
    const setOpen = (open) => {
        header.classList.toggle('is-menu-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Menüyü kapat' : 'Menüyü aç');
    };
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    header.querySelectorAll('.site-main-nav a, .site-account a').forEach((link) => link.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setOpen(false); });
    window.addEventListener('resize', () => { if (window.innerWidth > 760) setOpen(false); });
})();
</script>
