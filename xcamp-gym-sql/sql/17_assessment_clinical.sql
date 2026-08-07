-- =============================================================================
-- xcamp-gym-sql : 17_assessment_clinical.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- الطبقة الإكلينيكية للتقييم: فحص الحركة الوظيفية (FMS) + القوام + الاختلالات
-- العضلية. هذه المدخلات تُغذّي محرّك التوصيات (درجة خطر → مرحلة برنامج تلقائية +
-- تركيز تصحيحي + مجموعات يُنصح بتجنّبها) عبر دوال training.php.
--   assessment_fms          11 حركة، درجة 0..3 (0=ألم، 1=عاجز، 2=تعويض، 3=طبيعي).
--   assessment_posture      انحرافات القوام (موجود/غير موجود).
--   assessment_imbalances   لكل منطقة: ضعيف/مشدود.
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS.
-- =============================================================================

USE xcamp_gym;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS assessment_fms (
    fms_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    movement      ENUM('deep_squat','hurdle_step','inline_lunge','shoulder_mobility','active_slr',
                       'trunk_stability','rotary_stability','single_leg_balance','overhead_reach',
                       'hip_hinge','thoracic_rotation') NOT NULL,
    score         TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- 0=ألم .. 3=طبيعي
    PRIMARY KEY (fms_id),
    UNIQUE KEY uq_fms (assessment_id, movement),
    CONSTRAINT fk_fms_asm FOREIGN KEY (assessment_id) REFERENCES member_assessments (assessment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_posture (
    posture_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    finding       ENUM('forward_head','rounded_shoulders','kyphosis','lordosis','anterior_pelvic_tilt',
                       'posterior_pelvic_tilt','knee_valgus','knee_varus','scapular_winging',
                       'leg_length','foot_arch') NOT NULL,
    present       TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (posture_id),
    UNIQUE KEY uq_posture (assessment_id, finding),
    CONSTRAINT fk_posture_asm FOREIGN KEY (assessment_id) REFERENCES member_assessments (assessment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_imbalances (
    imbalance_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    region        ENUM('chest','upper_back','lower_back','shoulders','biceps','triceps',
                       'glutes','hamstrings','quads','calves') NOT NULL,
    weak          TINYINT(1)      NOT NULL DEFAULT 0,
    tight         TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (imbalance_id),
    UNIQUE KEY uq_imbalance (assessment_id, region),
    CONSTRAINT fk_imb_asm FOREIGN KEY (assessment_id) REFERENCES member_assessments (assessment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
