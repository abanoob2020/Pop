-- =============================================================================
-- xcamp-gym-sql : 13_coach_hr.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- الموارد البشرية للكباتن: إعدادات (راتب/عمولة/سعة) + ورديات أسبوعية + مسيّر رواتب.
--   coach_hr        إعدادات الكابتن: الراتب الأساسي، نسبة العمولة، سعة الأعضاء
--   coach_shifts    ورديات أسبوعية متكرّرة (يوم + من/إلى)
--   coach_payroll   سجلّ الرواتب الشهرية (أساسي + عمولة = الإجمالي) وحالته
-- تسجيل راتب كمدفوع يُنشئ مصروفًا (salaries) في جدول expenses.
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS.
-- =============================================================================

USE xcamp_gym;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS coach_hr (
    coach_id         BIGINT UNSIGNED NOT NULL,
    base_salary      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    commission_rate  DECIMAL(5,2)    NOT NULL DEFAULT 0.00,   -- نسبة مئوية من مدفوعات أعضائه
    capacity_members INT             NOT NULL DEFAULT 0,       -- 0 = بلا حدّ
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (coach_id),
    CONSTRAINT fk_coach_hr_coach FOREIGN KEY (coach_id) REFERENCES coaches (coach_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coach_shifts (
    shift_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    coach_id   BIGINT UNSIGNED NOT NULL,
    weekday    TINYINT         NOT NULL,   -- 0=السبت .. 6=الجمعة
    start_time TIME            NOT NULL,
    end_time   TIME            NOT NULL,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (shift_id),
    KEY idx_shift_coach (coach_id),
    CONSTRAINT fk_coach_shift_coach FOREIGN KEY (coach_id) REFERENCES coaches (coach_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coach_payroll (
    payroll_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    coach_id   BIGINT UNSIGNED NOT NULL,
    period_ym  CHAR(7)         NOT NULL,   -- YYYY-MM
    base       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    commission DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    total      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    status     ENUM('pending','paid') NOT NULL DEFAULT 'paid',
    paid_at    DATETIME        NULL,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payroll_id),
    UNIQUE KEY uq_coach_period (coach_id, period_ym),
    CONSTRAINT fk_payroll_coach FOREIGN KEY (coach_id) REFERENCES coaches (coach_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إعدادات مبدئية للكباتن الموجودين
INSERT IGNORE INTO coach_hr (coach_id, base_salary, commission_rate, capacity_members)
SELECT coach_id, 5000.00, 10.00, 25 FROM coaches;
