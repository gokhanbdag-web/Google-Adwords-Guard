<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IP yönetimi - AdGuard</title><link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>"></head>
<body><main class="container"><h1>IP yönetimi</h1><p><a href="<?= e(url('/dashboard')) ?>">Panel</a> · <a href="<?= e(url('/visits')) ?>">Ziyaretler</a></p><?php if(!empty($_SESSION['success'])): ?><p class="success"><?= e($_SESSION['success']) ?></p><?php unset($_SESSION['success']); endif; ?><?php if(!empty($_SESSION['error'])): ?><p class="alert"><?= e($_SESSION['error']) ?></p><?php unset($_SESSION['error']); endif; ?><a class="button" href="<?= e(url('/ip-rules/create')) ?>">Manuel IP kuralı ekle</a><form method="get"><label>Web sitesi</label><select name="website_id"><option value="0">Tüm siteler</option><?php foreach($websites as $site): ?><option value="<?= (int)$site['id'] ?>" <?= (int)($_GET['website_id']??0)===(int)$site['id']?'selected':'' ?>><?= e($site['name']) ?></option><?php endforeach; ?></select><button type="submit">Filtrele</button></form><table><thead><tr><th>IP</th><th>Site</th><th>Kural</th><th>Durum</th><th>Kaynak</th><th>Risk</th><th>İşlem</th></tr></thead><tbody><?php foreach($rules as $rule): ?><tr><td><?= e($rule['ip_text']) ?></td><td><?= e($rule['website_name']) ?></td><td><?= e($rule['rule_type']) ?></td><td><?= e($rule['status']) ?></td><td><?= e($rule['source']) ?></td><td><?= (int)$rule['risk_score'] ?> / <?= (int)$rule['evidence_count'] ?></td><td><?php if($rule['status']==='pending' && $rule['rule_type']==='candidate'): ?><form method="post" action="<?= e(url('/ip-rules/approve')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>"><button type="submit">Onayla</button></form><?php endif; ?><?php if(in_array($rule['status'],['active','pending'],true)): ?><form method="post" action="<?= e(url('/ip-rules/revoke')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>"><button type="submit">İptal</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
<h2>IP kuralı geçmişi</h2>
<?php if (!$history): ?><p>Henüz IP kuralı değişikliği kaydı yok.</p><?php endif; ?>
<?php foreach ($history as $item): ?>
    <article class="site-card">
        <strong><?= e($item['ip_text']) ?></strong>
        <p><?= e($item['website_name']) ?> · <?= e($item['action']) ?> · <?= e($item['created_at']) ?></p>
        <p><?= e($item['old_rule_type'] ?? '—') ?> / <?= e($item['old_status'] ?? '—') ?> → <?= e($item['new_rule_type'] ?? '—') ?> / <?= e($item['new_status'] ?? '—') ?></p>
        <p><?= e($item['reason'] ?? '') ?></p>
    </article>
<?php endforeach; ?>
</main></body></html>
