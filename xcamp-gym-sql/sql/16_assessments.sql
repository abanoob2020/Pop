-- =============================================================================
-- xcamp-gym-sql : 16_assessments.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- التقييم الشامل للعضو (Elite Member Assessment): سجلّ تقييم لكل عضو/تاريخ يجمع
-- الفحص الصحي (PAR-Q) + نمط الحياة + الأهداف + تركيب الجسم + المحيطات، ويُطبع
-- بنفس تصميم استمارة XCAMP مع رمز العضو. الأساس الذي تبني عليه الشرائح التالية
-- (FMS/القوام/الاختلالات + الذكاء التصحيحي).
--   member_assessments      السجلّ الأب: نمط حياة + تركيب جسم + هدف + درجات.
--   assessment_parq         إجابات الفحص الصحي (نعم/لا) لكل بند.
--   assessment_measurements محيطات الجسم (يمين/يسار) لكل موضع.
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS.
-- =============================================================================

USE xcamp_gym;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS member_assessments (
    assessment_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id       BIGINT UNSIGNED NOT NULL,
    assessed_by     BIGINT UNSIGNED NULL,                    -- المستخدم (كوتش/إدارة)
    assessment_date DATE            NOT NULL,
    status          ENUM('draft','final') NOT NULL DEFAULT 'draft',
    -- الأهداف
    goal_primary    ENUM('muscle_gain','fat_loss','recomposition','strength','athletic',
                         'health_fitness','rehab','posture','flexibility','other') NULL,
    -- الصحة العامة
    doctor_approval ENUM('yes','no','na') NOT NULL DEFAULT 'na',
    health_notes    VARCHAR(300)    NULL,
    -- نمط الحياة
    sleep_hours     DECIMAL(4,1)    NULL,
    sleep_quality   ENUM('poor','fair','good','excellent') NULL,
    water_liters    DECIMAL(4,1)    NULL,
    daily_steps     INT             NULL,
    sitting_hours   DECIMAL(4,1)    NULL,
    job_type        ENUM('sitting','standing','physical') NULL,
    stress_level    ENUM('low','medium','high') NULL,
    caffeine        ENUM('none','low','moderate','high') NULL,
    smoking         TINYINT(1)      NOT NULL DEFAULT 0,
    alcohol         TINYINT(1)      NOT NULL DEFAULT 0,
    activity_level  ENUM('low','moderate','high') NULL,
    -- تركيب الجسم
    weight_kg       DECIMAL(5,1)    NULL,
    body_fat_pct    DECIMAL(4,1)    NULL,
    muscle_mass_kg  DECIMAL(5,1)    NULL,
    lean_mass_kg    DECIMAL(5,1)    NULL,
    water_pct       DECIMAL(4,1)    NULL,
    bmi             DECIMAL(4,1)    NULL,
    bmr_kcal        INT             NULL,
    tdee_kcal       INT             NULL,
    metabolic_age   INT             NULL,
    -- الخلاصة
    overall_score   INT             NULL,                     -- 0..100
    next_goal       VARCHAR(200)    NULL,
    notes           VARCHAR(500)    NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (assessment_id),
    KEY idx_asm_member (member_id, assessment_date),
    CONSTRAINT fk_asm_member FOREIGN KEY (member_id) REFERENCES members (member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_parq (
    parq_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    item          ENUM('heart_disease','diabetes','respiratory','joint_back_pain','dizziness',
                       'surgery','medication','smoking','pregnancy','allergies') NOT NULL,
    answer        TINYINT(1)      NOT NULL DEFAULT 0,        -- 1 = نعم (علامة حمراء)
    PRIMARY KEY (parq_id),
    UNIQUE KEY uq_parq (assessment_id, item),
    CONSTRAINT fk_parq_asm FOREIGN KEY (assessment_id) REFERENCES member_assessments (assessment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_measurements (
    measurement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id  BIGINT UNSIGNED NOT NULL,
    site           ENUM('neck','shoulder','chest','upper_chest','waist','abdomen','hip','glutes',
                        'arm_relaxed','arm_flexed','forearm','thigh_mid','thigh_upper','calf','ankle') NOT NULL,
    right_cm       DECIMAL(5,1)    NULL,
    left_cm        DECIMAL(5,1)    NULL,
    PRIMARY KEY (measurement_id),
    UNIQUE KEY uq_measure (assessment_id, site),
    CONSTRAINT fk_meas_asm FOREIGN KEY (assessment_id) REFERENCES member_assessments (assessment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
