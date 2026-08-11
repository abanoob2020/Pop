-- =============================================================================
-- xcamp-gym-sql : 21_payment_idempotency.sql  (P0-B — إضافي، لا يمسّ 01_tables.sql)
-- -----------------------------------------------------------------------------
-- حماية من ازدواج الدفعات: عمود مفتاح idempotency + قيد UNIQUE على مستوى القاعدة.
-- كل نموذج تسجيل دفعة يحمل رمزًا فريدًا (hex) يُولّد عند عرض النموذج؛ فإعادة الإرسال
-- (نقر مزدوج/تحديث/إعادة محاولة/طلبات متزامنة) بنفس الرمز تفشل عند INSERT الثاني
-- بفضل القيد الفريد — فيُعامَل كنجاح idempotent دون صفّ مكرّر.
--
-- آمن مع الصفوف الحالية: العمود يقبل NULL، وMariaDB تسمح بقيم NULL متعدّدة في UNIQUE،
-- فالدفعات القديمة (idempotency_key = NULL) لا تتعارض. قابل لإعادة التشغيل (IF NOT EXISTS).
-- Rollback: ALTER TABLE payments DROP INDEX uq_payments_idem, DROP COLUMN idempotency_key;
-- =============================================================================

USE xcamp_gym;

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(64) NULL AFTER reference_no;

ALTER TABLE payments
  ADD UNIQUE KEY IF NOT EXISTS uq_payments_idem (idempotency_key);
