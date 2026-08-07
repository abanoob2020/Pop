-- =============================================================================
-- xcamp-gym-sql : 18_recipes.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- مكتبة الوصفات وحاسبة الحصص: مكوّنات (ماكروز لكل 100غ نيّئ) + وصفات + كميات،
-- مع حساب دقيق لأي وزن حصة مطلوب. الإصلاح المحترف: القسمة على **الوزن المطبوخ**
-- (الحصيلة cooked_weight_grams) لا مجموع الخام — لأن الطبخ يغيّر الوزن (الأرز
-- يمتصّ ماءً واللحم يفقده)، والماكروز محفوظة فتُوزَّع على وزن الطبق المطبوخ.
--   ingredients          مكوّن + ماكروز/100غ (نيّئ) + علامة مطبخ عربي.
--   recipes              وصفة + الوزن المطبوخ (المقام الصحيح) + حصص افتراضية.
--   recipe_ingredients   كمية كل مكوّن بالجرام (خام).
-- بذر مكوّنات شائعة + طبقين عربيين (مندي/كبسة) مع أوزانهما المطبوخة.
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS + INSERT IGNORE (اسم فريد).
-- =============================================================================

USE xcamp_gym;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ingredients (
    ingredient_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name               VARCHAR(150)    NOT NULL,
    unit               VARCHAR(30)     NOT NULL DEFAULT 'grams',
    calories_per_100g  DECIMAL(8,2)    NOT NULL DEFAULT 0,
    protein_per_100g   DECIMAL(8,2)    NOT NULL DEFAULT 0,
    carbs_per_100g     DECIMAL(8,2)    NOT NULL DEFAULT 0,
    fats_per_100g      DECIMAL(8,2)    NOT NULL DEFAULT 0,
    is_arabic          TINYINT(1)      NOT NULL DEFAULT 1,
    active             TINYINT(1)      NOT NULL DEFAULT 1,
    created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ingredient_id),
    UNIQUE KEY uq_ing_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipes (
    recipe_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                VARCHAR(150)    NOT NULL,
    cuisine_type        VARCHAR(50)     NOT NULL DEFAULT 'Middle Eastern',
    cooked_weight_grams DECIMAL(10,2)   NULL,          -- الوزن المطبوخ (الحصيلة) — المقام الصحيح
    default_servings    INT             NOT NULL DEFAULT 1,
    description         VARCHAR(300)    NULL,
    active              TINYINT(1)      NOT NULL DEFAULT 1,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (recipe_id),
    UNIQUE KEY uq_recipe_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_ingredients (
    recipe_ingredient_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipe_id            BIGINT UNSIGNED NOT NULL,
    ingredient_id        BIGINT UNSIGNED NOT NULL,
    amount_grams         DECIMAL(10,2)   NOT NULL,      -- كمية المكوّن (خام)
    PRIMARY KEY (recipe_ingredient_id),
    UNIQUE KEY uq_recipe_ing (recipe_id, ingredient_id),
    CONSTRAINT fk_ri_recipe FOREIGN KEY (recipe_id) REFERENCES recipes (recipe_id) ON DELETE CASCADE,
    CONSTRAINT fk_ri_ing    FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── بذر المكوّنات (ماكروز تقريبية لكل 100غ نيّئ) ──
INSERT IGNORE INTO ingredients (name, calories_per_100g, protein_per_100g, carbs_per_100g, fats_per_100g) VALUES
  ('أرز بسمتي (خام)',      350, 7.1, 78.0, 0.9),
  ('صدور دجاج (نيّئ)',     120, 22.5, 0.0, 2.6),
  ('لحم ضأن (نيّئ)',       250, 17.0, 0.0, 21.0),
  ('زيت نباتي',            884, 0.0,  0.0, 100.0),
  ('زيت زيتون',            884, 0.0,  0.0, 100.0),
  ('بصل',                  40,  1.1,  9.3, 0.1),
  ('طماطم',                18,  0.9,  3.9, 0.2),
  ('بهارات مندي',          300, 10.0, 45.0, 8.0),
  ('بهارات كبسة',          300, 11.0, 44.0, 9.0),
  ('بيض',                  143, 12.6, 0.7, 9.5),
  ('شوفان',                389, 16.9, 66.3, 6.9),
  ('زبادي',                61,  3.5,  4.7, 3.3);

-- ── بذر الوصفات (مع الوزن المطبوخ = المقام الصحيح) ──
INSERT IGNORE INTO recipes (name, cuisine_type, cooked_weight_grams, default_servings, description) VALUES
  ('مندي دجاج', 'Middle Eastern', 1400.00, 4, 'أرز مندي مع دجاج — طبق أساسي.'),
  ('كبسة لحم',  'Middle Eastern', 2000.00, 5, 'كبسة أرز مع لحم ضأن وخضار.');

-- ── ربط المكوّنات بالوصفات (كميات خام) ──
INSERT IGNORE INTO recipe_ingredients (recipe_id, ingredient_id, amount_grams)
SELECT r.recipe_id, i.ingredient_id, x.amt
FROM (
      SELECT 'مندي دجاج' rn, 'أرز بسمتي (خام)' inn, 400 amt
UNION ALL SELECT 'مندي دجاج', 'صدور دجاج (نيّئ)', 500
UNION ALL SELECT 'مندي دجاج', 'زيت نباتي',       30
UNION ALL SELECT 'مندي دجاج', 'بهارات مندي',     10
UNION ALL SELECT 'كبسة لحم',  'أرز بسمتي (خام)', 500
UNION ALL SELECT 'كبسة لحم',  'لحم ضأن (نيّئ)',  600
UNION ALL SELECT 'كبسة لحم',  'طماطم',           200
UNION ALL SELECT 'كبسة لحم',  'بصل',             150
UNION ALL SELECT 'كبسة لحم',  'زيت نباتي',       40
UNION ALL SELECT 'كبسة لحم',  'بهارات كبسة',     15
) x
JOIN recipes r     ON r.name = x.rn
JOIN ingredients i ON i.name = x.inn;
