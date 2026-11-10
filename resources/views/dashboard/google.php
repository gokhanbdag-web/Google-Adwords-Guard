<!doctype html>
<html lang="tr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Google Ads bağlantısı - AdGuard</title><link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>"></head>
<body>
<main class="container">
    <h1>Google Ads hesapları</h1>
    <p><a href="<?= e(url('/dashboard')) ?>">Kontrol paneli</a> · <a href="<?= e(url('/ip-rules')) ?>">IP yönetimi</a></p>
    <?php if (!empty($_SESSION['success'])): ?><p class="success"><?= e($_SESSION['success']) ?></p><?php unset($_SESSION['success']); endif; ?>
    <?php if (!empty($_SESSION['error'])): ?><p class="alert"><?= e($_SESSION['error']) ?></p><?php unset($_SESSION['error']); endif; ?>
    <a class="button" href="<?= e(url('/google/connect')) ?>">Google Ads hesabını bağla</a>

    <?php if (!$connections): ?><p>Henüz bağlı Google Ads hesabı yok.</p><?php endif; ?>
    <?php foreach ($connections as $connection): ?>
        <section class="site-card">
            <h2>Müşteri hesabı: <?= e($connection['customer_id']) ?></h2>
            <p>Durum: <?= e($connection['status']) ?> · Token bitişi: <?= e($connection['token_expires_at'] ?? '') ?></p>
            <form method="post" action="<?= e(url('/google/manager')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $connection['id'] ?>">
                <label>Yönetici müşteri numarası (MCC, isteğe bağlı)</label>
                <input type="text" name="login_customer_id" value="<?= e($connection['login_customer_id'] ?? '') ?>" placeholder="Örn. 1234567890">
                <button type="submit">Yönetici hesabını kaydet</button>
            </form>
            <p><a href="/google/campaigns?id=<?= (int) $connection['id'] ?>">Kampanyaları ve IP hariç tutmalarını yönet</a></p>
            <form method="post" action="<?= e(url('/google/disconnect')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $connection['id'] ?>">
                <button type="submit">Bağlantıyı kaldır</button>
            </form>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
