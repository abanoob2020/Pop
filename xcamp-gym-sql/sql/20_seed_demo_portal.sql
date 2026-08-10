-- =============================================================================
-- xcamp-gym-sql : 20_seed_demo_portal.sql  (بيانات تجريبية — DB_SEED=1 فقط)
-- -----------------------------------------------------------------------------
-- يفعّل بوابة العضو لأعضاء العيّنة (المعرّفات 1..5 من 06_seed_data.sql) بكلمة مرور
-- تجريبية موحّدة **member123** (bcrypt)، حتى يعمل تسجيل دخول العضو فورًا للتجربة.
--
-- ⚠️ هذا الملف **لا يُشغَّل في النشر النظيف** (DB_SEED=0) — المشغّلات تستبعده، فلا
-- تُنشأ أي حسابات بوابة في الإنتاج. يعمل بعد 10_member_portal.sql (إنشاء الجدول).
-- الدخول: هاتف/إيميل العضو (مثل 01111111101 أو omar@x.com) + member123.
--
-- الملف قابل لإعادة التشغيل: INSERT IGNORE (لا يلمس صفوفًا موجودة).
-- =============================================================================

USE xcamp_gym;
SET NAMES utf8mb4;

INSERT IGNORE INTO member_auth (member_id, password_hash, portal_enabled)
SELECT m.member_id, '$2y$12$y7iYMTugK/BthMhPzi6leeMY.XV9rLRSUUEQmgLKSGplSavR.HIA6', 1
FROM members m
WHERE m.member_id BETWEEN 1 AND 5;
