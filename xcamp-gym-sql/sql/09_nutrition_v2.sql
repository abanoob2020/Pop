-- =============================================================================
-- xcamp-gym-sql : 09_nutrition_v2.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- تتبّع الالتزام الغذائي: سجلّ يومي لمدى التزام العضو بخطته الغذائية.
--   nutrition_logs   صف واحد لكل عضو/يوم (on_plan / partial / off_plan) + قيم فعلية
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS.
-- =============================================================================

USE xcamp_gym;

-- يضمن قراءة النصوص العربية في هذا الملف بشكل صحيح أيًّا كان ترميز العميل
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS nutrition_logs (
    nutrition_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id        BIGINT UNSIGNED NOT NULL,
    log_date         DATE            NOT NULL,
    adherence        ENUM('on_plan','partial','off_plan') NOT NULL DEFAULT 'on_plan',
    calories_actual  INT             NULL,
    protein_actual   DECIMAL(6,2)    NULL,
    notes            VARCHAR(255)    NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (nutrition_log_id),
    UNIQUE KEY uq_member_date (member_id, log_date),
    KEY idx_nutrilog_member (member_id),
    CONSTRAINT fk_nutrilog_member FOREIGN KEY (member_id)
        REFERENCES members (member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
