-- Phase 2B: site_settings table (ရှိပြီးသားဆိုရင် skip)
CREATE TABLE IF NOT EXISTS site_settings (
  id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  setting_key   VARCHAR(80)     NOT NULL,
  setting_value TEXT            NOT NULL DEFAULT '',
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default township fees (1 Ks each for testing)
INSERT INTO site_settings (setting_key, setting_value)
VALUES ('township_fees', '{
  "ဗဟန်း": 1,
  "လှိုင်": 1,
  "မရမ်းကုန်း": 1,
  "ကမာရွတ်": 1,
  "စမ်းချောင်း": 1,
  "ရန်ကင်း": 1,
  "ဒဂုံ": 1,
  "သာကေတ": 1,
  "တောင်ဥက္ကလာ": 1,
  "မြောက်ဥက္ကလာ": 1,
  "လိုက်ဆော်": 1,
  "ပုဇွန်တောင်": 1
}')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Default promo codes (3 types)
INSERT INTO site_settings (setting_key, setting_value)
VALUES ('promo_codes', '[
  {"code":"SAVE500","type":"fixed","value":500,"label":"500 Ks off"},
  {"code":"RAINY20","type":"percent","value":20,"label":"20% off"},
  {"code":"FREESHIP","type":"free_ship","value":0,"label":"Free delivery"}
]')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
