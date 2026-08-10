# XCAMP GYM — تقرير المراجعة الأمنية والتحصين

منهجية: OWASP. النطاق: تطبيق `dashboard/` (Raw PHP 8.x + PDO/MariaDB) + سكربتات
النشر/النسخ الاحتياطي. لا إعادة كتابة، لا تغيير schema، لا كسر وظائف.

## Executive Summary

| التصنيف | العدد | الحالة |
|---------|-------|--------|
| CRITICAL | 0 | — |
| HIGH | 0 | — |
| MEDIUM | 6 | ✅ مُصلَحة |
| LOW | 3 | ✅ مُصلَحة |
| INFO | 1 | ✅ مُتحقَّق منه (سلوك مقصود) |

**الحالة العامة: جيّدة.** الفئات عالية الخطورة (SQLi / تجاوز مصادقة / IDOR / CSRF /
XSS / RCE عبر الرفع) كانت مُعالَجة فعليًا في التصميم الأصلي. الإصلاحات ركّزت على
**التحصين** (Hardening): إخفاء الأخطاء، ترويسات الأمان، فشل مُغلق للأسرار، سجلّ أمني،
دفاع في العمق للرفع، وضبط أقل امتياز.

### ما كان قويًا أصلًا (تحقّقنا منه)
- **SQL Injection:** Prepared statements في كل الاستعلامات؛ كل استيفاء ديناميكي إمّا
  `(int)` أو قائمة أعمدة مُدرَجة مسبقًا (allowlist في `assess.php`). لا concatenation
  لمدخل خام. + الآن `EMULATE_PREPARES=false`.
- **CSRF:** رمز `random_bytes(32)` مربوط بالجلسة + `hash_equals` + ليس في URL.
- **Auth:** bcrypt، `session_regenerate_id(true)` بعد الدخول، فلتر `is_active=1`،
  رسائل خطأ لا تكشف وجود الحساب، تقييد محاولات، إتلاف جلسة سليم.
- **RBAC/IDOR:** صفحات المدير محميّة خادِميًا (`require_role`)؛ أفعال الكابتن تتحقّق من
  ملكية العضو (`member_allowed`) قبل أي تعديل.
- **File upload:** MIME عبر `finfo` (محتوى) + allowlist + امتداد مفروض + اسم عشوائي
  + `is_uploaded_file` + حد حجم. + الآن `getimagesize()` و`.htaccess` منع تنفيذ.

---

## Findings

### SEC-001 — MEDIUM — كلمة مرور DB افتراضية في الشيفرة
- **Location:** `dashboard/db.php` (دالة `db()`).
- **Vulnerability:** قيمة احتياطية `'ChangeThisPass123'` تُستخدم عند غياب `DB_PASS` (fail-open).
- **Impact:** اتصال بكلمة مرور معروفة من المصدر؛ خرق قاعدة «لا أسرار في الشيفرة».
- **Root Cause:** قيمة افتراضية مضمّنة.
- **Fix:** إزالة القيمة؛ فشل مُغلق — `throw` إذا لم يُضبط `DB_PASS` (يُسمح بقيمة فارغة صريحة).
- **Verification:** `env -u DB_PASS` ⇒ استثناء واضح؛ لا اتصال.
- **Remaining Risk:** لا يوجد.

### SEC-002 — MEDIUM — كشف رسائل أخطاء القاعدة للمستخدم
- **Location:** `login.php`, `member_login.php`, `db.php::db_error_box()`.
- **Vulnerability:** طباعة `$e->getMessage()` الخام + مثال `GRANT ALL … IDENTIFIED BY 'MyPass123'`.
- **Impact:** إفشاء مخطط/مضيف/تلميحات اعتماد.
- **Root Cause:** عرض تفاصيل تشخيصية في الإنتاج.
- **Fix:** رسائل عامة للمستخدم + `error_log`/`app_log` داخلي؛ التفاصيل خلف `APP_DEBUG=1` فقط؛
  إزالة كتلة الاعتماد/GRANT من الواجهة.
- **Verification:** الإنتاج (بلا `APP_DEBUG`) يعرض رسالة عامة؛ لا تسريب.
- **Remaining Risk:** لا يوجد.

### SEC-003 — MEDIUM — غياب ترويسات الأمان
- **Location:** كل الصفحات (تُبعث الآن مركزيًا من `db.php`).
- **Vulnerability:** لا `X-Frame-Options`/`X-Content-Type-Options`/`Referrer-Policy`/`CSP`/`HSTS`.
- **Impact:** Clickjacking، MIME sniffing.
- **Fix:** باعث ترويسات مركزي: `X-Frame-Options: DENY`، `nosniff`، `Referrer-Policy: same-origin`،
  `Permissions-Policy`، CSP بأصول ذاتية (`frame-ancestors 'none'`, `object-src 'none'`,
  `base-uri/form-action 'self'`)، و`HSTS` تحت HTTPS فقط.
- **Verification:** `curl -D -` يُظهر كل الترويسات.
- **Remaining Risk:** CSP تُبقي `'unsafe-inline'` لوجود أنماط/معالجات inline — تضييق عبر nonces
  في `FUTURE_ARCHITECTURE.md`.

### SEC-004 — MEDIUM — لا معالجة أخطاء مركزية / `display_errors` غير مضبوط
- **Location:** عام (الآن في `db.php`).
- **Fix:** `display_errors=0` افتراضيًا (بوابة `APP_DEBUG`)، `error_reporting(E_ALL)`,
  `set_exception_handler` + `register_shutdown_function` يسجّلان ويعرضان رسالة عامة.
- **Verification:** استثناء غير مُلتقَط ⇒ 500 + رسالة عامة + سطر في سجلّ الخادم.
- **Remaining Risk:** لا يوجد.

### SEC-005 — MEDIUM — خرق أقل امتياز في إرشاد صلاحيات القاعدة
- **Location:** `db.php::db_error_box()` (سابقًا) + توثيق.
- **Vulnerability:** إرشاد `GRANT ALL PRIVILEGES`.
- **Fix:** حذف الإرشاد من الواجهة؛ توثيق GRANT محدود (SELECT/INSERT/UPDATE/DELETE/EXECUTE)
  في `docs/deployment-security.md` مع فصل حساب النشر (DDL) عن حساب التشغيل (DML).
- **Remaining Risk:** يعتمد على تطبيق المشغّل للـGRANT الموصى به (توثيق + تحقّق).

### SEC-006 — MEDIUM — غياب سجلّ أمني
- **Location:** لا يوجد سابقًا (الآن `app_log()` في `db.php`).
- **Fix:** سجلّ JSON بمستويات (INFO/WARNING/ERROR/SECURITY) في `logs/security.log`،
  يحجب الحقول الحسّاسة، موصول بـ: نجاح/فشل الدخول (طاقم وعضو)، الحظر، ورفض الصلاحية.
- **Verification:** السجلّ يحوي `login_success`/`login_failed`/`permission_denied`؛
  لا كلمات مرور فيه.
- **Remaining Risk:** التدوير (rotation) عبر `logrotate` — موثّق كإجراء تشغيلي.

### SEC-007 — LOW/MEDIUM — الملفات المرفوعة داخل جذر الويب
- **Location:** `dashboard/uploads/`, `captains.php` (رفع صورة التقدّم).
- **Vulnerability:** لا منع تنفيذ على مستوى الخادم (مُخفَّف بالامتداد المفروض).
- **Fix:** `uploads/.htaccess` يُعطّل محرّك PHP ويمنع سكربتات؛ + `getimagesize()` تحقّق أن
  المحتوى صورة نقطية صالحة؛ مكافئ nginx موثّق.
- **Verification:** ملف PHP مُقنّع بامتداد صورة ⇒ يُرفض (finfo + getimagesize).
- **Remaining Risk:** يعتمد `.htaccess` على Apache AllowOverride؛ لـnginx راجع دليل النشر.

### SEC-008 — LOW — `EMULATE_PREPARES` مُفعّل
- **Fix:** `PDO::ATTR_EMULATE_PREPARES => false` — عبارات مُجهَّزة حقيقية.
- **Remaining Risk:** لا يوجد.

### SEC-009 — LOW — تقييد الدخول على (IP+بريد) فقط
- **Location:** `login.php`.
- **Fix:** سقف إضافي لكل IP عبر كل البُرد (`RL_IP_MAX`) ضد credential stuffing.
- **Remaining Risk:** التخزين ملفّي best-effort؛ limiter مدعوم DB/Redis في `FUTURE_ARCHITECTURE.md`.

### SEC-010 — INFO — وصول القراءة وعرض بيانات تجريبية
- **Location:** `revenue.php`, `login.php`.
- **Finding/Outcome:** `revenue.php` بـ`require_login` **مقصود** — الكابتن يرى أعضاءه فقط
  (نطاق `coach_id`)، فلا تغيير. تلميح بيانات الدخول التجريبية على صفحة الدخول: أصبح خلف
  `APP_DEBUG` فلا يظهر في الإنتاج.
- **Remaining Risk:** لا يوجد.

## ملاحظات INFO إضافية (مُصلَحة)
- `member_logout.php` بلا `exit;` بعد `header` → أُضيف.
- `h()` بلا `ENT_SUBSTITUTE` → أُضيف (يمنع كسر الإخراج على UTF-8 غير صالح).

## القرارات المؤجّلة (بلا تنفيذ الآن)
انظر `FUTURE_ARCHITECTURE.md`: multi-tenancy، Framework، نظام migrations بإصدارات،
CSP بـnonces (حذف `'unsafe-inline'`)، وrate-limiter مركزي.
