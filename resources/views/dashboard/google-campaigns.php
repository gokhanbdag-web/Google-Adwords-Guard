<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Google Ads kampanyaları - AdGuard</title><link rel="stylesheet" href="/assets/app.css"></head>
<body><main class="container"><h1>Google Ads kampanyaları</h1><p><a href="/google">Hesap bağlantılarına dön</a></p><?php if(!empty($_SESSION['success'])): ?><p class="success"><?= e($_SESSION['success']) ?></p><?php unset($_SESSION['success']); endif; ?><?php if(!empty($_SESSION['error'])): ?><p class="alert"><?= e($_SESSION['error']) ?></p><?php unset($_SESSION['error']); endif; ?>
<h2>Bağlı hesap: <?= e($connection['customer_id']) ?></h2>
<?php if(!$campaigns): ?><p>Bu hesapta listelenecek kampanya bulunamadı.</p><?php endif; ?>
<?php foreach($campaigns as $row): $campaign=$row['campaign']??[]; ?>
<section class="site-card"><h3><?= e($campaign['name']??'Kampanya') ?></h3><p>ID: <?= e($campaign['id']??'') ?> · Durum: <?= e($campaign['status']??'') ?></p>
<form method="post" action="/google/exclude"><?= csrf_field() ?><input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>"><input type="hidden" name="campaign_id" value="<?= (int)($campaign['id']??0) ?>"><label>Onaylanmış IP engelleme kuralı</label><select name="rule_id" required><?php foreach($rules as $rule): ?><option value="<?= (int)$rule['id'] ?>"><?= e($rule['ip_text']) ?> — <?= e($rule['website_name']) ?></option><?php endforeach; ?></select><button type="submit" <?= !$rules?'disabled':'' ?>>Bu kampanyada IP'yi hariç tut</button></form>
</section>
<?php endforeach; ?>
<p>Bu işlem yalnızca yönetici/hesap sahibinin açık onayıyla çalışır. Google Ads yazma işlemleri .env ayarında ayrıca etkinleştirilmelidir.</p></main></body></html>
