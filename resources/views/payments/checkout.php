<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ödeme - AdGuard</title><link rel="stylesheet" href="/assets/app.css"></head><body><main class="auth-container"><h1>Ödeme bilgileri</h1>
<?php if (!empty($_SESSION['error'])): ?><p class="alert"><?= e($_SESSION['error']) ?></p><?php unset($_SESSION['error']); endif; ?>
<h2><?= e($plan['name']) ?></h2><p><strong><?= e(number_format((float)$plan['price'],2,',','.')) ?> <?= e($plan['currency']) ?></strong> / <?= $plan['billing_period']==='yearly'?'yıl':'ay' ?></p>
<form method="post" action="/payments/start"><?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
<label>Ad soyad</label><input name="name" required minlength="2" maxlength="120" value="<?= e(auth()['name']??'') ?>"><label>Telefon</label><input name="phone" type="tel" required minlength="10" maxlength="20">
<label>Fatura adresi</label><textarea name="address" required minlength="8" maxlength="500"></textarea><label>Şehir</label><input name="city" required maxlength="100"><label>Posta kodu</label><input name="zip" required maxlength="20">
<label>Ödeme sağlayıcısı</label><select name="provider" required><option value="paytr">PayTR</option><option value="iyzico">iyzico</option></select>
<label><input type="checkbox" name="auto_renew" value="1"> Aboneliğimin dönem sonunda otomatik yenilenmesini ve ödeme yöntemimden dönemsel tahsilat yapılmasını açıkça kabul ediyorum. İstediğim zaman iptal edebilirim.</label>
<label>T.C. Kimlik Numarası (iyzico için gereklidir)</label><input name="identity_number" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" autocomplete="off">
<p><small>Otomatik yenileme şu aşamada yalnızca iyzico abonelik planı eşleştirilmiş paketlerde kullanılabilir. PayTR tek çekim için kullanılabilir; PayTR kayıtlı kart ve tekrarlayan ödeme izinleri tamamlanmadan otomatik tahsilat başlatılmaz. Kart numarası ve CVV AdGuard sunucularında saklanmaz.</small></p>
<button type="submit">Güvenli ödemeye geç</button></form><p><a href="/pricing">Paketlere dön</a></p></main></body></html>
