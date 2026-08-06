-- =============================================================================
-- xcamp-gym-sql : 15_referrals.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- الإحالات وأكواد الخصم: كود خصم يُطبَّق في نقطة البيع أو الاشتراكات. الكود قد
-- يكون عامًّا (تسويقي) أو مملوكًا لعضو (كود إحالة) — عند استخدامه يُكافأ صاحبه.
--   discount_codes        الأكواد: نسبة/مبلغ، النطاق، الحد، المالك، مكافأة الإحالة.
--   discount_redemptions  سجلّ كل استخدام (الأساس/الخصم/الصافي) — تدقيق.
-- نبذر كود إحالة لكل عضو حالي + كودين تسويقيين للتجربة الفورية.
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS + بذر محروس بـ INSERT IGNORE.
-- =============================================================================

USE xcamp_gym;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS discount_codes (
    code_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code             VARCHAR(40)     NOT NULL,
    kind             ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    value            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- نسبة% أو مبلغ ثابت
    scope            ENUM('membership','pos','both') NOT NULL DEFAULT 'both',
    owner_member_id  BIGINT UNSIGNED NULL,                     -- كود إحالة إن وُجد مالك
    reward_value     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- مكافأة المالك لكل استخدام
    max_uses         INT             NOT NULL DEFAULT 0,       -- 0 = بلا حدّ
    used_count       INT             NOT NULL DEFAULT 0,
    active           TINYINT(1)      NOT NULL DEFAULT 1,
    expires_on       DATE            NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (code_id),
    UNIQUE KEY uq_code (code),
    KEY idx_owner (owner_member_id),
    CONSTRAINT fk_dc_owner FOREIGN KEY (owner_member_id) REFERENCES members (member_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discount_redemptions (
    redemption_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code_id         BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(40)     NOT NULL,                 -- نسخة من الكود وقت الاستخدام
    member_id       BIGINT UNSIGNED NULL,                     -- المستفيد (قد يكون زائرًا)
    context         ENUM('membership','pos') NOT NULL,
    base_amount     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    final_amount    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (redemption_id),
    KEY idx_red_code (code_id),
    KEY idx_red_member (member_id),
    CONSTRAINT fk_dr_code FOREIGN KEY (code_id) REFERENCES discount_codes (code_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- كود إحالة لكل عضو: REF-XXXX (خصم 10% للمُحال + 50 ج.م مكافأة للمُحيل)
INSERT IGNORE INTO discount_codes (code, kind, value, scope, owner_member_id, reward_value, max_uses)
SELECT CONCAT('REF-', UPPER(SUBSTRING(SHA2(CONCAT('xcg-ref-', member_id), 256), 1, 6))),
       'percent', 10.00, 'both', member_id, 50.00, 0
FROM members;

-- كودان تسويقيان عامّان للتجربة
INSERT IGNORE INTO discount_codes (code, kind, value, scope, max_uses, active) VALUES
  ('WELCOME10', 'percent', 10.00, 'both', 0, 1),
  ('SUMMER50',  'fixed',   50.00, 'pos',  100, 1);
