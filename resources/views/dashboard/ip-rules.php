<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IP Yönetimi - AdGuard</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="dashboard-header">
    <a href="/dashboard">AdGuard</a>
    <nav>
        <a href="/dashboard">Panel</a>
        <a href="/sites">Sitelerim</a>
        <a href="/ip-rules">IP Yönetimi</a>
        <form method="post" action="/logout"><?= csrf_field() ?><button type="submit">Çıkış</button></form>
    </nav>
</header>
<main class="container">
    <h1>IP Yönetimi</h1>
    <p>Buradaki kurallar AdGuard içindeki inceleme ve karar kayıtlarıdır. Google Ads hesabına otomatik gönderim yapılmaz.</p>

    <?php if (!empty($_SESSION['error'])): ?>
        <p class="alert"><?= e($_SESSION['error']) ?></p>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['success'])): ?>
        <p class="success"><?= e($_SESSION['success']) ?></p>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <section class="site-card">
        <h2>Manuel IP kuralı ekle</h2>
        <?php if (!$websites): ?>
            <p>Önce bir web sitesi eklemelisiniz.</p>
        <?php else: ?>
            <form method="post" action="/ip-rules">
                <?= csrf_field() ?>
                <label>Web sitesi</label>
                <select name="website_id" required>
                    <option value="">Web sitesi seçin</option>
                    <?php foreach ($websites as $website): ?>
                        <option value="<?= (int) $website['id'] ?>"><?= e($website['name']) ?> — <?= e($website['domain']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>IP adresi</label>
                <input name="ip_address" type="text" placeholder="203.0.113.10 veya IPv6" required>
                <label>Kural türü</label>
                <select name="rule_type" required>
                    <option value="deny">Engelleme adayı / deny</option>
                    <option value="allow">İzin ver / allow</option>
                </select>
                <label>Açıklama</label>
                <textarea name="reason" rows="3" maxlength="1000" required></textarea>
                <label>Son geçerlilik (isteğe bağlı)</label>
                <input name="expires_at" type="datetime-local">
                <button type="submit">Kuralı kaydet</button>
            </form>
        <?php endif; ?>
    </section>

    <h2>IP kuralları ve inceleme adayları</h2>
    <?php if (!$rules): ?>
        <p>Henüz IP kuralı veya inceleme adayı yok.</p>
    <?php endif; ?>
    <?php foreach ($rules as $rule): ?>
        <article class="site-card">
            <h3><?= e($rule['ip_address']) ?></h3>
            <p><?= e($rule['website_name']) ?> — <?= e($rule['domain']) ?></p>
            <p>
                Tür: <strong><?= e($rule['rule_type']) ?></strong> ·
                Durum: <strong><?= e($rule['status']) ?></strong> ·
                Kaynak: <?= e($rule['source']) ?>
            </p>
            <p>Risk: <?= (int) $rule['risk_score'] ?>/100 · Kanıt: <?= (int) $rule['evidence_count'] ?></p>
            <p><?= e($rule['reason']) ?></p>
            <?php if ($rule['status'] === 'pending' && $rule['source'] === 'automatic'): ?>
                <form method="post" action="/ip-rules/approve">
                    <?= csrf_field() ?>
                    <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                    <button type="submit">İnceleyip onayla</button>
                </form>
            <?php elseif ($rule['status'] === 'active'): ?>
                <form method="post" action="/ip-rules/revoke">
                    <?= csrf_field() ?>
                    <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                    <button type="submit">Kuralı pasife al</button>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</main>
</body>
</html>
