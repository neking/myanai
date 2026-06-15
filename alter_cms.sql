-- ════════════════════════════════════════════════════
--  NoodleHaus Phase 1 CMS — site_settings table
--  phpMyAdmin → noodlehaus → SQL tab → paste → Go
-- ════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key   VARCHAR(80)  NOT NULL,
    setting_value TEXT         DEFAULT NULL,
    label         VARCHAR(120) NOT NULL DEFAULT '',
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_settings (setting_key, setting_value, label) VALUES
('store_name',        'NoodleHaus',                                   'Store Name'),
('store_emoji',       '🍜',                                           'Store Emoji'),
('hero_badge',        '🔥 Live Kitchen — 20 min delivery',            'Hero Badge Text'),
('hero_title_line1',  'Authentic Asian',                              'Hero Title Line 1'),
('hero_title_line2',  'Noodles & More',                              'Hero Title Line 2'),
('hero_subtitle',     'Freshly prepared, delivered hot. Order online, pay instantly.', 'Hero Subtitle'),
('open_hours',        'Open until 10 PM',                             'Open Hours Text'),
('delivery_fee',      '150',                                          'Delivery Fee (cents, e.g. 150 = $1.50)'),
('delivery_label',    '20 min delivery',                              'Delivery Label'),
('announcement_text', '',                                             'Announcement Banner Text'),
('announcement_color','#e84c2b',                                      'Announcement Color'),
('announcement_on',   '0',                                            'Announcement Active (0/1)'),
('footer_phone',      '',                                             'Footer Phone'),
('footer_address',    '',                                             'Footer Address'),
('footer_facebook',   '',                                             'Footer Facebook URL'),
('footer_instagram',  '',                                             'Footer Instagram URL'),
('footer_copyright',  '© 2025 NoodleHaus. All rights reserved.',     'Footer Copyright')
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- Footer appearance keys (run if already created site_settings table)
INSERT IGNORE INTO site_settings (setting_key, setting_value, label) VALUES
('footer_tiktok',     '',          'Footer TikTok URL'),
('footer_bg_color',   '#1a1209',   'Footer Background Color'),
('footer_bg_opacity', '1',         'Footer Background Opacity'),
('footer_bg_image',   '',          'Footer Background Image Path'),
('footer_logo_image', '',          'Footer Logo Image Path');

-- Header appearance keys
INSERT IGNORE INTO site_settings (setting_key, setting_value, label) VALUES
('header_bg_color',         '#1a1209',  'Header Background Color'),
('header_logo_text_color',  '#f0a500',  'Header Logo Accent Color'),
('header_text_color',       '#b8a48a',  'Header Status Text Color'),
('header_bg_img_opacity',   '0.2',      'Header Image Opacity'),
('header_bg_image',         '',         'Header Background Image Path');

-- Hero appearance keys
INSERT IGNORE INTO site_settings (setting_key, setting_value, label) VALUES
('hero_bg_color',         '',       'Hero Background Color'),
('hero_bg_image',         '',       'Hero Background Image Path'),
('hero_bg_img_opacity',   '0.3',    'Hero Image Opacity'),
('hero_title_color',      '#ffffff','Hero Title Color'),
('hero_subtitle_color',   '#b8a48a','Hero Subtitle Color'),
('hero_badge_color',      '#f0a500','Hero Badge Color'),
('hero_emoji',            '🍜',     'Hero Watermark Emoji');
