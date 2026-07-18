-- =============================================================================
-- xcamp-gym-sql / dashboard : setup_logins.sql
-- -----------------------------------------------------------------------------
-- يضبط كلمات مرور (bcrypt) لحسابات الدخول في جدول users حتى تعمل صفحة login.php.
-- شغّله مرة واحدة بعد تحميل قاعدة البيانات:
--   sudo mysql xcamp_gym < setup_logins.sql
--
-- بيانات الدخول الافتراضية (غيّرها لاحقًا من قاعدة البيانات):
--   admin@xcamp.com     / admin123     (مدير — صلاحية كاملة)
--   manager@xcamp.com   / manager123   (مدير — صلاحية كاملة)
--   coach1@xcamp.com    / coach123     (Coach Ahmed — يرى أعضاءه فقط)
--   coach2@xcamp.com    / coach123     (Coach Sara  — يرى أعضاءه فقط)
-- =============================================================================

USE xcamp_gym;

UPDATE users SET password_hash = '$2y$12$ggB51H3wudXoKzPS39I7wudYWi.HFO5ksRyqRDFRVrAzJ.WTbgQOG' WHERE email = 'admin@xcamp.com';
UPDATE users SET password_hash = '$2y$12$c.qwIPN.wRWPJ26nQ81nSezUvz5f5FFDYkQ8r4TN8iUM/xu2fK196' WHERE email = 'manager@xcamp.com';
UPDATE users SET password_hash = '$2y$12$Jx0BxSRoPe4Y9z0TP9th2ubrCD61zAyCmQejA7IFBOe/hGzQsHBxG' WHERE email = 'coach1@xcamp.com';
UPDATE users SET password_hash = '$2y$12$EP5ORr6u6qhemabA3IK9lO1OVAB3pX2NrKOOJO08NLUcqmFWPJ0aS' WHERE email = 'coach2@xcamp.com';
