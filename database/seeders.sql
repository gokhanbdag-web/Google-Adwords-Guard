
USE adguard;

INSERT IGNORE INTO plans
(name, slug, description, price, currency, billing_period,
 daily_visit_limit, website_limit, features, is_active, sort_order)
VALUES
('Başlangıç', 'baslangic', 'Temel trafik analizi', 299.00, 'TRY',
 'monthly', 1000, 1, JSON_ARRAY('Ziyaret analizi','IP geçmişi'), 1, 1),
('Profesyonel', 'profesyonel', 'Gelişmiş trafik koruması', 799.00, 'TRY',
 'monthly', 10000, 5, JSON_ARRAY('Gelişmiş analiz','Google Ads bağlantısı'), 1, 2),
('Kurumsal', 'kurumsal', 'Yüksek hacimli trafik analizi', 1999.00, 'TRY',
 'monthly', 50000, 20, JSON_ARRAY('Çoklu site','Gelişmiş raporlama'), 1, 3)
INSERT IGNORE INTO pages
(title, slug, content, status, meta_title, meta_description)
VALUES
('Ana Sayfa', 'home',
 '<h1>Reklam trafiğinizi analiz edin</h1><p>Şüpheli ziyaretleri tespit edin ve yönetin.</p>',
 'published', 'AdGuard - Trafik Koruma', 'Google Ads trafik analizi ve IP yönetimi'),
('Gizlilik Politikası', 'privacy',
 '<h1>Gizlilik ve Kişisel Verilerin İşlenmesi</h1><p><strong>Yayın öncesi inceleme:</strong> Bu metin AdGuard yazılımının varsayılan veri akışını açıklayan bir taslaktır. Hizmeti işleten veri sorumlusu; kendi unvanını, iletişim bilgilerini, işleme amaçlarını, hukuki sebeplerini, alıcı gruplarını ve saklama sürelerini doğrulayıp bu metni yayın öncesinde güncellemelidir. Bu metin tek başına KVKK veya GDPR uyumluluğu garantisi değildir.</p><h2>İşlenen veri kategorileri</h2><p>Hesap ve abonelik bilgileri; kayıt e-posta adresi, parola özeti, abonelik ve ödeme durumu. Ziyaret güvenliği verileri; sunucunun gördüğü IP adresi, kullanıcı aracısı, ziyaret zamanı, sayfa yolu, temizlenmiş yönlendiren adresi, risk puanı ve tespit sinyalleri. Google Ads bağlantısı kullanılırsa müşteri hesabı numarası ve şifreli saklanan OAuth erişim/yenileme belirteçleri. Ödeme sağlayıcısı kullanılırsa sağlayıcı işlem ve bildirim verileri.</p><h2>İşleme amaçları</h2><p>Hesap yönetimi, hizmetin sunulması, kota ve abonelik yönetimi, şüpheli trafik analizi, IP kurallarının incelenmesi, kullanıcının açıkça başlattığı Google Ads hariç tutma işlemlerinin yürütülmesi, ödeme işlemlerinin doğrulanması ve bilgi güvenliği.</p><h2>URL verilerinin azaltılması</h2><p>Ziyaret sayfası ve yönlendiren adresi kaydedilirken sorgu parametreleri ve URL parçaları kaldırılır. Bu önlem URL içinde bulunabilecek e-posta, oturum belirteci veya benzeri bilgilerin gereksiz toplanmasını azaltır. IP adresi ve kullanıcı aracısı yine de kişisel veri sayılabilir.</p><h2>Saklama</h2><p>Ziyaret kayıtlarının varsayılan saklama süresi 90 gündür ve VISIT_RETENTION_DAYS ayarıyla 7 ila 3650 gün arasında yapılandırılabilir. Eski ziyaret kayıtları zamanlanmış cron göreviyle silinir. Hesap, ödeme, denetim ve Google Ads işlem kayıtlarının saklama süreleri hizmet işletmecisi tarafından hukuki yükümlülüklere göre ayrıca belirlenmelidir. Google OAuth bağlantısı kaldırıldığında saklanan belirteçler silinir veya geçersizleştirilir.</p><h2>Hizmet sağlayıcılar ve aktarım</h2><p>Dağıtıma göre barındırma/veritabanı sağlayıcısı, Google Ads API, PayTR ve iyzico ile veri alışverişi yapılabilir. İlgili sağlayıcılar yalnızca etkinleştirilmiş özelliklerde devreye girer. Yurt dışına veri aktarımı ve uygun hukuki mekanizma, hizmet işletmecisi tarafından ayrıca değerlendirilmelidir.</p><h2>Güvenlik</h2><p>Parolalar güçlü tek yönlü özetleme ile saklanır; Google OAuth belirteçleri uygulama anahtarıyla şifrelenir. Yönetim işlemleri oturum ve CSRF kontrolleriyle korunur. Hiçbir teknik önlem mutlak güvenlik sağlamaz.</p><h2>Başvuru ve iletişim</h2><p>İlgili kişi başvuruları ve veri sorumlusu iletişim bilgileri, hizmet işletmecisinin kendi ticari unvanı ve geçerli iletişim kanalı eklenerek burada yayımlanmalıdır. Kullanıcılar, hizmeti kullandıkları web sitesinin kendi gizlilik bildirimini de incelemelidir.</p>',
 'published', 'Gizlilik Politikası', 'AdGuard gizlilik politikası')
INSERT INTO menus (location, label, url, sort_order, is_active)
SELECT 'header', 'Ana Sayfa', '/', 1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE location = 'header' AND url = '/')
UNION ALL
SELECT 'header', 'Giriş Yap', '/login', 2, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE location = 'header' AND url = '/login')
UNION ALL
SELECT 'header', 'Kayıt Ol', '/register', 3, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE location = 'header' AND url = '/register')
UNION ALL
SELECT 'footer', 'Gizlilik Politikası', '/privacy', 1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE location = 'footer' AND url = '/privacy');

INSERT IGNORE INTO settings (setting_key, setting_value)
VALUES
('site_name', 'AdGuard'),
('support_email', ''),
('header_html', ''),
('footer_html', ''),
('maintenance_mode', '0')
;
