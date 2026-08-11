-- =============================================================================
-- xcamp-gym-sql : 23_perf_indexes.sql  (P0-D — إضافي، لا يمسّ 01_tables.sql)
-- -----------------------------------------------------------------------------
-- فهارس مبنية على أدلّة EXPLAIN (type=ALL, key=NULL قبلها) + أنماط الوصول الفعلية.
-- تُغطّي أعمدة تصفية/فرز/مدى عالية الانتقائية على الجداول الأكثر نموًا/استعلامًا.
--
-- المُضافة (ADD) — لكلٍّ نمط وصول فعلي في الكود/الـviews:
--   daily_attendance(attendance_date)   : استعلامات «اليوم»/المدى (أسرع الجداول نموًا).
--   memberships(end_date)               : قرب الانتهاء/التجديد (مدى، انتقائية عالية).
--   memberships(payment_status)         : المتعثّرات (فئة أقلّية: unpaid/partial/failed).
--   memberships(renewal_status)         : خطّ التجديد.
--   members(status)                     : لوحات الحالة/المخاطر.
--   followups(next_followup_date)       : المتابعات المستحقّة (مدى).
--
-- مؤجّلة/مرفوضة (موثّقة في P0_IMPLEMENTATION_REPORT.md):
--   members(full_name/phone) — البحث `LIKE '%q%'` لا يستفيد من فهرس B-Tree (بادئة عامّة)
--     → يلزم FULLTEXT/بحث مخصّص لاحقًا (خارج نطاق P0).
--   payments(status) — انتقائية منخفضة ('paid' غالبة) → المُحسّن يفضّل المسح؛ مؤجّل.
--   payments(payment_date) — استعلام الدخل يلفّ YEAR()/MONTH() (غير sargable) → لا فائدة
--     دون إعادة صياغة الاستعلام إلى مدى تواريخ (خارج نطاق P0).
--
-- قابل لإعادة التشغيل (CREATE INDEX IF NOT EXISTS — MariaDB 10.11).
-- Rollback: DROP INDEX <name> ON <table>; لكل فهرس أدناه.
-- =============================================================================

USE xcamp_gym;

CREATE INDEX IF NOT EXISTS idx_att_date        ON daily_attendance (attendance_date);
CREATE INDEX IF NOT EXISTS idx_ms_end_date     ON memberships (end_date);
CREATE INDEX IF NOT EXISTS idx_ms_pay_status   ON memberships (payment_status);
CREATE INDEX IF NOT EXISTS idx_ms_renew_status ON memberships (renewal_status);
CREATE INDEX IF NOT EXISTS idx_members_status  ON members (status);
CREATE INDEX IF NOT EXISTS idx_fu_next_date    ON followups (next_followup_date);
