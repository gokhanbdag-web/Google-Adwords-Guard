<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page['meta_title'] ?: $page['title']) ?></title>
    <meta name="description" content="<?= e($page['meta_description'] ?? '') ?>">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/">AdGuard</a>
    <nav><a href="/">Ana Sayfa</a><a href="/login">Giriş</a><a href="/register">Kayıt Ol</a></nav>
</header>
<main class="container">
    <h1><?= e($page['title']) ?></h1>
    <div class="site-card"><?= $page['content'] ?></div>
    <p><a href="/">Ana sayfaya dön</a></p>
</main>
</body>
</html>
