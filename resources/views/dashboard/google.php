<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Google Ads Bağlantısı - AdGuard</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="dashboard-header">
    <a href="/dashboard">AdGuard</a>
    <nav>
        <a href="/dashboard">Panel</a>
        <a href="/sites">Sitelerim</a>
        <a href="/ip-rules">IP Yönetimi</a>
        <a href="/google">Google Ads</a>
        <form method="post" action="/logout"><?= csrf_field() ?><button type="submit">Çıkış</button></form>
    </nav>
</header>
<main class="container">
    <h1>Google Ads bağlantısı</h1>
    <p>Google hesabınızı bağlayın, erişebildiğiniz müşteri hesabını ve kampanyayı seçin. IP hariç tutma işlemleri varsayılan olarak yalnızca doğrulama modunda çalışır.</p>

    <?php if (!empty($_SESSION['error'])): ?>
        <p class="alert"><?= e($_SESSION['error']) ?></p>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['success'])): ?>
        <p class="success"><?= e($_SESSION['success']) ?></p>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if ($googleError): ?>
        <p class="alert">Google Ads API: <?= e($googleError) ?></p>
    <?php endif; ?>

    <section class="site-card">
        <h2>Google hesabı</h2>
        <p>Google OAuth üzerinden yalnızca Google Ads erişimi istenir. Token'lar veritabanında şifreli saklanır.</p>
        <a class="button" href="/google/connect">Google Ads hesabını bağla</a>
        <?php if (!$connections): ?>
            <p>Henüz bağlı Google Ads hesabı yok.</p>
        <?php else: ?>
            <?php foreach ($connections as $connection): ?>
                <form method="post" action="/google/select" class="site-card">
                    <?= csrf_field() ?>
                    <input type="hidden" name="customer_id" value="<?= e($connection['customer_id']) ?>">
                    <h3>Hesap: <?= e($connection['customer_id']) ?></h3>
                    <p>Durum: <?= e($connection['status']) ?> · <?= !empty($connection['is_selected']) ? 'Seçili hesap' : 'Seçili değil' ?></p>
                    <label>Yönetici müşteri numarası (MCC, isteğe bağlı)</label>
                    <input type="text" name="login_customer_id" value="<?= e($connection['login_customer_id'] ?? '') ?>" placeholder="Örn. 1234567890">
                    <button type="submit"><?= !empty($connection['is_selected']) ? 'Seçimi güncelle' : 'Bu hesabı seç' ?></button>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php if ($selected): ?>
        <section class="site-card">
            <h2>Seçili hesap: <?= e($selected['customer_id']) ?></h2>
            <p>API sürümü: <?= e(env('GOOGLE_ADS_API_VERSION', 'v26')) ?></p>
            <p>
                <?php if ($writesEnabled): ?>
                    <strong>Canlı yazma izni sunucu ayarında açık.</strong> Her işlemde ayrıca açık onay gerekir.
                <?php else: ?>
                    <strong>Güvenli doğrulama modu.</strong> Sunucu ayarında canlı Google Ads yazma işlemleri kapalı.
                <?php endif; ?>
            </p>
            <h3>Kampanyalar</h3>
            <?php if (!$campaigns): ?>
                <p>Bu hesapta erişilebilir kampanya bulunamadı.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($campaigns as $campaign): ?>
                        <li><?= e($campaign['name']) ?> (<?= e($campaign['id']) ?>) — <?= e($campaign['channel']) ?> / <?= e($campaign['status']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <h2>Onaylanmış IP kuralları</h2>
        <?php if (!$rules): ?>
            <p>Google Ads'e gönderilmeye hazır aktif deny kuralı yok. Önce IP Yönetimi bölümünden adayları inceleyip onaylayın.</p>
        <?php endif; ?>

        <?php foreach ($rules as $rule): ?>
            <article class="site-card">
                <h3><?= e($rule['ip_address']) ?></h3>
                <p><?= e($rule['website_name']) ?> — <?= e($rule['domain']) ?></p>
                <p>Risk: <?= (int) $rule['risk_score'] ?>/100 · Kanıt: <?= (int) $rule['evidence_count'] ?></p>
                <form method="post" action="/google/apply">
                    <?= csrf_field() ?>
                    <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                    <input type="hidden" name="scope" value="account">
                    <h4>Hesap genelinde (tüm kampanyalar)</h4>
                    <?php if ($writesEnabled): ?>
                        <label><input type="checkbox" name="confirm_live" value="1" required> Bu IP adresini Google Ads hesabının tamamında gerçekten hariç tutmayı onaylıyorum.</label>
                        <button type="submit">Hesap genelinde uygula</button>
                    <?php else: ?>
                        <p>Yalnızca doğrulama yapılır; hesapta değişiklik oluşturulmaz.</p>
                        <button type="submit">Hesap genelinde doğrula</button>
                    <?php endif; ?>
                </form>
                <?php if ($campaigns): ?>
                    <form method="post" action="/google/apply">
                        <?= csrf_field() ?>
                        <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                        <input type="hidden" name="scope" value="campaign">
                        <label>Yalnızca kampanya</label>
                        <select name="campaign_id" required>
                            <option value="">Kampanya seçin</option>
                            <?php foreach ($campaigns as $campaign): ?>
                                <?php if ($campaign['status'] === 'ENABLED'): ?>
                                    <option value="<?= e($campaign['id']) ?>"><?= e($campaign['name']) ?> (<?= e($campaign['id']) ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($writesEnabled): ?>
                            <label><input type="checkbox" name="confirm_live" value="1" required> Bu IP adresini seçili kampanyada gerçekten hariç tutmayı onaylıyorum.</label>
                            <button type="submit">Kampanyaya uygula</button>
                        <?php else: ?>
                            <p>Yalnızca doğrulama yapılır; kampanyada değişiklik oluşturulmaz.</p>
                            <button type="submit">Kampanya doğrula</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2>Google Ads işlem geçmişi</h2>
    <?php if (!$history): ?>
        <p>Henüz Google Ads API işlemi yapılmadı.</p>
    <?php else: ?>
        <?php foreach ($history as $item): ?>
            <article class="site-card">
                <strong><?= e($item['ip_address']) ?></strong>
                <p>Müşteri: <?= e($item['customer_id']) ?> · Kapsam: <?= $item['campaign_id'] === 'ACCOUNT' ? 'Hesap geneli' : e($item['campaign_id']) ?></p>
                <p>İşlem: <?= e($item['action']) ?> · Sonuç: <?= e($item['status']) ?> · <?= e($item['created_at']) ?></p>
                <?php if (!empty($item['error_message'])): ?><p class="alert"><?= e($item['error_message']) ?></p><?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
