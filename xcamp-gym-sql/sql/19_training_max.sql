-- =============================================================================
-- xcamp-gym-sql : 19_training_max.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- المحرّك التكيّفي للأحمال: يسجّل أداء مجموعة اختبار (وزن/تكرار/RPE/RIR) لكل
-- عضو/تمرين، يحسب 1RM المُقدَّر، ويقترح **وزن العمل** القادم. سجلّ إلحاقي (append)
-- فيعطي القيمة الحالية والسابقة (حماية) وتاريخًا للاتجاه.
--   training_max  صف لكل قياس: الأداء + 1RM + وزن العمل المقترح + القرار.
--
-- الإصلاح المحترف: وزن العمل القادم يُشتقّ من **الوزن المُؤدَّى** لا من الـ1RM
-- (الـ1RM حِمل تكرار واحد، ليس وزن عمل). يُحسب في adaptive_load_decision().
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS.
-- =============================================================================

USE xcamp_gym;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS training_max (
    max_id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id         BIGINT UNSIGNED NOT NULL,
    exercise_id       BIGINT UNSIGNED NOT NULL,
    formula           ENUM('epley','brzycki') NOT NULL DEFAULT 'epley',
    performed_weight  DECIMAL(6,2)    NOT NULL,
    reps              SMALLINT UNSIGNED NOT NULL,
    rpe               TINYINT UNSIGNED NOT NULL,        -- 1..10
    rir               TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0..5
    one_rep_max       DECIMAL(6,2)    NOT NULL,          -- 1RM المُقدَّر
    next_weight       DECIMAL(6,2)    NOT NULL,          -- وزن العمل المقترح للأسبوع القادم
    action            ENUM('up','hold','warn') NOT NULL DEFAULT 'hold',
    calculated_on     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (max_id),
    KEY idx_tm_member_ex (member_id, exercise_id, calculated_on),
    CONSTRAINT fk_tm_member   FOREIGN KEY (member_id)   REFERENCES members (member_id) ON DELETE CASCADE,
    CONSTRAINT fk_tm_exercise FOREIGN KEY (exercise_id) REFERENCES exercises (exercise_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
