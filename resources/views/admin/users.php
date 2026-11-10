<?php
$adminTitle='Kullanıcı Yönetimi';
$adminActive='users';
require BASE_PATH.'/resources/views/admin/_header.php';
$fmtDate=static function($value): string { return $value ? date('d.m.Y H:i',strtotime((string)$value)) : '—'; };
?>
<section class="admin-stats">
  <article class="site-card"><strong><?= (int)($counts['total']??0) ?></strong><span>Toplam kullanıcı</span></article>
  <article class="site-card"><strong><?= (int)($counts['active']??0) ?></strong><span>Aktif kullanıcı</span></article>
  <article class="site-card"><strong><?= (int)($counts['suspended']??0) ?></strong><span>Askıya alınan</span></article>
  <article class="site-card"><strong><?= (int)($counts['admins']??0) ?></strong><span>Aktif yönetici</span></article>
</section>

<section class="site-card">
  <h2>Yeni kullanıcı oluştur</h2>
  <form method="post" action="<?= e(url('/admin/users/store')) ?>">
    <?= csrf_field() ?>
    <label for="new-name">Ad soyad</label><input id="new-name" name="name" maxlength="120" required>
    <label for="new-email">E-posta</label><input id="new-email" type="email" name="email" maxlength="190" required>
    <label for="new-password">Geçici şifre (en az 12 karakter)</label><input id="new-password" type="password" name="password" minlength="12" autocomplete="new-password" required>
    <label for="new-role">Rol</label><select id="new-role" name="role"><option value="customer">Müşteri</option><option value="admin">Yönetici</option></select>
    <label for="new-status">Durum</label><select id="new-status" name="status"><option value="active">Aktif</option><option value="pending">Beklemede</option><option value="suspended">Askıda</option></select>
    <button type="submit">Kullanıcı oluştur</button>
  </form>
</section>

<section class="site-card">
  <h2>Kullanıcıları bul</h2>
  <form method="get" action="<?= e(url('/admin/users')) ?>">
    <label for="user-q">Ad, e-posta veya kullanıcı ID</label><input id="user-q" name="q" value="<?= e($search) ?>" placeholder="Örn. 24 veya e-posta">
    <label for="user-status">Durum</label><select id="user-status" name="status"><option value="">Tüm durumlar</option><option value="active" <?= $status==='active'?'selected':'' ?>>Aktif</option><option value="pending" <?= $status==='pending'?'selected':'' ?>>Beklemede</option><option value="suspended" <?= $status==='suspended'?'selected':'' ?>>Askıda</option></select>
    <label for="user-role">Rol</label><select id="user-role" name="role"><option value="">Tüm roller</option><option value="customer" <?= $role==='customer'?'selected':'' ?>>Müşteri</option><option value="admin" <?= $role==='admin'?'selected':'' ?>>Yönetici</option></select>
    <button type="submit">Filtrele</button> <a href="<?= e(url('/admin/users')) ?>">Filtreleri temizle</a>
  </form>
</section>

<h2>Kayıtlı kullanıcılar (en fazla 500 kayıt)</h2>
<?php if (!$users): ?><p>Bu filtrelerle eşleşen kullanıcı bulunamadı.</p><?php endif; ?>
<?php foreach ($users as $user): $isSelf=(int)$user['id']===(int)(auth()['id']??0); ?>
<section class="site-card">
  <div class="admin-user-summary">
    <h3>#<?= (int)$user['id'] ?> · <?= e($user['name']) ?></h3>
    <p><?= e($user['email']) ?> · <?= $user['role']==='admin'?'Yönetici':'Müşteri' ?> · <?= $user['status']==='active'?'Aktif':($user['status']==='pending'?'Beklemede':'Askıya alınmış') ?></p>
    <p>Site: <?= (int)$user['website_count'] ?> · Abonelik kaydı: <?= (int)$user['subscription_count'] ?> · Aktif paket: <?= e($user['active_plan'] ?: 'Yok') ?></p>
    <p>Kayıt: <?= e($fmtDate($user['created_at'])) ?> · Son giriş: <?= e($fmtDate($user['last_login_at'])) ?></p>
  </div>
  <details>
    <summary>Kullanıcı bilgilerini düzenle</summary>
    <form method="post" action="<?= e(url('/admin/users/update')) ?>">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
      <label>Ad soyad</label><input name="name" maxlength="120" value="<?= e($user['name']) ?>" required>
      <label>E-posta</label><input type="email" name="email" maxlength="190" value="<?= e($user['email']) ?>" required>
      <label>Rol</label><select name="role" <?= $isSelf?'disabled':'' ?>><option value="customer" <?= $user['role']==='customer'?'selected':'' ?>>Müşteri</option><option value="admin" <?= $user['role']==='admin'?'selected':'' ?>>Yönetici</option></select>
      <?php if($isSelf): ?><input type="hidden" name="role" value="admin"><?php endif; ?>
      <label>Durum</label><select name="status" <?= $isSelf?'disabled':'' ?>><option value="active" <?= $user['status']==='active'?'selected':'' ?>>Aktif</option><option value="pending" <?= $user['status']==='pending'?'selected':'' ?>>Beklemede</option><option value="suspended" <?= $user['status']==='suspended'?'selected':'' ?>>Askıya alınmış</option></select>
      <?php if($isSelf): ?><input type="hidden" name="status" value="active"><?php endif; ?>
      <label>Yeni şifre (değiştirmeyeceksen boş bırak; en az 12 karakter)</label><input type="password" name="password" minlength="12" autocomplete="new-password">
      <button type="submit">Değişiklikleri kaydet</button>
    </form>
  </details>
  <?php if(!$isSelf && $user['status']!=='pending'): ?>
  <form method="post" action="<?= e(url('/admin/users/status')) ?>" onsubmit="return confirm('<?= $user['status']==='active'?'Bu kullanıcı askıya alınsın mı?':'Kullanıcı yeniden etkinleştirilsin mi?' ?>');">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><input type="hidden" name="status" value="<?= $user['status']==='active'?'suspended':'active' ?>">
    <button type="submit"><?= $user['status']==='active'?'Hesabı askıya al':'Hesabı etkinleştir' ?></button>
  </form>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php require BASE_PATH.'/resources/views/admin/_footer.php'; ?>
