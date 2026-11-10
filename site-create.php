<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Site Ekle - AdGuard</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="auth-container">
    <h1>Yeni Web Sitesi</h1>

    <?php if (!empty($_SESSION['error'])): ?>
        <p class="alert"><?= e($_SESSION['error']) ?></p>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <form method="post" action="/sites">
        <?= csrf_field() ?>
        <label>Site adı</label>
        <input type="text" name="name" maxlength="120" required>

        <label>Alan adı</label>
        <input type="text" name="domain" placeholder="ornek.com" required>

        <button type="submit">Siteyi Ekle</button>
    </form>

    <a href="/sites">Geri dön</a>
</main>
</body>
</html>