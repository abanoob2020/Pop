-- =============================================================================
-- xcamp-gym-sql : 11_finance_pos.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- المحاسبة ونقطة البيع (تُكمّل جدول payments الأصلي — لا تُنشئه من جديد):
--   products         كتالوج المنتجات + المخزون (مكمّلات/مشروبات/ملابس/خدمات)
--   pos_sales        فواتير البيع (إجمالي/طريقة الدفع/الكاشير/العضو اختياري)
--   pos_sale_items   بنود كل فاتورة (لقطة اسم/سعر المنتج + الكمية)
--   expenses         سجلّ المصروفات حسب الفئة
-- دفعات الاشتراكات تُسجَّل في جدول payments الأصلي الموجود بالمخطط.
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS + INSERT IGNORE بمعرّفات ثابتة.
-- =============================================================================

USE xcamp_gym;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    product_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120)    NOT NULL,
    category    ENUM('supplement','drink','apparel','accessory','service','other') NOT NULL DEFAULT 'other',
    price       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    stock_qty   INT             NOT NULL DEFAULT 0,
    active      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_sales (
    sale_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id       BIGINT UNSIGNED NULL,
    cashier_user_id BIGINT UNSIGNED NULL,
    total           DECIMAL(10,2)   NOT NULL,
    method          ENUM('cash','card','bank_transfer','wallet','other') NOT NULL DEFAULT 'cash',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (sale_id),
    KEY idx_pos_member (member_id),
    CONSTRAINT fk_pos_member FOREIGN KEY (member_id) REFERENCES members (member_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_sale_items (
    sale_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_id      BIGINT UNSIGNED NOT NULL,
    product_id   BIGINT UNSIGNED NULL,
    product_name VARCHAR(120)    NOT NULL,
    qty          INT             NOT NULL,
    unit_price   DECIMAL(10,2)   NOT NULL,
    PRIMARY KEY (sale_item_id),
    KEY idx_item_sale (sale_id),
    CONSTRAINT fk_item_sale FOREIGN KEY (sale_id) REFERENCES pos_sales (sale_id) ON DELETE CASCADE,
    CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
    expense_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category    ENUM('rent','salaries','equipment','utilities','marketing','maintenance','supplies','other') NOT NULL DEFAULT 'other',
    amount      DECIMAL(10,2)   NOT NULL,
    description VARCHAR(200)    NULL,
    spent_on    DATE            NOT NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (expense_id),
    KEY idx_exp_date (spent_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- كتالوج منتجات ابتدائي
INSERT IGNORE INTO products (product_id, name, category, price, stock_qty) VALUES
 (1, 'واي بروتين 1kg',        'supplement', 1500.00, 20),
 (2, 'كرياتين 300g',           'supplement',  600.00, 15),
 (3, 'مشروب طاقة',             'drink',        50.00, 100),
 (4, 'زجاجة مياه',             'drink',        15.00, 200),
 (5, 'تيشيرت الجيم',           'apparel',     350.00, 30),
 (6, 'قفازات تمرين',           'accessory',   250.00, 25),
 (7, 'حصة يومية (Day Pass)',   'service',     100.00, 999);
