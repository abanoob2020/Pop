-- =============================================================================
-- xcamp-gym-sql : 14_pt_sessions.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- حجز جلسات التدريب الشخصي (PT): العضو/الاستقبال يحجز موعدًا مع الكابتن ضمن
-- ورديات الكابتن (من coach_shifts)، مع دورة حالة (محجوز/مكتمل/ملغى/تخلّف) وسعر.
--   pt_sessions   موعد جلسة شخصية لعضو مع كابتن في تاريخ/وقت.
-- كما نبذر ورديات افتراضية للكباتن بلا ورديات ليعمل الحجز مباشرةً بعد النشر.
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS + بذر محروس بـ NOT EXISTS.
-- =============================================================================

USE xcamp_gym;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pt_sessions (
    pt_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id    BIGINT UNSIGNED NOT NULL,
    coach_id     BIGINT UNSIGNED NOT NULL,
    session_date DATE            NOT NULL,
    start_time   TIME            NOT NULL,
    end_time     TIME            NOT NULL,
    status       ENUM('booked','completed','cancelled','no_show') NOT NULL DEFAULT 'booked',
    booked_by    ENUM('member','staff') NOT NULL DEFAULT 'member',
    price        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    notes        VARCHAR(300)    NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (pt_id),
    KEY idx_pt_coach_date (coach_id, session_date),
    KEY idx_pt_member (member_id),
    CONSTRAINT fk_pt_member FOREIGN KEY (member_id) REFERENCES members (member_id) ON DELETE CASCADE,
    CONSTRAINT fk_pt_coach  FOREIGN KEY (coach_id)  REFERENCES coaches (coach_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ورديات افتراضية للكباتن الذين لا ورديات لهم: الأحد/الثلاثاء/الخميس 16:00–21:00
-- (weekday: 0=السبت .. 6=الجمعة ⇒ 1=الأحد، 3=الثلاثاء، 5=الخميس)
INSERT INTO coach_shifts (coach_id, weekday, start_time, end_time)
SELECT c.coach_id, d.wd, '16:00:00', '21:00:00'
FROM coaches c
CROSS JOIN (SELECT 1 AS wd UNION ALL SELECT 3 UNION ALL SELECT 5) d
WHERE c.active = 1
  AND NOT EXISTS (SELECT 1 FROM coach_shifts s WHERE s.coach_id = c.coach_id);
