# SYSTEM_SECURITY_STABILITY_AUDIT.md — XCAMP GYM

**نوع المهمة:** 100% READ-ONLY Forensic Engineering Audit. **لم يُعدَّل أي كود/SQL/schema/
config.** المخرج الوحيد هو هذا التقرير. كل finding مصنّف:
`VERIFIED` / `LIKELY` / `POTENTIAL` / `NOT VERIFIED` / `NOT PRESENT` / `UNKNOWN`.

---

## 1. Executive Summary

XCAMP GYM نظام إدارة نادٍ **Raw PHP 8 + PDO/MariaDB بلا framework** (28 صفحة، ~7,736 سطرًا،
48 جدولًا). بعد جولات التحصين (#36–#40) المشروع في وضع أمني **قوي نسبيًا لمشروع Raw PHP**:
لا ثغرات **CRITICAL/HIGH** مُثبَتة (لا SQLi، لا RCE، لا تجاوز مصادقة، لا IDOR مكشوف).
المخاطر المتبقّية **تشغيلية/بنيوية** (اتّساق مالي، فهارس، اختبارات، god file) لا اختراقية.

**Overall Engineering Risk: MEDIUM** (يميل إلى LOW أمنيًا، MEDIUM تشغيليًا).

**نطاق التدقيق:** الشجرة الحالية = فرع `fix/biz-001-revenue-ledger` @ `d8bf910`
(= `main`/#39 + إصلاح BIZ-001 في `revenue.php`). **تنويه:** تحسينات CSP الخاصة بـPR #40
(`connect-src`، Report-Only، `csp_report.php`) على فرع **غير مدموج** وليست في هذه الشجرة.

## 2. Scope

كامل `xcamp-gym-sql/`: `dashboard/*.php` (28)، `sql/*.sql` (21)، سكربتات النشر/النسخ
الاحتياطي، `.gitignore`، `.htaccess`، التوثيق. **خارج النطاق (NOT VERIFIABLE from repo):**
بنية الإنتاج الفعلية (خادم الويب، TLS، صلاحيات DB على الخادم، cron المنصوب).

## 3. Methodology

قراءة الكود الفعلي + تتبّع تدفّق البيانات + فحص علاقات القاعدة + بحث أنماط ثابت
(grep/read). لا تنفيذ exploit، لا أوامر تغيّر الحالة. الأحكام مسنودة بموقع
(file:line) حيثما أمكن.

## 4. System Inventory (VERIFIED)

```
XCAMP
├── Presentation/UI ....... 28 صفحة PHP (HTML+CSS+JS inline)، RTL
├── Authentication ........ login.php, member_login.php, logout, member_logout, account, provision_admin(CLI)
├── Authorization/RBAC .... db.php (require_login/require_role/is_manager) + coach scope + member_allowed
├── Business Logic ........ صفحات + training.php (دوال نقية) + 11 stored procedures
├── Database Layer ........ db.php::db() (PDO مفرد) — 48 جدول/59 FK/13 view/8 trigger/1 event
├── File Handling ......... captains.php (رفع صورة تقدّم) → dashboard/uploads/progress/
├── Reports ............... analytics, revenue, finance, crm, retention (views)
├── APIs/AJAX ............. لا API/JSON؛ page_script يعيد <main> عبر fetch (نفس الأصل)
├── Configuration ........ متغيّرات بيئة فقط (DB_*, APP_DEBUG, SESSION_SECURE, APP_LOG_DIR)
├── Logging .............. app_log() → logs/security.log (JSON)
├── Backups .............. backup.sh / restore.sh / backup.cron.example
└── Infrastructure ....... deploy.sh / reset_and_deploy.sh / run_all.sql / update.sh
```
**NOT PRESENT:** `composer.json`، `package.json`، `.env`، مجلد `tests/`، أي CDN/مكتبة خارجية.

## 5. Current Architecture

صفحات مُخدَّمة خادِميًا، ملف مشترك `db.php` (اتصال+جلسة+مصادقة+CSRF+ترويسات+واجهة).
منطق أعمال موزّع: PHP + **محرّك أتمتة في القاعدة** (8 triggers `AFTER INSERT` → 11
`sp_handle_*` تُنشئ tasks/flags/messages/milestones). لا طبقات (MVC/service/repo)، لا DI،
لا router. كل الأصول inline ذاتية.

## 6. Authentication Audit

مساران منفصلان: طاقم (`$_SESSION['user']`) وعضو (`$_SESSION['member']`).

| البند | الحالة | الدليل |
|-------|--------|--------|
| Password hashing | **VERIFIED** bcrypt | `password_hash(..., PASSWORD_BCRYPT)` (login/provision/captains) |
| Verification | **VERIFIED** | `password_verify()` (login.php:42، member_login.php:38) |
| MD5/SHA1/plaintext/custom | **NOT PRESENT** | لا مطابقة في الكود |
| Brute-force/rate-limit | **VERIFIED** | login.php: `RL_MAX=5` لكل IP+بريد + `RL_IP_MAX=20` لكل IP، نافذة 900ث (ملفّي) |
| Account lockout | LIKELY (مؤقّت) | حظر نافذة زمنية لا قفل دائم |
| Enumeration | **NOT PRESENT** (جيّد) | رسالة موحّدة «بيانات الدخول غير صحيحة» بصرف النظر عن وجود الحساب (login.php:46) |
| Timing | POTENTIAL منخفض | `password_verify` ثابت الزمن؛ فرق SELECT ضئيل |
| Default/hardcoded creds | **NOT PRESENT** في الكود | بذور تجريبية مقيّدة بـDB_SEED؛ التلميح خلف APP_DEBUG |
| Session fixation | **NOT PRESENT** (جيّد) | `session_regenerate_id(true)` بعد الدخول (login.php:58، member_login.php:46) |
| Cookie flags | **VERIFIED** | `HttpOnly`+`SameSite=Lax`+`Secure` تلقائي تحت HTTPS (db.php:88-95) |
| Logout invalidation | **VERIFIED** | `$_SESSION=[]` + حذف الكوكي + `session_destroy()` (logout.php) |
| Session ID exposure | **NOT PRESENT** | لا معرّف في URL/سجلّ/HTML |
| Password reset (ذاتي) | **NOT PRESENT** | لا تدفّق «نسيت كلمة المرور» — الطاقم يعيّن كلمة العضو |
| **Idle/absolute timeout** | **NOT PRESENT** | `lifetime=0` (عمر المتصفّح فقط) — **finding SESS-01 (LOW)** |
| Account status | **VERIFIED** | فلتر `is_active=1` (login.php:39) |

## 7. Authorization / RBAC Audit

أدوار `users.role` ENUM: **admin, manager, coach, reception** + عضو البوابة.
`is_manager()` = admin|manager|reception (db.php:167-170).

- **Server-side RBAC (VERIFIED):** صفحات المدير تستدعي `require_role([...])` أعلى الملف
  (index/finance/hr/analytics/promos) — ليس إخفاء أزرار.
- **Coach scope (VERIFIED):** `$myCoach = (int)($me['coach_id'])` من الجلسة؛ استعلامات
  مقيّدة `WHERE coach_id = <int>`.
- **IDOR/BOLA (NOT PRESENT — VERIFIED):** أفعال الكابتن في `captains.php` تُحلّ
  `targetMember` من مُعرّف الكائن (سطور 67-89) ثم `member_allowed($pdo,$me,$targetMember)`
  (38-41) قبل الكتابة؛ crm.php:27 وassess.php:87 تتحقّقان من الملكية. تغيير `id/member_id`
  في الطلب لعضو كابتن آخر → «غير مسموح».
- **الوصول المباشر للـURL:** كل صفحة تبدأ بـ`require_login/require_role/require_member`؛
  الوصول غير المصادَق → redirect لصفحة الدخول (VERIFIED).
- **ملاحظة (VERIFIED مقصود):** `revenue.php`/`crm.php`/`retention.php` بـ`require_login`
  + نطاق كابتن داخلي (يرى أعضاءه فقط).

**Matrix (ALLOW/DENY/COND):**
| إجراء | Admin | Manager | Reception | Coach | Member |
|------|:---:|:---:|:---:|:---:|:---:|
| index/finance/hr/analytics/promos | A | A | A | **D** | D |
| crm/retention/revenue/captains(تعديل) | A | A | A | **COND(أعضاؤه)** | D |
| portal | D | D | D | D | **A(نفسه)** |

## 8. SQL Injection Audit — **NOT PRESENT (VERIFIED)**

- كل تفاعل قاعدة عبر **PDO Prepared Statements** بمعاملات `?`. + `ATTR_EMULATE_PREPARES
  => false` (db.php:141) → عبارات مُجهَّزة حقيقية.
- الاستيفاء الديناميكي الوحيد إمّا **`(int)`** (مثال: captains.php `... = " . (int)$_POST[...]`)
  أو **allowlist أعمدة مُدرَجة مسبقًا** (assess.php: مفاتيح `$fields` ثابتة).
- نطاقات الكابتن تُبنى من `(int)$myCoach` (جلسة) لا من الطلب.
- **لم يُعثر** على concatenation لمدخل مستخدم خام في أي استعلام. `ORDER BY` ثابتة (لا
  عمود ديناميكي من المستخدم).
- **التصنيف:** Informational (لا عناصر ثغرة). الوحيد للملاحظة: بعض الاستيفاء بـ`(int)`
  بدل bind — آمن وظيفيًا لكن الأنظف bind (تحسين أسلوبي، ليس ثغرة).

## 9. XSS Audit — **Largely NOT PRESENT (VERIFIED)**

- إخراج قيم عبر `h()` = `htmlspecialchars(..., ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')`
  (db.php:123) — سياق HTML.
- **Reflected:** لا `echo`/`<?=` مباشر لأي `$_GET/$_POST/$_SERVER` (بحث ثابت: صفر).
- **Stored:** أسماء/ملاحظات الأعضاء تُخرَج عبر `h()` — لم يُعثر على إخراج خام من القاعدة.
- **DOM XSS:** `page_script` يستبدل `main.innerHTML` بمحتوى **خادِمي مُهرَّب**؛ `<script>`
  لا يُنفَّذ عبر innerHTML. لا `document.write`/`eval` (VERIFIED صفر).
- **POTENTIAL (منخفض):** سياقات غير-HTML (قيم داخل `style="..."` أو JS inline) تعتمد على
  أن القيم أرقام/ثابتة؛ لم أجد حقن قيمة مستخدم داخل JS/CSS، لكن لا يوجد encoder مخصّص
  للسياق (لو أُضيف لاحقًا إخراج مستخدم داخل style/JS يلزم encoder مناسب). **INFO.**

## 10. CSRF Audit — **PROTECTED (VERIFIED)**

- رمز `bin2hex(random_bytes(32))` مربوط بالجلسة (`$_SESSION['csrf']`)، تحقّق
  `hash_equals()` (db.php: csrf_token/csrf_field/csrf_check).
- **تغطية VERIFIED:** كل صفحة تعالج POST (19/19) تستدعي `csrf_check()` في بداية المعالجة
  (فحص آلي لكل الملفات).
- الرمز **ليس في URL**؛ حقل مخفي `csrf` داخل النماذج.
- **قيود:** لا انتهاء صلاحية للرمز (طوال الجلسة) — POTENTIAL منخفض جدًا (مقبول).

## 11. File Upload Audit — **HARDENED (VERIFIED)**

مكان وحيد: `captains.php` (صورة تقدّم) → `dashboard/uploads/progress/`.
- `is_uploaded_file()` + حد **5MB** (captains.php:151-152).
- **MIME بالمحتوى** عبر `finfo` + **allowlist** `image/jpeg|png|webp` (153-155).
- **`getimagesize()`** تأكيد صورة نقطية صالحة (يمنع ملفًا مُقنّعًا).
- **الامتداد مفروض خادِميًا** من خريطة MIME (لا من اسم المستخدم) + **اسم عشوائي**
  `bin2hex(random_bytes(4))` (158) → لا `.php`/`.phtml`/double-extension/null-byte/traversal.
- منع التنفيذ: `dashboard/uploads/.htaccess` (Apache) — **VERIFIED موجود في الشجرة**؛
  مكافئ nginx **NOT VERIFIABLE** (بنية إنتاج).
- **POTENTIAL (بيئي):** إن كان الخادم nginx بلا قاعدة deny، تبقى الحماية من الامتداد
  المفروض قائمة (لا تنفيذ PHP)، لكن دفاع-في-العمق يعتمد على إعداد الخادم.

## 12. Input Validation

- **Validation ≠ Encoding ≠ Authorization** مطبّقة بوعي: enum allowlist للحقول المقيّدة،
  `(int)/(float)` للأرقام، `trim`، وفحوص أعمال (`amount<=0` مرفوض finance.php:78).
- **VERIFIED جيّد:** التواريخ في إنشاء العضوية **محسوبة نظاميًا** (`CURDATE()`+`duration_days`)
  لا مدخل مستخدم → `end_date<start_date` غير قابل للحدوث.
- **POTENTIAL:** لا تحقّق مركزي موحّد (مكرّر عبر الصفحات)؛ لو أُضيف لاحقًا تحرير تواريخ
  يدوي يلزم تحقّق. البريد/الهاتف يعتمدان قيود القاعدة (UNIQUE) + `type=email` (عميل).

## 13. Error Handling — **HARDENED (VERIFIED)**

- `display_errors` **مُطفأ افتراضيًا**، يُفعَّل فقط بـ`APP_DEBUG=1` (db.php:12).
- `set_exception_handler` + `register_shutdown_function` يسجّلان داخليًا ويعرضان **رسالة
  عامة** (db.php:44-59).
- `db_error_box()` لا يكشف الرسالة الخام إلا في APP_DEBUG؛ أُزيلت كتلة `GRANT ALL`/الاعتماد.
- login/member_login: أخطاء القاعدة → رسالة عامة + `error_log` (لا تسريب).
- **النتيجة:** في الإنتاج لا تُكشف SQL/مسارات/stack traces/إصدار (VERIFIED بالكود؛ يعتمد
  أيضًا على `APP_DEBUG` غير مضبوط — إجراء تشغيلي).

## 14. Logging & Audit Trail

- **موجود (VERIFIED):** `app_log()` → `logs/security.log` (JSON، حجب أسرار) موصول بـ:
  `login_success/failed/throttled`, `member_login_*`, `permission_denied`, `db_error`,
  `csp_violation`(على فرع #40).
- **ناقص (finding LOG-01, MEDIUM):** لا سجلّ لأحداث الأعمال الحسّاسة: إنشاء/تعديل الدفع،
  تغيير حالة الاشتراك، الحذف، تغيير الأدوار. لا يمكن دائمًا معرفة **من غيّر ماذا/متى** على
  البيانات المالية.
- **`audit_logs` (VERIFIED غير مستخدم):** جدول مصمّم لتدقيق تغيّرات الكيانات (old/new JSON)
  لكن **لا يكتب فيه أي كود/trigger** (finding DEBT-01, INFO).

## 15. Database Security

- **Credentials:** من البيئة؛ **fail-closed** إذا غاب `DB_PASS` (db.php:134-137) — لا سرّ في الكود (VERIFIED).
- **Least privilege:** موثّق (`docs/deployment-security.md`: `xcamp_app` DML+EXECUTE فقط) لكن
  **NOT VERIFIABLE** على الخادم الفعلي.
- **FKs (VERIFIED):** 59 مفتاحًا بسياسات حذف صريحة (CASCADE للأبناء، SET NULL للاختياري،
  RESTRICT على `memberships→plans`).
- **Indexes (finding PERF-01, MEDIUM):** المخطط الأساسي `01_tables.sql` بلا فهارس ثانوية
  على أعمدة التصفية/التاريخ (`members.status`, `memberships.end_date/renewal_status/
  payment_status`, `payments.payment_date/status`, `daily_attendance.attendance_date`,
  `followups.next_followup_date`). فهارس FK فقط.
- **Transactions (VERIFIED):** المالية ذرّية — finance.php (POS: pos_sales+items+كود؛
  membership payment: payments+membership+كود) `beginTransaction/commit/rollBack`؛
  index/assess/captains/hr كذلك.
- **Race/consistency:** انظر §16 (INTEG-01 الحضور) و§21 (BIZ-001 الدفع، مُعالَج في d8bf910).

## 16. Backup & Recovery

- **موجود (VERIFIED):** `backup.sh` (mysqldump `--single-transaction --routines
  --triggers --events | gzip`, retention `BACKUP_KEEP`, نقل ذرّي)، `restore.sh` (تأكيد
  اسم القاعدة، `--latest`، فحص اتصال)، `backup.cron.example`. دورة تعافٍ مُختبَرة سابقًا.
- **Encryption/off-site:** **NOT PRESENT** (النسخ محلية بلا تشفير) — finding BKP-01 (MEDIUM).
- **Automated schedule:** مثال cron موجود لكن التنصيب الفعلي **NOT VERIFIABLE**.
- **RPO/RTO:** **Not defined / Unknown** (يعتمد على جدولة الخادم).

## 17. Secrets Management — **CLEAN (VERIFIED)**

- لا أسرار مضمّنة في PHP/JS/SQL (بحث ثابت: صفر بعد إزالة `ChangeThisPass123` في التحصين).
- `.env` **NOT PRESENT**؛ `.gitignore` يستثني `logs/`, `backups/`, `uploads/*`.
- تاريخ Git: لم يُلتزم `.env` قطّ (فُحص سابقًا). أي سرّ حقيقي: **REDACTED** — لا يوجد لعرضه.
- **الأسرار من البيئة فقط** (`DB_PASS` إلخ). VERIFIED.

## 18. Security Headers (في هذه الشجرة = إصدار #39)

مركزيًا من `db.php:64-78`:
| Header | الحالة |
|--------|--------|
| Content-Security-Policy | **موجود** `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'` |
| X-Frame-Options | **DENY** |
| X-Content-Type-Options | **nosniff** |
| Referrer-Policy | **same-origin** |
| Permissions-Policy | geolocation/mic/camera = () |
| Strict-Transport-Security | تحت HTTPS فقط |

- **`unsafe-inline`: موجود** (script+style) — ضروري لوجود ~20 معالج inline + كتل script؛
  موثّق كمؤقّت. **`unsafe-eval`: NOT PRESENT** (جيّد).
- **تنويه:** تحسين CSP (`connect-src 'self'` + Report-Only + `csp_report.php`) على فرع #40
  **غير مدموج** — ليس في هذه الشجرة.

## 19. HTTPS / Transport — **Partially NOT VERIFIABLE**

- الكود HTTPS-aware: `Secure` cookie + HSTS تُبعث تحت HTTPS/‏`SESSION_SECURE=1` (VERIFIED كود).
- فرض HTTPS/إعادة توجيه/TLS/mixed-content: **NOT VERIFIABLE from repository** (بنية خادم).

## 20. Dependencies — **NONE (VERIFIED)**

لا `composer.json`/`package.json`/مكتبات/CDN. صفر تبعيات خارجية ⇒ **لا سطح هجوم سلسلة
توريد، لا حزم متقادمة/مهجورة**. الجانب الآخر: كل شيء يدوي (لا ORM/إطار).

## 21. Business Logic Security

- **BIZ-001 (payment source of truth) — VERIFIED (مُعالَج في هذه الشجرة d8bf910):** كان
  `revenue.php` يحسب «محصّل» = `price × (payment_status='paid')` بينما finance يحسب
  `SUM(payments.amount)`؛ الإصلاح جعل «محصّل» يقرأ من `payments` (نطاق الجلسة). العلم
  التشغيلي `payment_status` باقٍ للبوّابة/الاحتفاظ. **ملاحظة:** الإصلاح على فرع مستقل غير
  مدموج بعد.
- **المبالغ (VERIFIED):** `amount<=0` مرفوض؛ الخصم مقصوص `0..base` (لا سالب/تجاوز).
- **دفعة أكبر/أقل من السعر (POTENTIAL):** لا فحص `amount==price`؛ دفعة جزئية تُعلّم العضوية
  `paid` (business decision).
- **Duplicate payment (POTENTIAL, finding PAY-01):** لا idempotency ولا `UNIQUE` على
  `receipt_no/reference_no`؛ إعادة إرسال POST قد تُنشئ دفعة مكرّرة (تُجمع في SUM). الزر
  مُعطَّل عميليًا فقط.
- **Refund (gap):** `'refunded'` تسمية حالة فقط بلا حركة مال مسجّلة.
- **Role/commission manipulation (NOT PRESENT):** تغيير الأدوار عبر شاشات مدير محميّة؛ لا
  مدخل يتحكّم في الدور من طرف غير المدير.
- **تجاوز قاعدة أعمال:** الأتمتة في triggers قد تُنفَّذ مرّتين عند إدراج حضور مكرّر (§16).

## 22. Data Privacy

- بيانات حسّاسة (VERIFIED): شخصية (أسماء/هواتف/بريد/عناوين)، مالية (مدفوعات/مبالغ)،
  صحّية/لياقية (تقييمات/إصابات/قياسات)، مصادقة (bcrypt).
- **Access controls:** RBAC + نطاق كابتن يحدّان الوصول (جيّد). التقارير/التصدير مقيّدة بالدور.
- **التخزين:** ضروري للأعمال؛ لا تخزين زائد ظاهر.
- **السجلّات:** `security.log` **لا يسجّل** بيانات شخصية/أسرار (حجب) — جيّد.
- **Compliance:** أي ادّعاء GDPR/PCI **UNKNOWN/غير قابل للإثبات** من الريبو.

## 23. Code Quality / Architecture

- **God file (finding CODE-01, MEDIUM):** `captains.php` (1506 سطر) يمزج HTML+PHP+SQL لأفعال
  كثيرة.
- **SQL/business logic داخل الصفحات (VERIFIED):** نمط المشروع؛ يصعّب الاختبار وإعادة الاستخدام.
- **تكرار (VERIFIED):** بناء نطاق الكابتن (`$scope`) وبطاقات الحالة مكرّر عبر صفحات.
- **منطق أعمال في triggers/procedures:** قوي لكن «مخفيّ»، يصعب تتبّعه/اختباره.
- **جيّد:** `db.php` نواة نظيفة، `training.php` دوال نقية قابلة للاختبار، تسمية متّسقة عمومًا.
- **Refactor risk:** god file عالي الخطر؛ الدوال النقية منخفضة الخطر.

## 24. Laravel Readiness

- **Moderate–High effort.** لا فصل طبقات؛ الجلسة/CSRF/التفويض يدوية؛ منطق في القاعدة.
- **أكبر مخاطر الهجرة:** `captains.php` (god file)، triggers/procedures (منطق أعمال في DB)،
  غياب طبقة نماذج/خدمات.
- **أسهل:** `training.php` (دوال نقية → services/actions مباشرة)، المخطط (→ Eloquent models
  + migrations، مع مراعاة الـtriggers).
- **الاستراتيجية (اقتراح فقط):** strangler-fig — Laravel كطبقة أمامية، ترحيل ميزة/ميزة،
  إبقاء القاعدة، تحويل الدوال النقية أولًا، ثم الصفحات، أخيرًا نقل منطق triggers إلى خدمات.

## 25. API Readiness

- **Page-coupled (VERIFIED):** لا طبقة API؛ الاستجابة HTML كاملة؛ التفويض مربوط بجلسة كوكي.
- **قابل لإعادة الاستخدام:** `training.php` + الدوال النقية + المخطط.
- **يلزم لبناء REST/موبايل:** طبقة مصادقة رموز (JWT/opaque) منفصلة عن الكوكيز، فصل منطق
  الأعمال عن الصفحات، تنسيق استجابة JSON. **ممكن لاحقًا بلا rewrite كامل** عبر استخراج
  تدريجي، لكنه جهد متوسط-مرتفع.

## 26. Multi-Tenancy Readiness

- **Single-tenant (VERIFIED):** قاعدة واحدة، لا `organization_id/branch_id/tenant_id` على
  أي جدول. لا كيان organizations/branches.
- **الإضافة مستقبلًا:** ممكنة لكن **مكلفة** — تتطلب `tenant_id` على كل جدول أعمال + نطاق
  إجباري في كل استعلام/‏view/‏trigger (وإلا تسريب بين المستأجرين). **ليست كارثية** (المخطط
  منظّم) لكنها واسعة. الحالة: **NOT tenant-ready**.

## 27. Critical Business Flows (trace)

```
Login → csrf_check → rate-limit → SELECT is_active=1 → password_verify → regenerate → session
Dashboard(index) [require_role] → views KPIs
Member create(index/captains) [require_role/member_allowed] → tx: members+memberships(unpaid)
Subscription(index) → memberships (تواريخ محسوبة)
Payment(finance) [require_role] → tx: payments(paid)+membership.paid+discount ✓ذرّي
Attendance(checkin) → SELECT-then-INSERT (لا UNIQUE) → trigger→sp_handle_attendance
Coach/Workout(captains/session/progression) [member_allowed]
Reports(revenue/finance/analytics) [require_role/scope] → payments/views
```
- **نقاط فشل:** الحضور (سباق)، الدفع (ازدواج بلا idempotency)، APP_DEBUG لو فُعِّل في الإنتاج.
- **كل flow:** مصادقة + تفويض + CSRF (على POST) + prepared + معالجة أخطاء موجودة.

## 28. Security Risk Matrix

| ID | Finding | Severity | Likelihood | Impact | Evidence (file) | Rec |
|----|---------|----------|-----------|--------|-----------------|-----|
| BIZ-001 | تضارب مصدر حقيقة الدفع | MEDIUM | High (قبل الإصلاح) | تقارير مالية خاطئة | revenue.php (مُعالَج d8bf910) | دمج الإصلاح |
| PERF-01 | فهارس ناقصة | MEDIUM | High(نمو) | بطء/فحص جداول | 01_tables.sql | migration فهارس |
| LOG-01 | لا audit trail للأعمال | MEDIUM | Medium | ضعف تحقيق | app_log نطاقه أمني | توسيع app_log للأعمال |
| INTEG-01 | سباق الحضور | LOW-MED | Medium(تزامن) | حضور مكرّر/أتمتة مزدوجة | checkin.php:32-35 | UNIQUE(member,date) |
| PAY-01 | ازدواج الدفع | MEDIUM | Medium | تضخيم مبالغ | finance.php (لا idempotency) | مفتاح تفرّد/idempotency |
| BKP-01 | نسخ بلا تشفير/off-site | MEDIUM | Medium | فقد/تسريب نسخة | backup.sh | تشفير + off-site |
| CODE-01 | god file captains.php | MEDIUM | — | صيانة | captains.php(1506) | تفكيك تدريجي |
| SESS-01 | لا timeout جلسة | LOW | Low | جلسة طويلة | db.php:88-95 | idle/absolute timeout |
| DEBT-01 | audit_logs غير مستخدم | INFO | — | نيّة غير منفّذة | 01_tables.sql:361 | تفعيل/إزالة |
| CSP-01 | unsafe-inline دائم | LOW | Low | XSS أضعف تخفيفًا | db.php:70-74 | nonces (#40 يبدأها) |
| ENV-01 | APP_DEBUG لو فُعِّل إنتاجًا | LOW→HIGH(لو) | Low | تسريب تفاصيل | db.php:12 | ضمان إطفائه إنتاجًا |

**لا CRITICAL/HIGH أمني مُثبَت.**

## 29. Top 10 Risks

1. **BIZ-001** أرقام مالية متضاربة — *مُعالَج (d8bf910) بانتظار الدمج.* أولوية P0.
2. **PERF-01** غياب الفهارس — تدهور أداء عند النمو. P1.
3. **LOG-01** غياب audit trail للأعمال — لا تتبّع لتغيّرات المال/الأدوار. P1.
4. **PAY-01** ازدواج الدفع (لا idempotency) — تضخيم مبالغ. P1.
5. **INTEG-01** سباق الحضور (لا UNIQUE) — حضور/أتمتة مزدوجة. P1.
6. **BKP-01** نسخ بلا تشفير/off-site — مخاطرة فقد/تسريب. P1.
7. **ENV-01** لو فُعِّل `APP_DEBUG` إنتاجًا — تسريب داخلي (تشغيلي). P1.
8. **CODE-01** god file — مخاطرة صيانة/انحدار. P2.
9. **CSP-01** `unsafe-inline` دائم — تخفيف XSS أضعف. P2.
10. **SESS-01** لا timeout جلسة — جلسات طويلة. P2/P3.

## 30. Roadmap (بلا تنفيذ)

**P0 — Immediate (account takeover / breach / financial / data loss / RCE):**
لا يوجد بند أمني CRITICAL مفتوح. الوحيد بأثر مالي = **BIZ-001** (مُعالَج، يحتاج دمج).

**P1 — High (security/reliability/integrity):**
فهارس (PERF-01) · audit trail أعمال (LOG-01) · idempotency الدفع (PAY-01) ·
UNIQUE الحضور (INTEG-01) · تشفير/off-site للنسخ (BKP-01) · ضمان `APP_DEBUG` مُطفأ (ENV-01).

**P2 — Medium (debt/scalability):**
تفكيك `captains.php` · CSP nonces (Stage 2، #40 يبدأها) · timeout الجلسة · دالة نطاق مشتركة · قرار `audit_logs`.

**P3 — Future (architecture):**
اختبارات آلية + CI · pagination · migrations بإصدارات · API layer · multi-tenancy · Laravel تدريجي.

## 31. Quick Wins (عالية القيمة/منخفضة المخاطر — بلا تنفيذ)

1. حزمة فهارس (migration إضافي) — أداء فوري.
2. `UNIQUE(member_id, attendance_date)` بعد تنظيف تكرارات — يغلق INTEG-01.
3. توسيع `app_log` ليشمل أحداث الدفع/الاشتراك/الحذف — audit trail أعمال.
4. ضمان `APP_DEBUG` غير مضبوط + `logrotate` — تشغيلي بحت.
5. اختبارات لدوال `training.php` النقية — شبكة أمان بلا مخاطر.

## 32. Architecture Recommendations (تصوّر مستقبلي فقط)

```
Presentation (Blade/صفحات) → Application/Services → Domain/Business Logic → Infrastructure/DB
XCAMP: Auth · Authz · Members · Memberships · Payments · Attendance · Coaching ·
        Assessments · Workouts · Nutrition · Reporting · Audit · API
```
نقل منطق triggers إلى خدمات مجال؛ استخراج الدوال النقية؛ طبقة مستودعات فوق PDO/Eloquent؛
`tenant_id` عند الحاجة؛ طبقة API للموبايل. **بلا تنفيذ الآن.**

## 33. Final Engineering Verdict

**Scores /100:**
- Security: **83** (أساسيات قوية + تحصين؛ نقص audit trail أعمال، timeout)
- Stability: **68** (مالية ذرّية؛ سباق حضور + لا idempotency + لا فهارس)
- Architecture: **58** (بسيط فعّال؛ god file + منطق في DB + بلا طبقات)
- Maintainability: **62**
- Scalability Readiness: **45** (single-tenant، بلا API، بلا pagination)
- Production Readiness: **72** (Staging جاهز؛ Production بشروط P1)

**Overall Engineering Risk: MEDIUM.**

**إجابات صريحة:**
- **آمن بما يكفي للاستمرار؟** نعم — لا CRITICAL/HIGH؛ آمن للتشغيل المُراقَب مع تنفيذ P1.
- **يحتاج Hardening قبل ميزات جديدة؟** الأمني الأساسي **مُنجَز**؛ المتبقّي **صلابة/اتّساق**
  (فهارس، idempotency، UNIQUE حضور، audit trail) — يُفضَّل قبل توسّع الميزات، لا يمنعه.
- **Laravel الآن أم تدريجيًا؟** **تدريجيًا (strangler-fig)** لاحقًا — ليس الآن؛ لا مبرّر عاجل.
- **إعادة تصميم القاعدة الآن؟** **لا** — المخطط سليم؛ يحتاج **فهارس + UNIQUE + (لاحقًا)
  tenant_id**، لا إعادة تصميم.
- **API لاحقًا بلا rewrite؟** **نعم** عبر استخراج تدريجي (جهد متوسط) — الدوال النقية تساعد.
- **Tenant-ready؟** **لا** — يحتاج `tenant_id` + نطاق إجباري (غير كارثي).

**أول 5 أشياء يجب إصلاحها (بعد إذنك، بلا تنفيذ الآن):**
1. دمج إصلاح **BIZ-001** (اتّساق مالي).
2. **فهارس** التصفية/التاريخ (PERF-01).
3. **UNIQUE الحضور** + تنظيف تكرارات (INTEG-01).
4. **idempotency الدفع** (PAY-01).
5. **audit trail أعمال** عبر توسيع `app_log` (LOG-01).

---

## Read-Only Integrity Statement

**لم أعدّل أي ملف مشروع في هذه المرحلة.** الوحيد المُنشأ هو هذا التقرير
`SYSTEM_SECURITY_STABILITY_AUDIT.md` (مخرج مسموح). حالة الشجرة قبل/بعد: التعديل الوحيد
المُتتبَّع سابقًا هو `dashboard/revenue.php` (commit `d8bf910` من مرحلة سابقة بموافقتك)،
وملفّا التوثيق `SYSTEM_AUDIT.md`/`docs/audit-decision-memo.md` (غير مُتتبَّعين، من مراحل
سابقة). لا تغييرات كود/SQL/schema/config جديدة بسبب هذا التدقيق.
