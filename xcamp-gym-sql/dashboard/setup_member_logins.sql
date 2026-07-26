-- =============================================================================
-- xcamp-gym-sql / dashboard : setup_member_logins.sql
-- يفعّل بوابة العضو لأعضاء البذرة بكلمة مرور تجريبية موحّدة (member123).
-- شغّله بعد تحميل القاعدة:  sudo mysql xcamp_gym < setup_member_logins.sql
-- الدخول: بالهاتف أو البريد + member123  (مثال: 01111111101 / member123)
-- =============================================================================
USE xcamp_gym;
INSERT INTO member_auth (member_id, password_hash, portal_enabled)
SELECT member_id, '$2y$12$L3jxt2TWBjZuV/cq1g3C4ueYq0bCMNj7vBQJyVgLJ75hWRdLYKv.2', 1 FROM members
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), portal_enabled = 1;
