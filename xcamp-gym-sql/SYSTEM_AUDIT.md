# SYSTEM_AUDIT.md — XCAMP GYM

**نوع المهمة:** Discovery + Audit فقط — **لا تعديل على أي كود/SQL/schema.**
كل حكم مبنيّ على قراءة الكود الفعلي وتتبّع البيانات والعلاقات. تُصنَّف الأحكام:
`Confirmed` (دليل مباشر) · `Likely` · `Possible` · `Unknown`.

الأساس المفحوص: 28 صفحة PHP في `dashboard/` (~7,736 سطرًا)، 21 ملف SQL
(48 جدولًا، 59 مفتاحًا خارجيًا، 13 view، 11 procedure، 8 triggers، 1 event)،
وسكربتات النشر/النسخ الاحتياطي والتوثيق.

---

## 1. Executive Summary

XCAMP GYM نظام إدارة نادٍ/كوتشينج **Raw PHP 8 + PDO/MariaDB بلا framework**،
ناضج وظيفيًا وأقوى أمنيًا من المعتاد في مشاريع Raw PHP. الأساسيات الأمنية (Prepared
Statements، CSRF شامل، bcrypt، RBAC خادِمي، حماية IDOR، تحصين الرفع، ترويسات أمان)
**مطبَّقة فعليًا ومُتحقَّق منها**. نموذج البيانات غنيّ ومترابط (مفاتيح خارجية سليمة،
محرّك أتمتة عبر triggers/procedures).

**أبرز الفجوات (بأدلّة):** مصدرا حقيقة متوازيان لحالة الدفع (تقارير إيرادات قد تتضارب)،
غياب فهارس على أعمدة التصفية/التاريخ في المخطط الأساسي، غياب أي pagination، غياب أي
اختبارات آلية، وملفّ `captains.php` ضخم (god file). لا توجد ثغرات **CRITICAL** أمنية.

| المحور | التقدير |
|--------|---------|
| الجاهزية | **Staging-ready**؛ Production بشروط (فهارس + مصالحة الإيرادات + مراقبة) |
| أعلى المخاطر | تضارب أرقام الإيرادات (MEDIUM)، غياب الفهارس (MEDIUM)، صفر اختبارات (MEDIUM) |
| الحالة الأمنية | جيّدة (لا CRITICAL/HIGH بعد تحصين PR #36–#40) |

---

## 2. System Architecture

- **النمط:** صفحات PHP مُخدَّمة خادِميًا (page-per-feature)، ملف مشترك واحد `db.php`
  (اتصال + جلسة + مصادقة + CSRF + ترويسات + عناصر واجهة + مخطّط)، و`training.php`
  (مكتبة دوال نقية للحسابات التدريبية/التغذية). لا طبقة MVC، لا router، لا DI.
- **الحالة:** جلسات PHP (كوكي). لا API، لا JSON endpoints مخصّصة.
- **الأتمتة:** منطق أعمال مهمّ داخل قاعدة البيانات — 8 triggers (`AFTER INSERT`) تستدعي
  11 stored procedure (`sp_handle_*`) لإنشاء مهام/أعلام/رسائل/معالم تلقائيًا.
- **الأصول:** كل CSS/JS **inline** ذاتية (لا CDN، لا مكتبات خارجية). QR مولّد بـPHP نقي.
- **التشغيل:** `deploy.sh`/`run_all.sql` (نشر SQL بالترتيب)، `backup.sh`/`restore.sh`،
  و`provision_admin.php` (CLI).

## 3. Repository Structure

```
xcamp-gym-sql/
├── dashboard/        28 صفحة PHP (db.php + training.php مشتركان) + uploads/
├── sql/              00..20 (schema, procedures, triggers, events, views, seed, features)
├── docs/             security-audit.md, deployment-security.md, csp-plan.md
├── deploy.sh reset_and_deploy.sh update.sh run_all.sql
├── backup.sh restore.sh backup.cron.example
├── SECURITY.md FUTURE_ARCHITECTURE.md README.md
└── (لا composer.json، لا package.json، لا .env، لا مجلد tests)
```

## 4. Module Map (مستخرجة من الكود)

| Module | صفحات | جداول رئيسية | أدوار | ضوابط |
|--------|-------|--------------|-------|-------|
| Authentication (طاقم) | login, logout, account | users, coaches | كل الطاقم | bcrypt, CSRF, rate-limit, regenerate |
| Authentication (عضو) | member_login, member_logout | member_auth, members | العضو | bcrypt, CSRF, rate-limit |
| Members/CRM | index, crm, captains | members, followups, tasks, retention_flags | مدير/كابتن(نطاق) | require_role/scope, member_allowed |
| Subscriptions/Revenue | index, revenue | memberships, plans | مدير/كابتن(نطاق) | require_login + scope |
| Payments/POS/Finance | finance | payments, pos_sales, pos_sale_items, expenses, products | مدير فقط | require_role, transactions |
| Attendance | checkin, qr | daily_attendance, member_qr | طاقم | CSRF, trigger automation |
| Assessments | assess, assessment_print, athlete | member_assessments, assessment_* , assessments | طاقم(نطاق) | ownership check |
| Workouts/Progression | captains, session, templates, progression | workout_plans, workout_sessions, exercises, training_max | طاقم(نطاق) | member_allowed |
| Nutrition/Recipes | captains, recipes, portal | nutrition_plans, recipes, ingredients | طاقم | CSRF |
| Retention | retention, crm | retention_flags, tasks, milestones | طاقم(نطاق) | scope |
| Coach HR/PT | hr, pt | coach_hr, coach_payroll, coach_shifts, pt_sessions | مدير/كابتن | require_role/scope |
| Promotions | promos | discount_codes, discount_redemptions | مدير | require_role |
| Member Portal | portal, account | member_auth, workout/nutrition/progress | العضو | require_member |
| Analytics | analytics, revenue | views (vw_dashboard_*) | مدير | require_role |

## 5. Page Inventory (28 صفحة)

Auth: `L`=require_login · `R`=require_role(مدير) · `M`=require_member · `—`=عام.
CSRF مؤكَّد على **كل** الصفحات التي تعالج POST (19/19).

| Page | الغرض | Auth | POST | CSRF | Tx | Upload | ملاحظة مخاطر |
|------|-------|------|------|------|----|--------|--------------|
| login | دخول الطاقم | — | ✓ | ✓ | — | — | rate-limit ملفّي |
| member_login | دخول العضو | — | ✓ | ✓ | — | — | — |
| logout / member_logout | خروج | L/M | — | — | — | — | — |
| index | لوحة الإدارة + إنشاء عضو/اشتراك | R | ✓ | ✓ | ✓ | — | — |
| captains | مركز الكابتن (CRUD واسع) | L(scope) | ✓ | ✓ | ✓ | ✓(صورة) | **god file 1506 سطر** |
| crm | متابعة/قمع | L(scope) | ✓ | ✓ | — | — | scope عبر view |
| retention | أعلام الاحتفاظ | L(scope) | ✓ | ✓ | — | — | — |
| revenue | التجديدات/الإيراد | L(scope) | ✓ | ✓ | — | — | **تضارب payment_status** |
| finance | مدفوعات/POS/مصروفات | R | ✓ | ✓ | ✓ | — | مالي — transactional ✓ |
| checkin | حضور اليوم | L | ✓ | ✓ | — | — | **dedup بسباق** |
| qr | مولّد QR | L | — | — | — | — | — |
| assess | التقييم | L(scope) | ✓ | ✓ | ✓ | — | ملكية مؤكَّدة |
| assessment_print | طباعة التقييم | L | — | — | — | — | — |
| athlete | لوحة الرياضي | L(scope) | — | — | — | — | — |
| progression | التتبّع التكيّفي | L(scope) | ✓ | ✓ | — | — | scope ✓ |
| session | جلسة تدريب | L | ✓ | ✓ | — | — | — |
| templates | قوالب التمارين | L | ✓ | ✓ | — | — | — |
| recipes | الوصفات/الحاسبة | L | ✓ | ✓ | — | — | — |
| hr | شؤون الكباتن | R | ✓ | ✓ | ✓ | — | — |
| pt | جلسات PT | L(scope) | ✓ | ✓ | — | — | — |
| promos | الأكواد/الإحالات | R | ✓ | ✓ | — | — | — |
| analytics | التحليلات | R | — | — | — | — | يعتمد views |
| calendar | التقويم | L | — | — | — | — | — |
| portal | بوابة العضو | M | ✓ | ✓ | — | — | نطاق العضو نفسه |
| account | حساب الطاقم | L | ✓ | ✓ | — | — | — |
| provision_admin | تهيئة مدير | CLI | — | — | — | — | يرفض HTTP (403) |

## 6. Database Architecture

**48 جدولًا / 59 FK / 13 view / 11 procedure / 8 trigger / 1 event**، كلها
InnoDB + utf8mb4. سياسات الحذف مضبوطة بعناية: `CASCADE` لأبناء العضو،
`SET NULL` للكوتش/الاختياري، `RESTRICT` على `memberships→plans` (يمنع حذف خطة مستخدمة).

### علاقات أساسية (ER نصّي مبسّط)
```
users ──1:1?── coaches ──1:N── members ──1:N── memberships ──1:N── payments
                                   │                 └── plans (RESTRICT)
   members ──1:N── daily_attendance / assessments / member_assessments / injury_history
           ──1:N── workout_plans ──1:N── workout_sessions ──1:N── session_exercises
           ──1:N── nutrition_plans / supplements / progress_tracking / training_max
           ──1:N── followups / retention_flags ──1:N── tasks
           ──1:1── member_auth / member_qr
recipes ──N:M── ingredients (recipe_ingredients)
pos_sales ──1:N── pos_sale_items ; discount_codes ──1:N── discount_redemptions
audit_logs (معرّف، غير مُستخدَم من التطبيق)
```

### ملاحظات مخطط (Confirmed)
- **الفهارس:** المخطط الأساسي `01_tables.sql` لا يحوي فهارس ثانوية عدا PK/UNIQUE/FK.
  أعمدة تصفية/فرز شائعة بلا فهرس: `members.status`, `memberships.end_date /
  renewal_status / payment_status`, `payments.payment_date / status`,
  `daily_attendance.attendance_date`, `followups.next_followup_date`. (الملفات
  الإضافية 08/11/12 أضافت 28 فهرسًا لجداولها فقط.) → أثر أداء عند الحجم.
- **`daily_attendance`:** لا قيد `UNIQUE(member_id, attendance_date)` → الحماية من
  التكرار تطبيقية فقط.
- **`audit_logs`:** معرّف بحقول `old_data/new_data JSON` لكن **لا يكتب فيه التطبيق ولا
  الـtriggers** — نيّة تصميم غير مُنفَّذة (السجلّ الفعلي الآن ملفّي `security.log`).

## 7. Authentication Flow

مساران منفصلان تمامًا (جلستان: `$_SESSION['user']` للطاقم، `$_SESSION['member']` للعضو).
```
دخول → csrf_check → rate-limit (IP+بريد ولكل IP) → SELECT ... WHERE is_active=1
     → password_verify(bcrypt) → session_regenerate_id(true) → set session → redirect
فشل → app_log(SECURITY) + رسالة عامة لا تكشف وجود الحساب
```
- كوكي الجلسة: `HttpOnly` + `SameSite=Lax` + `Secure` تلقائيًا تحت HTTPS.
- تجديد المعرّف بعد الدخول (يمنع session fixation) — **Confirmed**.
- **فجوات:** لا timeout خمول/مطلق للجلسة (عمر المتصفّح فقط)؛ لا تدفّق «نسيت كلمة المرور»
  ذاتي (كلمة مرور العضو يعيّنها الطاقم)؛ التخزين الملفّي للـrate-limit best-effort.

## 8. Authorization / RBAC

الأدوار الفعلية (من `users.role` ENUM): **admin, manager, coach, reception** + عضو البوابة.
`is_manager()` = admin|manager|reception. الكابتن مقيَّد بأعضائه عبر `coach_id` (int)
و`member_allowed()`.

| الإجراء | Admin | Manager | Reception | Coach | Member |
|---------|:-----:|:-------:|:---------:|:-----:|:------:|
| لوحة الإدارة/إنشاء عضو (index) | ALLOW | ALLOW | ALLOW | DENY | DENY |
| المحاسبة/POS (finance) | ALLOW | ALLOW | ALLOW | DENY | DENY |
| شؤون الكباتن/الأكواد/التحليلات | ALLOW | ALLOW | ALLOW | DENY | DENY |
| CRM/الاحتفاظ/الإيرادات | ALLOW | ALLOW | ALLOW | CONDITIONAL(أعضاؤه) | DENY |
| تعديل عضو/برنامج/تقييم (captains) | ALLOW | ALLOW | ALLOW | CONDITIONAL(member_allowed) | DENY |
| بوابة العضو (portal) | DENY | DENY | DENY | DENY | ALLOW(نفسه) |

- **صفحات المدير محميّة خادِميًا** بـ`require_role()` (لا بإخفاء الروابط) — **Confirmed**.
- **IDOR:** أفعال الكابتن تُحلّ `targetMember` من مُعرّف الكائن ثم `member_allowed()` قبل
  الكتابة؛ crm/assess تتحقّق من الملكية. لم أجد IDOR — **Confirmed (لم يُعثر)**.
- **ملاحظة (Possible):** `revenue.php` بـ`require_login` + نطاق كابتن (مقصود)، لكن يجب
  التأكيد أن كل صفوف الـview مُقيَّدة فعلًا بالكابتن (يُبنى النطاق بـ`WHERE coach_id=(int)`).

## 9. Data Flow

```
Browser → POST (نموذج داخل <main>)
  → [AJAX] page_script يعترض ويرسل fetch لنفس المسار (X-Requested-With)
  → csrf_check() → require_login/require_role + member_allowed (ملكية)
  → validation (enum allowlist + (int)/(float) + trim) → PDO prepared → MariaDB
  → AFTER INSERT trigger → sp_handle_* → tasks/flags/messages/milestones
  → redirect (PRG) ؛ [AJAX] الخادم يعيد الصفحة كاملة، والعميل يستبدل <main>.innerHTML
```
نقاط الفشل المحتملة: فشل التحقّق (enum غير مطابق → null)، رفض التفويض (redirect)،
تضارب حالة الدفع (§11)، سباق الحضور (§12).

## 10. Security Audit (بعد تحصين PR #36–#40)

| ID | Severity | الحالة | ملاحظة |
|----|----------|--------|--------|
| SQL Injection | — | **لم يُعثر** (Confirmed) | Prepared شامل + `EMULATE_PREPARES=false`؛ الديناميكي int-cast/allowlist |
| CSRF | — | **مُغطّى** (Confirmed) | 19/19 صفحة POST؛ `random_bytes(32)`+`hash_equals` |
| AuthN/Session | — | جيّد | bcrypt، regenerate، is_active، rate-limit، كوكي مُقوّى |
| RBAC/IDOR | — | جيّد | require_role خادِمي + member_allowed |
| XSS | — | جيّد | `h()` (ENT_QUOTES\|ENT_SUBSTITUTE)؛ لا echo لـsuperglobal؛ لا eval |
| File upload | — | جيّد | finfo+getimagesize+امتداد مفروض+اسم عشوائي+.htaccess |
| Secrets | — | جيّد | من البيئة؛ fail-closed؛ لا سرّ في المصدر؛ لا .env في Git |
| Error leakage | — | مُعالَج | display_errors off + رسائل عامة + سجلّ |
| Headers | — | مُطبّقة | CSP/XFO/nosniff/Referrer/Permissions/HSTS |
| Open redirect / Path traversal / RCE / Deserialization | — | **لم يُعثر** (Confirmed) | لا Location من مدخل؛ لا include ديناميكي؛ لا `unserialize` لمدخل؛ لا eval/exec |
| Session timeout | LOW | مفتوح | لا idle/absolute timeout |

## 11. Business Logic Audit

- **دورة العضو/الاشتراك/الدفع:** إنشاء العضو (index/captains) → اشتراك (index) →
  دفع (finance، transactional: payments + `memberships.payment_status='paid'` + الكود).
- **BIZ-001 (MEDIUM, Confirmed) — مصدرا حقيقة للدفع:** `revenue.php::set_renewal`
  يضبط `memberships.payment_status='paid'` **بدون** إدراج صف في `payments`. بينما
  `finance.php` يحسب الدخل من `SUM(payments.amount)`. النتيجة: «المحصّل» في revenue
  (اشتراكات مُعلَّمة paid × السعر) قد **يتجاوز** دخل finance (مجموع المدفوعات) — تقارير
  متضاربة وغياب مصالحة. (اتجاه واحد: مسار finance متّسق؛ مسار revenue اليدوي لا يُسجّل دفعة.)
- **BIZ-002 (Possible) — صحّة القيم:** لم أتأكّد من منع `amount` سالب أو
  `end_date < start_date` أو اشتراك مكرّر نشِط؛ الإدخال أرقام من الواجهة — يلزم فحص
  التحقّق الخادِمي في index/finance (Possible bug، يحتاج تأكيد).
- **الأتمتة (Confirmed):** triggers→procedures تنشئ مهام/أعلام/رسائل عند
  الحضور/الدفع/التقييم/الإصابة/المتابعة — منطق قويّ لكنه «مخفيّ» في القاعدة.

## 12. Data Integrity

| الحماية | التطبيق | القاعدة |
|---------|---------|---------|
| مراجع خارجية صالحة | — | ✅ 59 FK بسياسات حذف صريحة |
| حذف يتيم | — | ✅ CASCADE/SET NULL |
| قيم enum متّسقة | ✅ allowlist خادِمي | ✅ ENUM |
| منع حضور مكرّر/يوم | ⚠️ SELECT-ثم-INSERT (سباق) | ❌ لا UNIQUE(member,date) |
| منع اشتراك مكرّر نشِط | ❓ غير مؤكَّد | ❌ لا قيد |
| تواريخ/أرقام صالحة | ❓ غير مؤكَّد (Possible) | جزئي (NOT NULL/DEFAULT) |
| audit trail | ملفّي (security.log) | ❌ audit_logs غير مُستخدَم |

## 13. Transaction & Concurrency

- **Transactional (Confirmed):** finance (POS: pos_sales+items+كود؛ membership payment:
  payments+membership+كود)، assess (تقييم متعدّد الجداول)، captains، hr، index —
  كلها `beginTransaction/commit` مع `rollBack` في catch.
- **INTEG-001 (LOW-MED, Likely) — سباق الحضور:** `checkin.php` يفحص التكرار ثم يُدرج بلا
  قيد UNIQUE → نافذة سباق تسمح بحضورين متزامنين لنفس العضو/اليوم.
- **BIZ (LOW, Possible) — ازدواج الدفع:** لا مفتاح idempotency على المدفوعات؛ الواجهة
  تعطّل الزر (عميل) لكن إعادة إرسال POST قد تكرّر — لا قيد `receipt_no` فريد.

## 14. Performance

- **PERF-001 (MEDIUM, Confirmed) — فهارس ناقصة:** لا فهارس على أعمدة التصفية/التاريخ في
  المخطط الأساسي (§6)؛ views اللوحات (`vw_dashboard_*`, `vw_at_risk_members`,
  `vw_overdue_payments`...) ستُجري فحص جداول عند آلاف الصفوف.
- **PERF-002 (MEDIUM, Confirmed) — لا pagination:** صفحات القوائم تجلب الجداول كاملة
  `ORDER BY` بلا `LIMIT`. مقبول ديمو؛ يتدهور مع النمو (أعضاء/مدفوعات/حضور).
- **N+1 (Possible):** الصفحات ذات العضو الواحد تُجري استعلامات فرعية محدودة؛ لم أرصد
  N+1 واسعًا في صفحات القوائم (تستخدم views/‏IN)، لكن يلزم تأكيد عند التوسّع.
- `SELECT *` في بعض قراءات الـviews/القوائم (تأثير طفيف).

## 15. Code Quality

| الملف/النمط | التقدير |
|-------------|---------|
| `db.php` (نواة مشتركة) | Good |
| `training.php` (دوال نقية) | Good (قابل للاختبار) |
| المخطط SQL + الأتمتة | Good/Acceptable (منطق مخفيّ في triggers) |
| `captains.php` (1506 سطر) | **High Technical Debt** (god file: HTML+PHP+SQL، أفعال كثيرة) |
| تكرار: auth-scope، بناء `$scope`, بطاقات الحالة | Needs Refactor (منطق نطاق مكرّر عبر الصفحات) |
| مزج HTML/PHP/SQL في الصفحات | Acceptable (نمط المشروع، لكن يصعب الاختبار) |

## 16. AJAX / Frontend

- **لا JSON endpoints.** `page_script()` يعترض نماذج POST داخل `<main>`، يرسل `fetch`
  لنفس المسار، ثم يستبدل `<main>.innerHTML` من HTML المُعاد. الخادم يعيد الصفحة كاملة.
- كل الضوابط الأمنية **خادِمية** (CSRF/تفويض) — لا اعتماد على تفويض العميل — **Confirmed**.
- `innerHTML` يُستبدَل بمحتوى خادِمي مُهرَّب بـ`h()`؛ `<script>` لا يُنفَّذ عبر innerHTML.
- تعطيل الزر أثناء الإرسال يقلّل الازدواج (عميل فقط). لا `eval`/`document.write`.

## 17. Configuration & Files

- إعداد من البيئة فقط (`DB_*`, `APP_DEBUG`, `SESSION_SECURE`, `CSP_REPORT_ONLY`,
  `APP_LOG_DIR`). لا `.env`، لا سرّ في Git (مؤكَّد بفحص التاريخ سابقًا).
- `uploads/` تحت جذر الويب لكن بـ`.htaccess` يمنع التنفيذ + امتداد مفروض. `logs/`
  و`backups/` مُستثناة من Git.
- `deploy.sh` يرشد GRANT محدود (بعد تصحيح SEC-005)؛ توثيق نشر آمن موجود.

## 18. Dependencies

| المكوّن | الإصدار | ملاحظة |
|---------|---------|--------|
| PHP | 8.x (يستخدم arrow-fn، `??=`، enums) | حديث |
| MariaDB | 10.11 / MySQL 8 | حديث |
| Composer / مكتبات PHP | **لا يوجد** | صفر تبعيات خارجية = سطح هجوم أصغر |
| JS/CSS libraries / CDN | **لا يوجد** | كله inline ذاتي |
| امتدادات PHP | `pdo_mysql` (مطلوب)، `finfo`، `gd`(للصور)، `mbstring`(بدائل آمنة) | — |

**لا مخاطر تبعيات معروفة** (لا سلسلة توريد). الجانب الآخر: لا ORM/إطار = عمل يدوي أكثر.

## 19. Testing Gaps

**لا توجد أي اختبارات آلية** (لا unit/integration/feature/security/regression).
الموجود فقط `07_test_queries.sql` (استعلامات تحقّق يدوية). أهم التدفّقات بلا تغطية:
1. المصادقة (دخول/فشل/rate-limit/تجديد جلسة).
2. التفويض/IDOR (كابتن↔عضو غيره).
3. تسجيل الدفع + مصالحة الإيراد (BIZ-001).
4. دورة الاشتراك والتواريخ.
5. دوال `training.php` النقية (قابلة للاختبار فورًا — فاكهة دانية).
6. سباق الحضور.

## 20. Production Readiness

| البيئة | التقييم | السبب |
|--------|---------|-------|
| Development | ✅ جاهز | نشر تجريبي + حسابات جاهزة |
| Staging | ✅ جاهز | نشر نظيف + مدير CLI + تحصين أمني + نسخ احتياطي |
| **Production** | ⚠️ **بشروط** | يلزم: فهارس (PERF-001)، مصالحة الإيراد (BIZ-001)، مراقبة/تدوير سجلّات، وحساب DB محدود الصلاحيات |
| Production Risk | متوسط | لا CRITICAL؛ المخاطر تشغيلية (أداء/اتّساق بيانات/غياب اختبارات) لا اختراق |

قوّة تشغيلية موجودة: نسخ احتياطي/استعادة مُختبَرة، معالجة أخطاء، سجلّ أمني، ترويسات،
HTTPS-aware. نقص: مراقبة/تنبيه، migrations بإصدارات، اختبارات.

## 21. Scalability / Future

- **يعمل الآن:** منشأة واحدة، أحجام صغيرة/متوسطة، فريق محدود.
- **يتعثّر مع النمو:** غياب الفهارس والـpagination (آلاف الصفوف)؛ صفحة واحدة/ميزة بلا
  فصل؛ منطق أعمال في triggers يصعب تطويره؛ لا API لتطبيق موبايل.
- **يحتاج إعادة تصميم لاحقًا (موثّق في FUTURE_ARCHITECTURE.md):** multi-tenancy،
  طبقة API، إطار عمل، نظام migrations، rate-limiter مركزي، CSP بـnonces.

## 22. Architecture Scores (0–100)

| المحور | الدرجة | السبب المختصر |
|--------|:-----:|----------------|
| Security | 85 | أساسيات قوية + تحصين OWASP؛ نقص: مراقبة، timeout جلسة |
| Authentication | 88 | bcrypt/regenerate/rate-limit؛ نقص: reset ذاتي، timeout |
| Authorization | 85 | RBAC خادِمي + IDOR مُعالَج؛ نطاق مكرّر يدويًا |
| Database | 78 | مخطط غنيّ وFK سليمة؛ نقص فهارس ثانوية + audit غير مستخدم |
| Code Quality | 62 | نظيف عمومًا لكن god file + تكرار نطاق + مزج طبقات |
| Architecture | 60 | بسيط وفعّال لكن بلا طبقات/API؛ منطق في القاعدة |
| Business Logic | 70 | غنيّ وظيفيًا؛ تضارب مصدر حقيقة الدفع (BIZ-001) |
| Data Integrity | 72 | FK ممتازة؛ نقص UNIQUE للحضور + تحقّق قيم غير مؤكَّد |
| Performance | 58 | لا فهارس تصفية ولا pagination |
| Testing | 15 | صفر اختبارات آلية |
| Deployment | 80 | نشر/نسخ احتياطي/توثيق جيّد؛ نقص migrations بإصدارات |
| Scalability | 45 | single-tenant، بلا API، قيود أداء |
| Maintainability | 62 | ملف مشترك جيّد؛ god file يخفض الدرجة |
| UX | 70 | RTL نظيف + تبويبات + AJAX؛ god page للكابتن |
| Product Readiness | 74 | تغطية ميزات واسعة؛ نقص إشعارات حقيقية/بوابة موسّعة |

## 23. Risk Matrix

| ID | Severity | المجال | الوصف | الأثر | الجهد | الأولوية |
|----|----------|--------|-------|-------|-------|----------|
| BIZ-001 | MEDIUM | Business/Data | مصدرا حقيقة للدفع (revenue vs payments) | تقارير مالية متضاربة | متوسط | **P0** |
| PERF-001 | MEDIUM | DB/Perf | فهارس ناقصة على التصفية/التاريخ | بطء عند النمو | منخفض | **P1** |
| TEST-001 | MEDIUM | Quality | صفر اختبارات آلية | انحدارات صامتة | متوسط | **P1** |
| PERF-002 | MEDIUM | Perf/Scale | لا pagination | تحميل ثقيل عند النمو | متوسط | P1 |
| INTEG-001 | LOW-MED | Integrity | سباق الحضور (لا UNIQUE) | حضور مكرّر نادر | منخفض | P1 |
| CODE-001 | MEDIUM | Maintainability | god file `captains.php` | صعوبة صيانة | مرتفع | P2 |
| DEBT-001 | INFO | Debt | `audit_logs` غير مستخدم | نيّة غير منفّذة | منخفض | P2 |
| BIZ-002 | LOW | Business | تحقّق القيم (تواريخ/سالب) غير مؤكَّد | إدخال غير صالح | منخفض | P1 |
| SESS-001 | LOW | Security | لا timeout جلسة | جلسة طويلة العمر | منخفض | P2 |
| SCALE-001 | INFO | Architecture | single-tenant/بلا API | حدود التوسّع | مرتفع | P3 |

## 24. Recommended Roadmap

### P0 — Immediate
- **BIZ-001 مصالحة الدفع:** توحيد مصدر الحقيقة — إمّا أن يُنشئ `revenue.php::set_renewal='paid'`
  صفَّ `payments` (أو يمنع التعليم اليدوي بلا دفعة)، أو أن تُحسب «المحصّل» من `payments`
  في الصفحتين. *تعقيد: متوسط · تبعيات: لا · خطر التأجيل: أرقام مالية غير موثوقة.*

### P1 — Hardening / Stability
- **PERF-001 فهارس:** إضافة فهارس على `members.status`, `memberships(end_date,
  renewal_status, payment_status)`, `payments(payment_date, status)`,
  `daily_attendance(member_id, attendance_date)`, `followups.next_followup_date`.
  *تعقيد: منخفض (migration إضافي) · خطر التأجيل: تدهور أداء.*
- **INTEG-001:** قيد `UNIQUE(member_id, attendance_date)` + `INSERT IGNORE`/`ON DUP`.
  *منخفض · يزيل السباق نهائيًا.*
- **TEST-001:** بدء باختبارات دوال `training.php` النقية + تدفّقات المصادقة/التفويض.
  *متوسط · شبكة أمان للانحدار.*
- **PERF-002 / BIZ-002:** pagination لصفحات القوائم؛ تأكيد/إضافة تحقّق التواريخ/السالب.

### P2 — Product
- إشعارات حقيقية (WhatsApp/SMS) على `messages_log` مع طابور وإعادة محاولة.
- بوابة العضو الموسّعة (اتجاهات 1RM/التغذية/الحضور).
- تفعيل/إحياء `audit_logs` (أو إزالته رسميًا).
- تفكيك `captains.php` تدريجيًا (استخراج المعالجات).

### P3 — Architecture (موثّق، بلا تنفيذ)
- multi-tenancy، طبقة API، migrations بإصدارات، rate-limiter مركزي، إطار عمل — راجع
  `FUTURE_ARCHITECTURE.md`.

## 25. Architecture Scores — انظر §22.

## 27. Open Questions
1. `revenue.php::set_renewal='paid'` بلا دفعة — سلوك مقصود أم يحتاج إنشاء دفعة؟ (BIZ-001)
2. هل يوجد تحقّق خادِمي يمنع `amount` سالب و`end_date < start_date` واشتراكًا مكرّرًا نشطًا؟ (BIZ-002)
3. هل `audit_logs` مقصود إحياؤه أم إزالته؟ (DEBT-001)
4. سياسة الاحتفاظ/التدوير لسجلّ `security.log` في الإنتاج؟

## 28. Technical Debt Register
| Debt | الموقع | الأثر | الحل المقترح |
|------|--------|-------|--------------|
| god file | `captains.php` (1506) | صيانة | تفكيك المعالجات |
| منطق نطاق مكرّر | صفحات متعددة | تكرار | دالة `scope_sql($me)` مشتركة |
| audit_logs غير مستخدم | schema | نيّة غير منفّذة | إحياء أو إزالة |
| منطق أعمال في triggers | `03_triggers.sql` | صعوبة تتبّع/اختبار | توثيق + اختبارات |
| صفر اختبارات | المشروع | انحدارات | إطار اختبار خفيف |
| لا فهارس تصفية | `01_tables.sql` | أداء | migration فهارس |

---

## Top 10 Strengths
1. Prepared statements شاملة + `EMULATE_PREPARES=false` — لا SQLi.
2. CSRF على 19/19 صفحة POST (مربوط بالجلسة، `hash_equals`).
3. RBAC خادِمي + حماية IDOR فعّالة (`member_allowed`).
4. bcrypt + `session_regenerate_id` + `is_active` + rate-limit مزدوج.
5. تحصين رفع الملفات (finfo+getimagesize+امتداد مفروض+`.htaccess`).
6. مخطط بيانات غنيّ ومترابط (59 FK بسياسات حذف سليمة، utf8mb4).
7. محرّك أتمتة (triggers→procedures) للمهام/الأعلام/الرسائل.
8. المدفوعات المالية transactional (payments+membership+كود ذرّيًا).
9. صفر تبعيات خارجية/CDN = سطح هجوم ضئيل + إعادة إنتاج سهلة.
10. تشغيل ناضج: نشر + نسخ احتياطي/استعادة مُختبَرة + توثيق أمني.

## Top 10 Weaknesses
1. صفر اختبارات آلية.
2. مصدرا حقيقة لحالة الدفع (revenue vs payments).
3. فهارس ثانوية ناقصة في المخطط الأساسي.
4. لا pagination في أي صفحة قوائم.
5. `captains.php` god file (1506 سطر).
6. منطق نطاق التفويض مكرّر يدويًا عبر الصفحات.
7. `audit_logs` معرّف وغير مُستخدَم.
8. لا قيد UNIQUE للحضور (سباق).
9. لا timeout خمول/مطلق للجلسة.
10. منطق أعمال مخفيّ في triggers يصعب تتبّعه/اختباره.

## Top 10 Risks
1. تقارير إيراد متضاربة (BIZ-001) — قرارات مالية خاطئة.
2. تدهور الأداء عند النمو (فهارس + pagination).
3. انحدارات صامتة (لا اختبارات) في نظام يعالج مدفوعات.
4. حضور مكرّر تحت التزامن (INTEG-001).
5. إدخال قيم غير صالحة (تواريخ/سالب) — Possible (BIZ-002).
6. ازدواج دفعة عند إعادة الإرسال (لا idempotency).
7. صعوبة الصيانة/التطوير بسبب god file + منطق DB.
8. جلسات طويلة العمر (لا timeout).
9. غياب مراقبة/تنبيه إنتاجي.
10. حدود التوسّع (single-tenant، بلا API).

## Top 10 Opportunities
1. مصالحة مالية موحّدة + تقرير دخل واحد موثوق.
2. حزمة فهارس + pagination (مكسب أداء كبير بجهد منخفض).
3. اختبارات لدوال `training.php` النقية (فاكهة دانية).
4. إشعارات حقيقية (WhatsApp/SMS) فوق `messages_log`.
5. بوابة عضو موسّعة (اتجاهات/التزام).
6. لوحة مؤشرات مالية موحّدة (MRR/تحصيل/متعثّر) من مصدر واحد.
7. تفكيك `captains.php` لوحدات أصغر قابلة للاختبار.
8. إحياء `audit_logs` كسجلّ تدقيق أعمال (بجانب security.log).
9. migrations بإصدارات + CI خفيف.
10. أساس API يمهّد لتطبيق موبايل.

## Top 10 Recommended Actions (مرتّبة)
1. **P0** توحيد مصدر حقيقة الدفع (BIZ-001).
2. **P1** إضافة فهارس التصفية/التاريخ (PERF-001).
3. **P1** بدء اختبارات آلية (training.php + auth/authz).
4. **P1** قيد UNIQUE للحضور (INTEG-001).
5. **P1** pagination لصفحات القوائم (PERF-002).
6. **P1** تأكيد/إضافة تحقّق القيم (تواريخ/سالب) (BIZ-002).
7. **P2** استخراج دالة نطاق تفويض مشتركة (إزالة التكرار).
8. **P2** قرار `audit_logs` (إحياء/إزالة).
9. **P2** بدء تفكيك `captains.php`.
10. **P2** timeout جلسة + تدوير `security.log`.
```
