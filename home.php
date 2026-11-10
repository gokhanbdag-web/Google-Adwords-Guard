<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page['title'] ?? 'AdGuard') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="site-header">
    <a href="/" class="brand">AdGuard</a>
    <nav>
        <a href="/login">Giriş Yap</a>
        <a href="/register">Kayıt Ol</a>
    </nav>
</header>

<main class="container">
    <?php if ($page): ?>
        <?= $page['content'] ?>
    <?php else: ?>
        <h1>Reklam trafiğinizi analiz edin</h1>
        <p>Web sitenizin ziyaretlerini izleyin ve şüpheli trafik sinyallerini inceleyin.</p>
    <?php endif; ?>
    <a class="button" href="/register">Hemen Başlayın</a>
</main>
</body>
</html>