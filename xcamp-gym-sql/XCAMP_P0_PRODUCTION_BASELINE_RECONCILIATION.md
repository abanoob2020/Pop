# XCAMP P0 — PRODUCTION BASELINE & MIGRATION RECONCILIATION REPORT

**PHASE 5 — تحليل ساكن للمستودع + تصميم تسوية. لم تُنفَّذ أي SQL، ولم يُتَّصل بأي قاعدة إنتاج.**
وثيقة توثيقية فقط — لا تُنشئ تتبّع مهاجرات ولا تعدّل أي منطق نشر.

---

## 1. Executive Summary

- **خط أساس الإنتاج (Production baseline): UNKNOWN.** لم يُفحَص مخطط الإنتاج الفعلي في هذا
  التدقيق (لا آلية وصول قراءة-فقط متاحة).
- **تاريخ المهاجرات (Migration history): UNKNOWN / PARTIAL.** لا يوجد أي تتبّع في المستودع
  ولا أي دليل نشر إنتاجي.
- **التنفيذ: NO-GO.** التصميم مكتمل، لكن البيانات الواقعية غائبة.
- ⚠️ **الخطر الجوهري:** إنشاء تاريخ مهاجرات بلا تسوية قد **يُعلّم مهاجرات لم تُطبَّق كأنها
  مُطبَّقة**، فتُتخطّى إلى الأبد — وهذا **أسوأ من غياب التتبّع أصلًا**.

## 2. Repository Schema Baseline

**فصل إلزامي بين حالتين لا يجوز الخلط بينهما:**

| | المصدر | الحالة |
|---|---|---|
| **REPOSITORY EXPECTED STATE** | ملفات `sql/` في هذا الفرع | ✅ **معروفة** (موثّقة أدناه) |
| **ACTUAL PRODUCTION STATE** | قاعدة الإنتاج نفسها | ❌ **UNKNOWN — لم تُفحَص** |

**48 جدولًا متوقّعًا** = 20 (`01_tables.sql`) + 28 (`08…19`).

### FRESH INSTALL BASELINE (مُرتَّب — الحالة المتوقّعة من المستودع)
```
00_init → 01_tables → 02_procedures → 03_triggers → 04_events → 05_views
        → 08 → 09 → 10 → 11 → 12 → 13 → 14 → 15 → 16 → 17 → 18 → 19
        → 21 → 22 → 23
[ديمو اختياري فقط: 06_seed_data, 20_seed_demo_portal, 07_test_queries]
```
**دليل ترتيبي:** `05_views` يُنفَّذ قبل `08…19` وينجح ⇒ الـviews تعتمد حصريًا على جداول
`01` (استنتاج مسنود بنجاح التنفيذ، لا افتراض).

## 3. Complete SQL Inventory (27 ملفًا — بحث شامل لا بالأسماء المعروفة)

| File | Type | Creates | Alters | Drops | Data | الدور |
|---|---|:---:|:---:|:---:|:---:|---|
| `sql/00_init.sql` | BOOTSTRAP | DB | — | — | — | تهيئة + `FOREIGN_KEY_CHECKS=0` |
| `sql/01_tables.sql` | **BASELINE** | 20 tbl | — | **20** | — | أساس مُدمِّر |
| `sql/02_procedures.sql` | PROCEDURE | 11 | — | 11 | — | منطق |
| `sql/03_triggers.sql` | TRIGGER | 8 | — | 8 | — | أتمتة |
| `sql/04_events.sql` | EVENT | 1 | — | 1 | — | مجدول |
| `sql/05_views.sql` | VIEW | 13 | — | 6 | — | تقارير |
| `sql/06_seed_data.sql` | **SEED** | — | — | — | INSERT بمعرّفات ثابتة | ديمو |
| `sql/07_test_queries.sql` | **TEST** | — | — | — | SELECT | تحقّق |
| `sql/08_workout_v2.sql` | MIGRATION | 5 tbl | — | — | INSERT IGNORE | تمارين/قوالب |
| `sql/09_nutrition_v2.sql` | MIGRATION | 1 | — | — | — | سجلّ تغذية |
| `sql/10_member_portal.sql` | MIGRATION | 1 | — | — | — | مصادقة العضو |
| `sql/11_finance_pos.sql` | MIGRATION | 4 | — | — | INSERT IGNORE | POS/مصروفات |
| `sql/12_checkin_qr.sql` | MIGRATION | 1 | — | — | — | رمز QR |
| `sql/13_coach_hr.sql` | MIGRATION | 3 | — | — | INSERT IGNORE | شؤون الكباتن |
| `sql/14_pt_sessions.sql` | MIGRATION | 1 | — | — | INSERT IGNORE | جلسات PT |
| `sql/15_referrals.sql` | MIGRATION | 2 | — | — | INSERT IGNORE | أكواد خصم |
| `sql/16_assessments.sql` | MIGRATION | 3 | — | — | — | تقييمات |
| `sql/17_assessment_clinical.sql` | MIGRATION | 3 | — | — | — | FMS/قوام/اختلالات |
| `sql/18_recipes.sql` | MIGRATION | 3 | — | — | INSERT IGNORE | وصفات |
| `sql/19_training_max.sql` | MIGRATION | 1 | — | — | — | 1RM تكيّفي |
| `sql/20_seed_demo_portal.sql` | **SEED** | — | — | — | INSERT IGNORE | ديمو بوابة |
| `sql/21_payment_idempotency.sql` | **MIGRATION** | — | **2 ALTER** | — | — | عمود + UNIQUE |
| `sql/22_attendance_unique.sql` | **MIGRATION** | — | **1 ALTER** | — | — | UNIQUE حضور |
| `sql/23_perf_indexes.sql` | **MIGRATION** | 6 idx | — | — | — | فهارس أداء |
| `run_all.sql` | BOOTSTRAP | — | — | — | — | منفذ بديل (نفس التسلسل) |
| `dashboard/setup_logins.sql` | **ORPHAN/UNKNOWN** | — | — | — | **UPDATE users** | كلمات مرور ديمو |
| `dashboard/setup_member_logins.sql` | **ORPHAN/UNKNOWN** | — | — | — | بوابة الأعضاء | ديمو |

## 4. Migration Dependency Graph

```
01_tables (users, members, memberships, payments, daily_attendance, workout_sessions, followups…)
   ├─► 08 exercises · session_exercises→workout_sessions · templates
   │      └─► 19 training_max → (members, exercises)          ← تبعية عابرة للملفات
   ├─► 09 nutrition_logs      ├─► 10 member_auth ──► 20 seed portal
   ├─► 11 POS                 ├─► 12 member_qr
   ├─► 13 coach_hr/shifts ──► 14 pt_sessions (يعتمد ورديات الكابتن)
   ├─► 15 discount_codes → discount_redemptions
   ├─► 16 member_assessments ──► 17 assessment_fms/posture/imbalances   ← تبعية حقيقية
   ├─► 18 ingredients → recipes → recipe_ingredients (مستقل عن الباقي)
   ├─► 21 ALTER payments      ├─► 22 ALTER daily_attendance
   └─► 23 فهارس على (members, memberships, daily_attendance, followups)
```

> ⚠️ **قاعدة إلزامية:** الترتيب الرقمي **كافٍ اليوم** بحسب التبعيات المرصودة (كل تبعية تشير
> إلى رقم أدنى: 17→16، 19→08، 20→10، 14→13)، **لكنه ليس قاعدة معمارية مضمونة**.
> **يجب التحقّق من ترتيب المهاجرات من التبعيات المُعلَنة/المكتشفة — ولا يجوز الوثوق بالترقيم
> الرقمي وحده.**

## 5. Known vs Unknown Migration State

بحث شامل عن `schema_migrations|migration_history|applied_at|checksum|schema_version`:
**لا نتيجة واحدة** خارج ذكر تطلّعي في `FUTURE_ARCHITECTURE.md`.

| Migration | حالة الإنتاج | المبرّر |
|---|:---:|---|
| `01–06` (الأساس) | **UNKNOWN** | نُشرت يومًا ما — بأي إصدار؟ لا سجلّ |
| `08…19` | **UNKNOWN** | وجودها في المستودع **لا يعني** تطبيقها |
| `21`, `22`, `23` | **UNKNOWN** | أُنشئت في فرع **غير مدموج**؛ لم تُنشر قط |

**CONFIRMED APPLIED:** لا شيء · **LIKELY APPLIED:** لا شيء · **CONFIRMED NOT APPLIED:** لا شيء
· **UNKNOWN:** الكل.

> **قاعدة صارمة:** لا تُرفَّع حالة `UNKNOWN` إلى `APPLIED` بلا دليل من المخطط الفعلي.
> لا يوجد أي دليل نشر إنتاجي في المستودع (لا سجلّات — `logs/` مُستثنى من git، لا CI،
> لا release notes). **لم يُخترَع أي تاريخ.**

## 6. Production Schema Reconciliation

**لم يُفحَص مخطط الإنتاج الفعلي في هذا التدقيق.** لا آلية وصول قراءة-فقط متاحة؛ القاعدة
المحلية **ساندبوكس مُثبَتة** (loopback · بيانات ديمو · أُعيد بناؤها مرارًا) ⇒
**PRODUCTION SCHEMA: UNKNOWN.**

### ما يجب أن يُثبته الفحص المستقبلي (قراءة فقط)
1. وجود الجداول العشرين الأساسية + أيّ من جداول `08…19` موجود.
2. **حالة 21:** وجود `payments.idempotency_key` **وتعريفه** (`VARCHAR(64) NULL`) + وجود
   قيد فريد على العمود (بالاسم أو بأعمدة مكافئة).
3. **حالة 22:** وجود `uq_attendance_member_day` أو أي قيد فريد على `(member_id, attendance_date)`.
4. **حالة 23:** أيّ من الفهارس الستة موجود، وبأي أسماء/أعمدة.
5. **الأهلية:** تكرارات `(member_id, attendance_date)` = 0.
6. **هوية القاعدة:** الاسم/المضيف + وجود بيانات حقيقية.

### نموذج تحديد الحالة
| الحالة | التعريف |
|---|---|
| **APPLIED** | كل كائنات المهاجرة موجودة **وتعريفها مطابق** |
| **PENDING** | **صفر** من كائناتها موجود، والأهلية محقّقة |
| **PARTIAL** | بعض كائناتها موجود وبعضها لا |
| **CONFLICT** | كائن موجود بتعريف مختلف (نوع/nullability/أعمدة/اسم قيد مغاير) |
| **UNKNOWN** | تعذّر الحسم |

## 7. Migration Tracking Design

**التوصية المفاهيمية:** جدول `schema_migrations`
```
migration_id      VARCHAR(120) PK    -- اسم الملف (مُعرّف مستقر)
migration_name    VARCHAR(200)
checksum          CHAR(64)           -- SHA-256 لمحتوى الملف
status            ENUM('running','success','failed','partial')
started_at        DATETIME NOT NULL
completed_at      DATETIME NULL
execution_time_ms INT NULL
applied_by        VARCHAR(100)
```

> 🛑 **NOT IMPLEMENTED** — لم يُنشأ هذا الجدول ولن يُنشأ في هذه المرحلة.
> 🛑 **MUST NOT BE BOOTSTRAPPED WITH ASSUMED HISTORY** — يُمنَع منعًا باتًّا ملؤه بافتراض
> أن مهاجرات الأساس «مُطبَّقة»؛ كل صفّ يجب أن يستند إلى **تحقّق فعلي من المخطط**.

**السلوك الآمن عند قتل العملية:** يُكتب صفّ `running` **قبل** التنفيذ ⇒ الانقطاع يترك
`running` معلّقًا ⇒ التشغيل التالي **يرفض البدء** ويطلب تدخّلًا بشريًا. الحالات الأربع
(`running/success/failed/partial`) **كلها ضرورية**؛ تسجيل النجاح وحده يخفي الانقطاع.

## 8. Baseline Strategy — Reconciliation-First

| Strategy | Safety | خطر إيجابي كاذب | خطر سلبي كاذب | القرار |
|---|:---:|:---:|:---:|---|
| A — افتراض أن كل الأساس مُطبَّق | ❌ | **مرتفع** | منخفض | **مرفوضة** |
| B — الاستنتاج من الكائنات | متوسطة | متوسط | متوسط | مُولِّد أدلّة فقط |
| C — مهاجرة أساس تشهد بالمخطط | جيّدة | منخفض | منخفض | سجلّ فقط |
| **D — تسوية يدوية ثم تعليم المُتحقَّق فقط** | **عالية** | **منخفض جدًا** | منخفض | ✅ **مُختارة** |

**التركيب المعتمد:** (B) يُنتج تقرير تسوية آليًّا ⇒ (D) مراجعة واعتماد بشري ⇒ (C) تُسجَّل
النتيجة كصفوف bookkeeping (**بلا تنفيذ SQL** — تسجيل فقط).

> **القواعد الحاكمة:**
> - **NO ASSUMED BASELINE** — لا أساس مفترض.
> - **NO FALSE APPLIED MARKERS** — لا تعليم زائف بالتطبيق.
> - **FAIL CLOSED ON UNKNOWN STATE** — التوقّف عند أي غموض.

## 9. Partial Migration Detection

| المهاجرة | الكائنات | Partial ممكن؟ | التفصيل |
|---|:---:|:---:|---|
| **21** | **2** (عمود + UNIQUE) | ✅ **نعم** | كائنان مستقلّان ⇒ «عمود موجود/قيد مفقود» حالة حقيقية ⇒ **PARTIAL**. وأيضًا: عمود بنوع مختلف (`VARCHAR(32)`) أو `NOT NULL` ⇒ **CONFLICT**؛ قيد فريد على نفس العمود **باسم آخر** ⇒ CONFLICT (إضافته تُنشئ فهرسًا مكرّرًا) |
| **22** | **1** (UNIQUE) | ⚠️ ذرّية | **خاصية بنيوية:** بمجرّد وجود القيد بنجاح **يستحيل** بقاء بيانات مكرّرة (القاعدة تفرضه) ⇒ الحالة «قيد موجود + تكرارات باقية» **مستحيلة بنيويًا**. **ومع ذلك يبقى فحص البيانات المسبق إلزاميًا قبل تطبيق القيد** |
| **23** | **6** فهارس | ✅ **نعم** | 4/6 أو 5/6 ⇒ **PARTIAL** بحالات متعدّدة؛ فهرس مكافئ باسم آخر على نفس العمود ⇒ CONFLICT/تكرار |

**القاعدة التصميمية:** المقارنة بـ**(الجدول، الأعمدة، النوع، التفرّد)** لا بالاسم وحده.

## 10. Schema Drift

| فئة الانحراف | القرار |
|---|:---:|
| MISSING TABLE (من الأساس) | 🔴 **BLOCK** |
| MISSING COLUMN / WRONG DEFINITION | 🔴 **BLOCK** |
| MISSING CONSTRAINT | 🟠 **BLOCK** (يُحسم بالتسوية: مهاجرة معلّقة أم انحراف؟) |
| WRONG CONSTRAINT (أعمدة/تفرّد مختلف) | 🔴 **BLOCK** |
| EXTRA TABLE / EXTRA COLUMN | 🟠 **BLOCK** (تعديل يدوي غير مُتتبَّع) |
| MISSING INDEX | 🟡 WARN |
| EXTRA INDEX | 🔵 WARN |
| WRONG VIEW / TRIGGER / PROCEDURE | 🟠 WARN → BLOCK |

**DRIFT = BLOCK** كلما مسّ الانحراف جدولًا/عمودًا/نوعًا/قيدًا يعتمد عليه أي كود — مع
**fail-closed** إلزامي للانحراف الجوهري.

## 11. Failure / Recovery Model

**تمييز إلزامي — DDL في MariaDB غير معاملاتي ⇒ لا يوجد transaction rollback للمهاجرات:**

| الآلية | متى تُستخدم |
|---|---|
| **Transaction rollback** | ❌ **غير متاح** للمهاجرات البنيوية (implicit commit) — **لا نَعِد به** |
| **Backup restore** | التلف/الفقد الجسيم فقط |
| **Forward-fix migration** | المسار الافتراضي لإصلاح فشل بنيوي |

| نوع التغيير | الاستراتيجية |
|---|---|
| إضافة فهرس (23) | عكس يدوي `DROP INDEX` (رخيص) |
| إضافة قيد UNIQUE (22) | عكس يدوي `DROP INDEX` |
| إضافة عمود nullable (21) | عكس يدوي `DROP COLUMN` (يفقد قيم العمود الجديد فقط) |
| تحويل بيانات (مستقبلًا) | **RESTORE** أو **FORWARD FIX** |

**مثال (21 ✅، 22 ❌، 23 لم يُحاوَل):** السجلّ = `21=success · 22=failed(+سبب) · 23=غائب`؛
التشغيل التالي يعيد **22 فقط** بعد preflight ناجح ثم 23. **لا تراجع عن 21** (مستقلّة).

## 12. Production Readiness

**IMPLEMENTATION: NO-GO** — للسببين المباشرين:
- **Production schema = UNKNOWN**
- **Production migration history = UNKNOWN**

**جاهز تصميميًا:** بنية `schema_migrations` · خوارزمية التسوية · سياسة checksum · نموذج
الفشل · فحوص ما قبل التطبيق للمهاجرات الثلاث. **ينقص فقط بيانات الواقع.**

### فحوص ما قبل التطبيق (قراءة فقط — لم تُنفَّذ)
- **21:** العمود جديد ⇒ كل الصفوف القائمة `NULL`، وMariaDB تسمح بـNULL متعدّد ⇒ **لا يمكن
  أن تخرق البيانات القائمة القيد**. الخطر الوحيد = تعريف سابق مغاير ⇒ **NON-BLOCKING**
  ما لم يظهر CONFLICT.
- **22:** 🔴 **BLOCKING** — `GROUP BY member_id, attendance_date HAVING COUNT(*)>1` يجب أن
  يعود بصفر صفوف. إن > 0: **FAIL CLOSED** — ❌ لا حذف آلي · ❌ لا دمج آلي · ❌ لا اختيار آلي
  للسجلّ «الصحيح» · ❌ لا تنظيف مُدمِّر ⇒ **قرار أعمال بشري**.
- **23:** فحص بـ(الجدول، العمود) لا بالاسم — لكشف فهرس مكافئ باسم آخر أو مركّب يُغني عنه.
  الستة: `idx_att_date`(daily_attendance) · `idx_ms_end_date`/`idx_ms_pay_status`/
  `idx_ms_renew_status`(memberships) · `idx_members_status`(members) ·
  `idx_fu_next_date`(followups).

## 13. Orphan Login SQL Files — P1 Latent Security Risk

| الملف | التصنيف |
|---|---|
| `dashboard/setup_logins.sql` | **ORPHAN / MANUAL / OBSOLETE** |
| `dashboard/setup_member_logins.sql` | **ORPHAN / MANUAL / OBSOLETE** |

**الأدلّة:**
- **خارج مسار المهاجرات القانوني** (`sql/`)، و**غير موصولين** بـ`deploy.sh` ولا `run_all.sql`.
- يُشغَّلان يدويًا (`sudo mysql xcamp_gym < setup_logins.sql`).
- يحتويان/يضبطان **كلمات مرور ديمو معروفة** (مثل `admin123`).
- **أصبحا زائدين بعد PR #38** الذي وضع bcrypt حقيقيًا في `06_seed_data.sql`
  و`20_seed_demo_portal.sql`.

**الخطر:** تشغيلهما على الإنتاج **يضبط كلمات مرور ديمو معروفة لحسابات حقيقية**.

**التصنيف: P1 — خطر أمني كامن.**

> 🛑 **لم يُحذفا ولم يُعدَّلا في هذه المرحلة** (خارج نطاقها).
> **التوصية: معالجتهما في تغيير أمني منفصل** (حذف أو حارس CLI + إزالة كلمات المرور).

---

## Audit Traceability

```
Source:
  XCAMP_DEPLOYMENT_DESTRUCTIVE_AUDIT.md
Related:
  XCAMP_PRODUCTION_READINESS_AUDIT.md
Related:
  XCAMP_P0_DEPLOYMENT_REMEDIATION_ARCHITECTURE.md
Status:
  DESIGN COMPLETE / IMPLEMENTATION NO-GO
Database:
  NOT TOUCHED
SQL EXECUTION:
  NONE
Production:
  NOT ACCESSED
```
