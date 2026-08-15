-- =============================================================================
-- xcamp-gym-sql : 22_attendance_unique.sql  (P0-C — إضافي، لا يمسّ 01_tables.sql)
-- -----------------------------------------------------------------------------
-- سلامة الحضور: قاعدة العمل «حضور واحد لكل عضو في اليوم». يفرضها قيد UNIQUE على
-- (member_id, attendance_date) على مستوى القاعدة، فيُغلق سباق SELECT-ثم-INSERT عبر
-- مسارات الإدراج الثلاثة (QR / الكابتن / الجلسة الحيّة). المسارات تستخدم
-- INSERT ... ON DUPLICATE KEY UPDATE فيصبح التكرار عملية idempotent بلا خطأ.
--
-- ⚠️ بوّابة البيانات: يُشغَّل فقط بعد التأكّد من عدم وجود تكرارات حالية
-- (member_id, attendance_date). فحص P0 أثبت: صفر تكرارات. إن وُجدت مستقبلًا سيفشل
-- إنشاء القيد — تُدمج/تُنظَّف التكرارات أولًا (خارج نطاق هذا الملف).
-- القيد أيضًا يخدم كفهرس مركّب (member_id, attendance_date). قابل لإعادة التشغيل.
-- Rollback: ALTER TABLE daily_attendance DROP INDEX uq_attendance_member_day;
-- =============================================================================

USE xcamp_gym;

ALTER TABLE daily_attendance
  ADD UNIQUE KEY IF NOT EXISTS uq_attendance_member_day (member_id, attendance_date);
