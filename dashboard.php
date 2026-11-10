<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Yönetim Paneli - AdGuard</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="container">
    <h1>Yönetim Paneli</h1>
    <p>Toplam kullanıcı: <?= $users ?></p>
    <p>Toplam web sitesi: <?= $websites ?></p>
    <p>Toplam ziyaret: <?= $visits ?></p>
    <p>Şüpheli ziyaret: <?= $suspicious ?></p>
    <a href="/dashboard">Müşteri Paneline Dön</a>
</main>
</body>
</html>