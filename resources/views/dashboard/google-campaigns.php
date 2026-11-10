<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Google Ads kampanyaları - AdGuard</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="container">
    <h1>Google Ads IP hariç tutmaları</h1>
    <p><a href="/google">Hesap bağlantılarına dön</a> · <a href="/dashboard">Kontrol paneli</a></p>

    <?php if (!empty($_SESSION['success'])): ?>
        <p class="success"><?= e($_SESSION['success']) ?></p>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <p class="alert"><?= e($_SESSION['error']) ?></p>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <h2>Bağlı hesap: <?= e($connection['customer_id']) ?></h2>
    <p>Hesap genelindeki hariç tutmalar tüm kampanyalara uygulanır. Kampanya bazlı hariç tutmalar yalnızca desteklenen kampanya türlerinde listelenir.</p>
    <?php if ($writesEnabled): ?>
        <p class="alert">Canlı yazma ayarı açık. Gerçek değişiklik için her formda ayrıca onay vermeniz gerekir.</p>
    <?php else: ?>
        <p class="success">Güvenli doğrulama modu açık. API çağrıları validateOnly ile çalışır; Google Ads hesabında değişiklik yapılmaz.</p>
    <?php endif; ?>

    <section class="site-card">
        <h2>Hesap genelinde IP hariç tutma</h2>
        <p>Seçilen IP, Google Ads müşteri hesabının tamamında hariç tutulur. Hesap düzeyinde en fazla 500 IP kaydı desteklenir.</p>
        <?php if (!$rules): ?>
            <p>Onaylanmış aktif deny kuralı bulunamadı.</p>
        <?php endif; ?>
        <?php foreach ($rules as $rule): ?>
            <form method="post" action="/google/exclude" class="site-row">
                <?= csrf_field() ?>
                <input type="hidden" name="connection_id" value="<?= (int) $connection['id'] ?>">
                <input type="hidden" name="scope" value="account">
                <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                <span><?= e($rule['ip_text']) ?> — <?= e($rule['website_name']) ?></span>
                <?php if ($writesEnabled): ?>
                    <label><input type="checkbox" name="confirm_live" value="1" required> Hesap genelinde canlı değişikliği onaylıyorum.</label>
                    <button type="submit" <?= !$rules ? 'disabled' : '' ?>>Hesap genelinde uygula</button>
                <?php else: ?>
                    <button type="submit" <?= !$rules ? 'disabled' : '' ?>>Hesap genelinde doğrula</button>
                <?php endif; ?>
            </form>
        <?php endforeach; ?>
    </section>

    <h2>Kampanya bazında hariç tutma</h2>
    <?php if (!$campaigns): ?>
        <p>IP hariç tutmayı destekleyen etkin Search, Display veya Shopping kampanyası bulunamadı. Hesap genelindeki yöntemi kullanabilirsiniz.</p>
    <?php endif; ?>

    <?php foreach ($campaigns as $row): $campaign = $row['campaign'] ?? []; ?>
        <?php if (($campaign['status'] ?? '') !== 'ENABLED') continue; ?>
        <section class="site-card">
            <h3><?= e($campaign['name'] ?? 'Kampanya') ?></h3>
            <p>ID: <?= e($campaign['id'] ?? '') ?> · Tür: <?= e($campaign['advertisingChannelType'] ?? '') ?> · Durum: <?= e($campaign['status'] ?? '') ?></p>
            <?php foreach ($rules as $rule): ?>
                <form method="post" action="/google/exclude" class="site-row">
                    <?= csrf_field() ?>
                    <input type="hidden" name="connection_id" value="<?= (int) $connection['id'] ?>">
                    <input type="hidden" name="scope" value="campaign">
                    <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
                    <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                    <span><?= e($rule['ip_text']) ?> — <?= e($rule['website_name']) ?></span>
                    <?php if ($writesEnabled): ?>
                        <label><input type="checkbox" name="confirm_live" value="1" required> Bu kampanyada canlı değişikliği onaylıyorum.</label>
                        <button type="submit">Kampanyada uygula</button>
                    <?php else: ?>
                        <button type="submit">Kampanyada doğrula</button>
                    <?php endif; ?>
                </form>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <h2>Google Ads işlem geçmişi</h2>
    <?php if (!$actions): ?>
        <p>Henüz Google Ads API işlemi yapılmadı.</p>
    <?php endif; ?>
    <?php foreach ($actions as $action): ?>
        <article class="site-card">
            <strong><?= e($action['ip_address']) ?></strong>
            <p><?= e($action['action_type']) ?> · <?= $action['campaign_id'] === '0' || (int) $action['campaign_id'] === 0 ? 'Hesap geneli' : 'Kampanya ' . e($action['campaign_id']) ?></p>
            <p>Durum: <?= e($action['status']) ?> · <?= e($action['created_at']) ?></p>
            <?php if (!empty($action['error_message'])): ?><p class="alert"><?= e($action['error_message']) ?></p><?php endif; ?>
            <?php if (in_array($action['action_type'], ['ip_exclusion', 'account_ip_exclusion'], true) && $action['status'] === 'succeeded'): ?>
                <form method="post" action="/google/remove">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action_id" value="<?= (int) $action['id'] ?>">
                    <?php if ($writesEnabled): ?>
                        <label><input type="checkbox" name="confirm_live" value="1" required> Bu hariç tutmayı Google Ads'ten gerçekten kaldırmayı onaylıyorum.</label>
                        <button type="submit">Google Ads'ten kaldır</button>
                    <?php else: ?>
                        <button type="submit">Kaldırmayı doğrula (dry-run)</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</main>
</body>
</html>
