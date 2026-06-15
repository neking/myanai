-- ════════════════════════════════════════════════
--  Menu items sort order
--  phpMyAdmin → noodlehaus → SQL tab → paste → Go
-- ════════════════════════════════════════════════

-- 1. sort_order column ထည့်
ALTER TABLE menu_items
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER is_active;

-- 2. လက်ရှိ items တွေကို category+name အတိုင်း initial order သတ်မှတ်
SET @r = 0;
UPDATE menu_items
SET sort_order = (@r := @r + 10)
ORDER BY category, name;
