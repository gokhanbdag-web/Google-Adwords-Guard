<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kontrol Paneli - AdGuard</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="dashboard-header">
    <a href="/dashboard">AdGuard</a>
    <nav>
        <a href="/sites">Sitelerim</a>
        <?php if (isAdmin()): ?><a href="/admin">Yönetim</a><?php endif; ?>
        <form method="post" action="/logout">
            <?= csrf_field() ?>
            <button type="submit">Çıkış</button>
        </form>
    </nav>
</header>
<main class="container">
    <h1>Kontrol Paneli</h1>
    <p>Hoş geldiniz, <?= e(auth()['name'] ?? '') ?></p>
    <?php if (!empty($_SESSION['error'])): ?>
        <p class="alert"><?= e($_SESSION['error']) ?></p>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['success'])): ?>
        <p class="success"><?= e($_SESSION['success']) ?></p>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <section class="stats">
        <article><span>Web siteleri</span><strong><?= $websiteCount ?></strong></article>
        <article><span>Bugünkü ziyaretler</span><strong><?= $todayVisits ?></strong></article>
        <article><span>Toplam ziyaret</span><strong><?= $totalVisits ?></strong></article>
        <article><span>Şüpheli ziyaretler</span><strong><?= $suspiciousVisits ?></strong></article>
    </section>
    <h2>Web sitelerim</h2>
    <a class="button" href="/sites/create">Yeni site ekle</a>
</main>
</body>
</html>
