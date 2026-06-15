-- ════════════════════════════════════════════════════════
--  NoodleHaus — Menu Items Seed Data
--  phpMyAdmin → noodlehaus → SQL tab → ဒါ paste လုပ်ပြီး Go နှိပ်ပါ
-- ════════════════════════════════════════════════════════

INSERT INTO menu_items (id, name, category, description, price, stock_qty, emoji, is_active) VALUES
(1,  'Mohinga',            'Noodles',  'Traditional fish-broth noodle soup',      4500, 20, '🍲', 1),
(2,  'Shan Noodles',       'Noodles',  'Light pork-broth rice noodles',            4000, 15, '🍜', 1),
(3,  'Beef Kway Teow',     'Noodles',  'Stir-fried flat rice noodles with beef',   5500, 10, '🥡', 1),
(4,  'Ramen Bowl',         'Noodles',  'Japanese-style ramen with chashu pork',    6000,  8, '🍥', 1),
(5,  'Char Siu Rice',      'Rice',     'Barbecue pork over steamed jasmine rice',  5000, 12, '🍚', 1),
(6,  'Coconut Rice',       'Rice',     'Fragrant coconut jasmine rice',            3500, 25, '🌾', 1),
(7,  'Fried Rice Deluxe',  'Rice',     'Wok-tossed with egg, prawns, vegetables',  5500, 18, '🍳', 1),
(8,  'Chicken Satay',      'Starters', 'Grilled skewers with peanut sauce',        4000, 30, '🍡', 1),
(9,  'Spring Rolls 6pc',   'Starters', 'Crispy rolls with glass noodles',          3000, 20, '🥢', 1),
(10, 'Tom Yum Soup',       'Soups',    'Hot and sour Thai soup with prawns',       4500, 14, '🫕', 1),
(11, 'Miso Ramen Soup',    'Soups',    'Classic miso broth with tofu',             4000,  3, '🍵', 1),
(12, 'Mango Sticky Rice',  'Desserts', 'Sweet sticky rice with fresh mango',       3000,  0, '🥭', 1),
(13, 'Taro Bubble Tea',    'Drinks',   'Creamy taro milk tea with tapioca',        2500, 40, '🧋', 1),
(14, 'Thai Milk Tea',      'Drinks',   'Spiced black tea with condensed milk',     2000, 50, '🧉', 1),
(15, 'Lychee Soda',        'Drinks',   'Lychee-flavoured sparkling drink 500ml',   1800, 35, '🫧', 1),
(16, 'Pad Thai',           'Noodles',  'Stir-fried rice noodles with peanuts',     6000,  2, '🥘', 1)
ON DUPLICATE KEY UPDATE
    name        = VALUES(name),
    category    = VALUES(category),
    description = VALUES(description),
    price       = VALUES(price),
    stock_qty   = VALUES(stock_qty),
    emoji       = VALUES(emoji),
    is_active   = VALUES(is_active);
