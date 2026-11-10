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
<a href="/visits">Ziyaretler</a>
<a href="/ip-rules">IP yönetimi</a>
<a href="/google">Google Ads</a>
<?php if (isAdmin()): ?><a href="/admin">Yönetim</a><?php endif; ?>
<form method="post" action="/logout"><?= csrf_field() ?><button type="submit">Çıkış</button></form>
</nav>
</header>
<main class="container">
<h1>Kontrol Paneli</h1>
<p>Hoş geldiniz, <?= e(auth()['name'] ?? '') ?></p>
<section class="stats">
<article><span>Web siteleri</span><strong><?= (int) $websiteCount ?></strong></article>
<article><span>Bugünkü ziyaretler</span><strong><?= (int) $todayVisits ?></strong></article>
<article><span>Şüpheli ziyaretler</span><strong><?= (int) $suspiciousVisits ?></strong></article>
</section>
<h2>Günlük kota</h2>
<p><?= (int) ($quota['used'] ?? 0) ?> / <?= (int) ($quota['limit'] ?? 0) ?></p>
<h2>Web Sitelerim</h2>
<a class="button" href="/sites/create">Yeni Site Ekle</a>
<?php foreach ($websites as $website): ?>
<article class="site-row"><strong><?= e($website['name']) ?></strong><span><?= e($website['domain']) ?></span><span><?= e($website['status']) ?></span></article>
<?php endforeach; ?>
</main>
</body>
</html>
