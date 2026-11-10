
USE adguard;

INSERT INTO plans
(name, slug, description, price, currency, billing_period,
 daily_visit_limit, website_limit, features, is_active, sort_order)
VALUES
('Deneme', 'deneme', 'Yeni hesaplar için 7 günlük ücretsiz deneme', 0.00, 'TRY',
 'monthly', 1000, 1, JSON_ARRAY('Trafik analizi','1 web sitesi','IP risk analizi'), 1, 0),
('Başlangıç', 'baslangic', 'Temel trafik analizi', 299.00, 'TRY',
 'monthly', 1000, 1, JSON_ARRAY('Ziyaret analizi','IP geçmişi'), 1, 1),
('Profesyonel', 'profesyonel', 'Gelişmiş trafik koruması', 799.00, 'TRY',
 'monthly', 10000, 5, JSON_ARRAY('Gelişmiş analiz','Google Ads bağlantısı'), 1, 2),
('Kurumsal', 'kurumsal', 'Yüksek hacimli trafik analizi', 1999.00, 'TRY',
 'monthly', 50000, 20, JSON_ARRAY('Çoklu site','Gelişmiş raporlama'), 1, 3)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    price = VALUES(price),
    currency = VALUES(currency),
    billing_period = VALUES(billing_period),
    daily_visit_limit = VALUES(daily_visit_limit),
    website_limit = VALUES(website_limit),
    features = VALUES(features),
    is_active = VALUES(is_active),
    sort_order = VALUES(sort_order);

INSERT INTO pages
(title, slug, content, status, meta_title, meta_description)
VALUES
('Ana Sayfa', 'home',
 '<h1>Reklam trafiğinizi analiz edin</h1><p>Şüpheli ziyaretleri tespit edin ve yönetin.</p>',
 'published', 'AdGuard - Trafik Koruma', 'Google Ads trafik analizi ve IP yönetimi'),
('Gizlilik Politikası', 'privacy',
 '<h1>Gizlilik Politikası</h1><p>Gizlilik politikası içeriği yönetici panelinden güncellenebilir.</p>',
 'published', 'Gizlilik Politikası', 'AdGuard gizlilik politikası')
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    content = VALUES(content),
    status = VALUES(status),
    meta_title = VALUES(meta_title),
    meta_description = VALUES(meta_description);

INSERT INTO menus
(location, label, url, sort_order, is_active)
VALUES
('header', 'Ana Sayfa', '/', 1, 1),
('header', 'Giriş Yap', '/login', 2, 1),
('header', 'Kayıt Ol', '/register', 3, 1),
('footer', 'Gizlilik Politikası', '/privacy', 1, 1);

INSERT INTO settings (setting_key, setting_value)
VALUES
('site_name', 'AdGuard'),
('support_email', ''),
('header_html', ''),
('footer_html', ''),
('maintenance_mode', '0')
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value);
