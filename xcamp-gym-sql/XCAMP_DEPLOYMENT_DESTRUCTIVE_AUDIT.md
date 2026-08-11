# XCAMP_DEPLOYMENT_DESTRUCTIVE_AUDIT.md

**READ-ONLY.** لم يُنفَّذ `deploy.sh` ولا أي ملف SQL؛ لا تعديل/commit/push/merge. تحليل كود فقط.

---

## 1. Executive Summary

**التصنيف: (C) — السكربت بوتستراب ومسار تحديث في آن واحد، وهو الخطر الحرج بعينه.**

`deploy.sh` سكربت **إعادة بناء (rebuild)**: يشغّل `01_tables.sql` الذي يُسقط **20 جدولًا
أساسيًا**، بلا أي حارس أو تأكيد أو نسخ احتياطي. ومع ذلك:

1. **مهاجرات P0 (21/22/23) مُوصَّلة داخله** ⇒ صار **المسار الرسمي الوحيد** في المستودع
   لتطبيق تحصين P0 على أي قاعدة، بما فيها الإنتاج.
2. **`update.sh --deploy`** يقدّمه صراحةً كعملية **تحديث** بوصف حميد: «إعادة نشر السكيمة
   والبيانات» — بلا أي تحذير من الفقد.
3. **`00_init.sql` يضبط `FOREIGN_KEY_CHECKS = 0`** ⇒ الإسقاط ينجح رغم وجود جداول أبناء
   من المهاجرات اللاحقة، فالنتيجة ليست «مسحًا نظيفًا» بل **قاعدة تالفة بمراجع معلّقة**.

⇒ **P0 BLOCKER · DEPLOYMENT SAFETY: UNSAFE · PRODUCTION: NO-GO.**

## 2. Exact Evidence (FACT)

| الدليل | الموقع |
|---|---|
| `FILES` تتضمّن `01_tables.sql` **بلا شرط** | `deploy.sh:20-27` |
| **20** `DROP TABLE IF EXISTS` | `sql/01_tables.sql:11-30` |
| 11 DROP PROCEDURE · 8 DROP TRIGGER · 1 DROP EVENT · 6 DROP VIEW | `02/03/04/05` |
| **`SET FOREIGN_KEY_CHECKS = 0`** | `sql/00_init.sql` |
| **صفر حوارس** (لا confirmation/backup/detection/production-mode) | `grep` على `deploy.sh` = **0** |
| مهاجرات P0 موصولة داخل نفس `FILES` | `deploy.sh` (21/22/23) |
| «تحديث اللوحة بأمر واحد… `--deploy` = إعادة نشر السكيمة والبيانات» | `update.sh:5-11, 80-83` |
| `deploy.sh` مُوصى به لإطلاق الإنتاج | `README.md:126` (`DB_SEED=0 … ./deploy.sh`) |
| لا CI/CD | لا `.github/` ولا `Jenkinsfile` |

**الجداول العشرون المُسقَطة:** `audit_logs, milestones, messages_log, tasks,
retention_flags, supplements, nutrition_plans, workout_sessions, workout_plans,
progress_tracking, followups, daily_attendance, injury_history, assessments, payments,
memberships, plans, members, coaches, users`.

## 3. deploy.sh Execution Flow

```
set -Eeuo pipefail
  ↓ يتحقّق من وجود ملفات SQL فقط (require_file) — لا يتحقّق من وجود بيانات
  ↓ CREATE DATABASE IF NOT EXISTS  (لا يكتشف قاعدة قائمة، يستخدمها كما هي)
  ↓ 00_init.sql   → FOREIGN_KEY_CHECKS = 0      ← يعطّل الحماية
  ↓ 01_tables.sql → 20 × DROP TABLE ثم CREATE   ← 🔴 الفقد يقع هنا
  ↓ 02/03/04/05   → DROP + CREATE للإجراءات/التريغرات/الأحداث/الـviews
  ↓ [DB_SEED=1] 06_seed_data.sql                 ← يحقن بيانات تجريبية بمعرّفات ثابتة
  ↓ 08…19, 21, 22, 23  (إضافية، CREATE/ALTER IF NOT EXISTS)
  ↓ [DB_SEED=1] 20_seed_demo_portal, 07_test_queries
  ↓ "Deployment completed successfully"
```
**لا وضع migration-only · لا وضع production · لا تأكيد · لا نسخ احتياطي · لا كشف لقاعدة
مأهولة.** مخرجات SQL تذهب إلى `logs/deploy.log` فقط (الأخطاء لا تظهر على الشاشة).

## 4. SQL File Classification

| الملف | التصنيف | عمليات مُدمِّرة | ملاحظة |
|---|---|---|---|
| `00_init.sql` | **BOOTSTRAP** | — (لكن `FOREIGN_KEY_CHECKS=0`) | CREATE DATABASE IF NOT EXISTS |
| `01_tables.sql` | **REBUILD** 🔴 | **20 × DROP TABLE** + CREATE | مُدمِّر بالكامل |
| `02_procedures.sql` | REBUILD | 11 × DROP PROCEDURE + CREATE | يُعاد إنشاؤها بأمان (منطق لا بيانات) |
| `03_triggers.sql` | REBUILD | 8 × DROP TRIGGER + CREATE | آمن الإعادة |
| `04_events.sql` | REBUILD | 1 × DROP EVENT + CREATE | آمن الإعادة |
| `05_views.sql` | REBUILD | 6 × DROP VIEW + CREATE OR REPLACE | آمن الإعادة |
| `06_seed_data.sql` | **SEED** ⚠️ | INSERT بمعرّفات ثابتة | يصطدم/يلوّث لو وُجدت بيانات (مُقيَّد بـ`DB_SEED`) |
| `20_seed_demo_portal.sql` | SEED | INSERT IGNORE | مُقيَّد بـ`DB_SEED` |
| `21_payment_idempotency.sql` | **MIGRATION** ✅ | ADD COLUMN/UNIQUE `IF NOT EXISTS` | إضافي · idempotent · **غير مُدمِّر** |
| `22_attendance_unique.sql` | **MIGRATION** ✅ | ADD UNIQUE `IF NOT EXISTS` | إضافي · يفشل عند وجود تكرارات |
| `23_perf_indexes.sql` | **MIGRATION** ✅ | 6 × CREATE INDEX `IF NOT EXISTS` | إضافي · غير مُدمِّر |
| `08…19` | MIGRATION | CREATE TABLE IF NOT EXISTS / INSERT IGNORE | إضافية |

**الخلاصة:** ملفات المهاجرات **آمنة بذاتها تمامًا**؛ الخطر كلّه في **الأداة** التي تحملها.

## 5. Repository Usage Analysis

- `README.md:74` — `./deploy.sh` كتثبيت أساسي (سياق تثبيت جديد).
- `README.md:126` — **قسم Go-Live:** `DB_SEED=0 … ./deploy.sh` ⇒ الاستخدام **المقصود
  والموثَّق** = **تهيئة إنتاج على قاعدة فارغة** (بوتستراب مشروع).
- `README.md:222/228` — `reset_and_deploy.sh` للتطوير فقط (يُسقط القاعدة كاملة).
- **`update.sh:10, 81`** — 🔴 يقدّم `--deploy` بوصف **«إعادة نشر السكيمة والبيانات»** ضمن
  سكربت اسمه «تحديث»، بلا تحذير ⇒ **مصدر الالتباس الأخطر**.
- **لا شيء في المستودع يقول صراحةً إن `deploy.sh` يُستخدم لتحديث إنتاج قائم** — لكن
  **لا شيء يمنعه أو يحذّر منه**، و**مهاجرات P0 لا سبيل لتطبيقها إلا عبره** (كما وُصِّلت).
- **PR #41** (وصفي أنا) يقول «Apply migrations in documented order» بلا تسمية الآلية ⇒
  المشغّل سيصل حتمًا إلى `deploy.sh`. **هذا خلل في وصف الـPR يجب تصحيحه.**

## 6. Production Data-Loss Scenario (تحليل، لم يُنفَّذ)

لو شُغّل `deploy.sh` على إنتاج مأهول:

1. **الجداول المفقودة:** العشرون أعلاه — أي **كل** الأعضاء، الاشتراكات، **المدفوعات**،
   الحضور، التقييمات، البرامج، المتابعات، المهام، الرسائل، وحسابات الموظّفين.
2. **البيانات المفقودة:** كامل السجلّ التاريخي والمالي. **لا استثناء.**
3. **المفاتيح الخارجية:** 🔴 لأن `00_init` يضبط `FOREIGN_KEY_CHECKS = 0`، تنجح عمليات
   الإسقاط **رغم** وجود جداول أبناء أنشأتها المهاجرات اللاحقة (`member_auth`, `member_qr`,
   `pos_sales`, `training_max`, `member_assessments`, `pt_sessions`, `coach_*` …) — وهذه
   **لا تُسقَط** (تستخدم `CREATE IF NOT EXISTS`). النتيجة: صفوفها تبقى وتشير إلى
   `member_id`/`coach_id` **لم تعد موجودة**، ثم يُعاد إنشاء `members` بعدّاد جديد ⇒
   **تلف مرجعي وإسناد خاطئ محتمل لأعضاء جدد** — أسوأ من المسح النظيف.
4. **`06_seed_data.sql`:** تحت `DB_SEED=1` (الافتراضي) يحقن أعضاء/موظّفين تجريبيين
   بمعرّفات ثابتة فوق قاعدة الإنتاج المُعاد بناؤها.
5. **الإجراءات/التريغرات/الأحداث/الـviews:** تُعاد إنشاؤها بأمان (منطق لا بيانات) ✅.

## 7. Transaction / Failure Analysis

- **لا معاملة تغلّف النشر** — و**DDL في MariaDB غير معاملاتي** (implicit commit لكل
  `DROP`/`CREATE`).
- `set -Eeuo pipefail` يوقف التنفيذ عند أول فشل ⇒ **لا يُعلن نجاحًا كاذبًا**، لكنه
  **يوقف بعد وقوع الضرر**.
- **التنفيذ الجزئي يترك قاعدة مكسورة:** فشل في منتصف `01_tables.sql` ⇒ بعض الجداول
  مُسقَطة وأخرى لا، مع `FOREIGN_KEY_CHECKS=0` ⇒ حالة غير متّسقة.
- المهاجرات 21/22/23 وحدها **idempotent** فإعادة تشغيلها آمنة.

## 8. Rollback Analysis

**لا rollback على الإطلاق.** لا لقطة، لا نسخة تلقائية قبل التنفيذ، ولا آلية تراجع عن
`DROP TABLE`. **التعافي الوحيد الممكن = استعادة نسخة احتياطية سابقة** — وقد سبق توثيق أن
قابلية الاستعادة **غير مُتحقَّقة** وأن النسخ **لا تشمل `uploads/`** ولا نسخة off-site.
⇒ في أسوأ سيناريو: **فقد نهائي**.

## 9. Severity

| البعد | التقييم |
|---|---|
| الاحتمال | **متوسط** (يتطلب خطأ بشريًا، لكن الطُّعم موجود: `update.sh --deploy` + وصف PR غامض + المهاجرات موصولة هنا) |
| الأثر | **كارثي** (فقد كل البيانات المالية والتشغيلية + تلف مرجعي) |
| قابلية الكشف | فوري لكن **بعد فوات الأوان** |
| التعافي | يعتمد كليًا على نسخة **غير مُثبتة الاستعادة** |
| **الشدّة الكلية** | 🔴 **CRITICAL** |

## 10. Classification

**P0 BLOCKER.**
الحكم **ليس** لمجرد وجود `DROP` (بوتستراب يُعيد البناء أمر مشروع بذاته)، بل لأن:
- المهاجرات الإضافية **وُصِّلت داخل السكربت المُدمِّر** ⇒ صار مسار تطبيق P0 على الإنتاج.
- `update.sh --deploy` **يسوّقه كتحديث** بلا تحذير.
- **لا حارس واحد** يمنع تشغيله على قاعدة مأهولة.
⇒ هذا يحوّله من «بوتستراب مقبول بالتصميم» إلى **فخّ نشر حقيقي**.

## 11. Recommended Architecture (اقتراح فقط — لم يُنفَّذ)

```
bootstrap.sh   ← تثبيت جديد فقط: 00 → 01 → 02 → 03 → 04 → 05 [→ 06 seed]
                 يرفض التنفيذ إن كانت القاعدة مأهولة إلا بـ--force صريح

migrate.sh     ← تحديث الإنتاج: المهاجرات الإضافية فقط (08…19, 21, 22, 23)
                 لا DROP إطلاقًا · idempotent · يسجّل الإصدار المُطبَّق

deploy.sh      ← wrapper رفيع يوجّه إلى أحدهما، ويُلزم اختيار الوضع صراحةً
```
مع جدول `schema_migrations` لتتبّع ما طُبِّق (لا يوجد حاليًا).

## 12. Safe Migration Strategy (لتطبيق P0 على الإنتاج اليوم)

```bash
# 1) نسخة + إثبات استعادة (على قاعدة منفصلة) + نسخ uploads/ يدويًا
# 2) preflight (قراءة فقط): تكرارات الحضور يجب أن تعود بصفر صفوف
# 3) تطبيق تفاضلي — الملفات الثلاثة فقط، بلا deploy.sh:
mysql --defaults-extra-file=<cnf> xcamp_gym < sql/21_payment_idempotency.sql
mysql --defaults-extra-file=<cnf> xcamp_gym < sql/22_attendance_unique.sql
mysql --defaults-extra-file=<cnf> xcamp_gym < sql/23_perf_indexes.sql
# 4) نشر الكود  5) تحقّق ما بعد النشر
```
🚫 **لا تشغّل** `deploy.sh` · `reset_and_deploy.sh` · `update.sh --deploy` · `update.sh --reset`
على الإنتاج.

## 13. Required Guards (مقترحة، غير مُنفَّذة)

1. **كشف قاعدة مأهولة** في `deploy.sh`: إن وُجدت صفوف في `members`/`payments` ⇒ توقّف
   ما لم يُمرَّر `--force-rebuild` صريح.
2. **تأكيد تفاعلي** بكتابة اسم القاعدة (كما في `restore.sh`).
3. **نسخة تلقائية إلزامية** قبل أي DDL مُدمِّر.
4. **فصل `migrate.sh`** لتشغيل الإضافية فقط.
5. **تحذير صريح في `update.sh`** لخياري `--deploy`/`--reset` (مُدمِّران).
6. **إزالة `FOREIGN_KEY_CHECKS=0`** من مسار الإنتاج أو تقييده بالبوتستراب.
7. **بانر تحذيري في رأس `deploy.sh` و`01_tables.sql`.**

## 14. Relationship to PR #41

- **كود P0 نفسه بريء** — التغييرات في `finance/revenue/checkin/captains/session` والمهاجرات
  الثلاث **غير مُدمِّرة** و37/37 اختبارًا خضراء. الخطر **ليس في ما بُني** بل في **كيفية نشره**.
- 🔴 **خلل في وصف الـPR:** قسم *Production Deployment Preconditions* يقول
  «Apply migrations in documented order» **دون تسمية الآلية ودون حظر `deploy.sh`** ⇒
  تعليمة قد تقود إلى فقد كامل. **يجب تصحيح الوصف قبل الدمج** (تعديل وصف PR فقط، لا كود).
- **توصيل 21/22/23 داخل `deploy.sh`** (commit `672f7e7`) صحيح لتثبيت جديد، لكنه **يوسّع
  سطح الخطر** ما لم يُضَف مسار تفاضلي.

## 15. Production GO / NO-GO

- **Merge PR #41:** مقبول **بشرط** تصحيح وصف النشر (بند 14).
- **Production deployment عبر `deploy.sh`:** **NO-GO — محظور.**
- **Production deployment عبر التطبيق التفاضلي (§12) + بوّابات النسخ/الاستعادة/التكرارات:**
  مسموح **بعد** استيفائها.

---

=== FINAL VERDICT ===

**DEPLOYMENT SAFETY: UNSAFE**
(بوتستراب مُدمِّر بلا حوارس، يحمل مهاجرات الإنتاج، ويُقدَّم كـ«تحديث» عبر `update.sh --deploy`)

**PRODUCTION DEPLOYMENT: NO-GO**
(عبر `deploy.sh`؛ المسار التفاضلي في §12 هو البديل المقبول بعد البوّابات)

**SEVERITY: P0**
