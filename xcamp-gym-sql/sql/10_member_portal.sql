-- =============================================================================
-- xcamp-gym-sql : 10_member_portal.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- بوابة العضو: مصادقة العضو للدخول بنفسه (منفصلة عن جدول users للموظّفين).
--   member_auth   بيانات دخول العضو (bcrypt) + تفعيل البوابة + آخر دخول
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS.
-- =============================================================================

USE xcamp_gym;

-- يضمن قراءة النصوص العربية في هذا الملف بشكل صحيح أيًّا كان ترميز العميل
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS member_auth (
    member_id       BIGINT UNSIGNED NOT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    portal_enabled  TINYINT(1)      NOT NULL DEFAULT 1,
    last_login_at   DATETIME        NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id),
    CONSTRAINT fk_member_auth_member FOREIGN KEY (member_id)
        REFERENCES members (member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
