<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sitelerim - AdGuard</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="container">
    <h1>Sitelerim</h1>\n    <a href="/dashboard">Kontrol Paneli</a>\n    <a href="/ip-rules">IP Yönetimi</a>
    <a class="button" href="/sites/create">Yeni Site Ekle</a>
    <a href="/dashboard">Kontrol Paneli</a>

    <?php foreach ($websites as $website): ?>
        <article class="site-card">
            <h2><?= e($website['name']) ?></h2>
            <p><?= e($website['domain']) ?></p>
            <p>Durum: <?= e($website['status']) ?></p>
            <label>Takip kodu</label>
            <textarea readonly rows="2">&lt;script defer src="<?= e(rtrim((string) env('APP_URL'), '/')) ?>/tracker.js" data-site-key="<?= e($website['site_key']) ?>"&gt;&lt;/script&gt;</textarea>
        </article>
    <?php endforeach; ?>
</main>
</body>
</html>