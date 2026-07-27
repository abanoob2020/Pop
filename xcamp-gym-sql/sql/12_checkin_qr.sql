-- =============================================================================
-- xcamp-gym-sql : 12_checkin_qr.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- الحضور بالـ QR: رمز فريد لكل عضو يُمسح عند الدخول → حضور تلقائي (daily_attendance).
--   member_qr   رمز الدخول (token) لكل عضو
-- تسجيل الحضور يُكتب في daily_attendance الموجود بالمخطط فتعمل أتمتة المتابعة.
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS.
-- =============================================================================

USE xcamp_gym;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS member_qr (
    member_id  BIGINT UNSIGNED NOT NULL,
    token      VARCHAR(32)     NOT NULL,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id),
    UNIQUE KEY uq_member_qr_token (token),
    CONSTRAINT fk_member_qr_member FOREIGN KEY (member_id)
        REFERENCES members (member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
