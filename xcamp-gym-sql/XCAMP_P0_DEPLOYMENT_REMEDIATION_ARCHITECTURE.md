# XCAMP P0 DEPLOYMENT REMEDIATION ARCHITECTURE

**PHASE 4 — DESIGN ONLY.** لا تنفيذ، لا تعديل كود/SQL، لا مهاجرات، لا commit/push/merge.
هذه وثيقة معمارية لإصلاح مسار النشر المُدمِّر المُثبَت في التدقيق الجنائي السابق.

---

## 1. Current Architecture

```
update.sh ──┬── (افتراضي) سحب كود + تشغيل سيرفر            [آمن]
            ├── --deploy ──► deploy.sh                      [🔴 مُدمِّر]
            └── --reset  ──► reset_and_deploy.sh ─► DROP DATABASE ─► deploy.sh  [🔴🔴]

deploy.sh  ──► 00_init (FK_CHECKS=0) ─► 01_tables (20×DROP+CREATE)
             ─► 02/03/04/05 (DROP+CREATE للمنطق) ─► [DB_SEED] 06_seed
             ─► 08…19 (إضافية) ─► 21/22/23 (إضافية) ─► [DB_SEED] 20, 07

run_all.sql ──► نفس التسلسل داخل جلسة mysql واحدة (منفذ بديل، نفس الخطر)
```

| الوظيفة | الحالة |
|---|---|
| bootstrap | ✅ موجود (داخل `deploy.sh`) |
| deployment | ⚠️ **نفس مسار البوتستراب** |
| migration | ❌ **لا مسار مستقل** |
| seed | ✅ مُقيَّد بـ`DB_SEED` |
| reset | ✅ `reset_and_deploy.sh` (تطوير) |
| update | ⚠️ `update.sh --deploy` ⇒ مُدمِّر |
| rollback | ❌ **غير موجود** |
| verification | ❌ غير موجود (سوى `07_test_queries` تحت SEED) |

## 2. Confirmed P0 Root Cause

ليست وجود `DROP` — بل **ازدواج الغرض بلا فاصل**:

1. ملف واحد (`deploy.sh`) يخدم التثبيت الجديد **والتحديث**.
2. **المهاجرات الإضافية (08–23) مُوصَّلة داخله** ⇒ لا سبيل «موثَّق» لتطبيقها إلا عبره.
3. **صفر حوارس** (لا كشف بيانات · لا تأكيد · لا نسخة · لا وضع migration-only).
4. `update.sh --deploy` يغلّفه بوصف حميد («إعادة نشر السكيمة والبيانات»).
5. `FOREIGN_KEY_CHECKS=0` يحوّل الفشل من «مسح» إلى **تلف مرجعي**.

## 3. Canonical Schema Baseline — اكتشاف بنيوي

**`01_tables.sql` هو الأساس المرجعي للجداول العشرين الأصلية فقط.** لكن مخطط التثبيت
الكامل = `00+01+02+03+04+05` **+ 08…19 + 21/22/23** (تضيف ~28 جدولًا + قيود P0).

⇒ **لا يوجد ملف واحد يمثّل المخطط الكامل**، وملفات 08–23 **مزدوجة الدور**: جزء من التثبيت
الجديد **و**مهاجرات للقاعدة القائمة. هذا جذر الغموض المعماري ويجب حسمه في التصميم الجديد.

**ما تضيفه 21/22/23 فوق الأساس:** عمود `payments.idempotency_key` + `UNIQUE`؛
`UNIQUE(member_id, attendance_date)`؛ 6 فهارس أداء. **لا تعديل ولا حذف لأي كائن قائم.**

## 4. Migration Inventory

| Migration | الغرض | العملية | مُدمِّر؟ | Idempotent؟ | آمن للإنتاج؟ | التبعية | التصنيف |
|---|---|---|:---:|:---:|:---:|---|:---:|
| 08–19 | جداول ميزات | `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE` | لا | ✅ | ✅ | 01 | **SAFE** |
| 20_seed_demo_portal | بذرة ديمو | `INSERT IGNORE` | لا | ✅ | ⚠️ **ديمو فقط** | 10 | **CONDITIONAL** |
| 21_payment_idempotency | عمود+UNIQUE | `ALTER … ADD … IF NOT EXISTS` | لا | ✅ | ✅ | payments | **SAFE** |
| 22_attendance_unique | UNIQUE | `ALTER … ADD UNIQUE IF NOT EXISTS` | لا | ✅ | ⚠️ **يفشل عند تكرارات** | daily_attendance | **CONDITIONAL** |
| 23_perf_indexes | 6 فهارس | `CREATE INDEX IF NOT EXISTS` | لا | ✅ | ✅ (زمن ALTER) | الجداول الأربعة | **SAFE** |
| 01–05 | الأساس/المنطق | `DROP` + `CREATE` | 🔴 **نعم** | ✅ تقنيًا | 🔴 **لا** | — | **UNSAFE** (بوتستراب فقط) |
| 06_seed_data | بذرة | `INSERT` بمعرّفات ثابتة | لا | ❌ | 🔴 لا | 01 | **UNSAFE** إنتاجًا |

**ملاحظة توافق:** `ADD COLUMN/KEY IF NOT EXISTS` و`CREATE INDEX IF NOT EXISTS` امتدادات
**MariaDB**؛ لو تغيّر المحرّك إلى MySQL 8 ستفشل ⇒ يجب تثبيت المحرّك أو استبدالها بفحص
`information_schema`.

## 5. Migration Tracking Gap

| السؤال | الجواب |
|---|---|
| تتبّع مهاجرات موجود؟ | ❌ **NO** (لا `schema_migrations`؛ ذكر واحد كأمنية في `FUTURE_ARCHITECTURE.md`) |
| خط الأساس الحالي معروف؟ | **PARTIAL** — معروف من الملفات، **غير معروف** لأي قاعدة فعلية |
| يمكن معرفة ما طُبِّق؟ | ❌ **NO** — فقط بالاستنتاج من وجود الكائنات |
| إعادة تشغيل مهاجرة آمنة؟ | **PARTIAL** — 08–23 نعم؛ 01–06 **لا** |

## 6. Production State Reconciliation Plan (قراءة فقط)

**ما يجب فحصه:**
1. **وجود القاعدة والجداول:** `information_schema.tables` — الجداول العشرون + كم من 08–19.
2. **حالة 21:** وجود `payments.idempotency_key` (`columns`) + `uq_payments_idem` (`statistics`).
3. **حالة 22:** وجود `uq_attendance_member_day`.
4. **حالة 23:** وجود الفهارس الستة بالاسم.
5. **الأهلية:** تكرارات `(member_id, attendance_date)` = 0 قبل 22.
6. **بصمة الهوية:** اسم القاعدة + وجود بيانات حقيقية (`COUNT(*)` على `members/payments`).

**قاعدة الحسم:** الكائن موجود ⇒ المهاجرة **مُطبَّقة**؛ غائب ⇒ **معلّقة**.
**حالة مختلطة/غامضة ⇒ FAIL CLOSED** ولا يُنفَّذ شيء حتى تُسوَّى يدويًا وتُسجَّل.

## 7. Recommended Architecture

| Option | Safety | Complexity | Dev UX | Prod Risk | التوصية |
|---|:---:|:---:|:---:|:---:|---|
| **A** `bootstrap.sh` + `migrate.sh` | **عالية** | منخفضة | واضح | **منخفض** | ✅ **مُختارة** |
| B `deploy.sh` بوضعين فرعيين | متوسطة | متوسطة | ملتبس | متوسط | ❌ يُبقي الاسم الملوَّث |
| C `install.sh` + `migrate.sh` | عالية | منخفضة | جيّد | منخفض | مكافئ لـA (تسمية) |
| D أداة migrations خارجية (Phinx) | عالية جدًا | **مرتفعة** | يتطلب Composer | منخفض | مؤجّل (يخالف «بلا تبعيات») |

**المختار: Option A** — فصل صريح بالاسم، بلا تبعيات جديدة، وأقلّ التباس تشغيلي.

## 8. Bootstrap Strategy

`bootstrap.sh` = تثبيت جديد فقط: `00→01→02→03→04→05` + **08…19 + 21/22/23** + بذرة اختيارية.
**شرط وجودي:** يرفض التنفيذ إن كانت القاعدة **غير فارغة** (§12) إلا بـ`--force-rebuild`
صريح + تأكيد باسم القاعدة. الملفات المُدمِّرة (01–05) **حصريًا** هنا.

## 9. Production Migration Strategy

`migrate.sh` = **إضافي فقط**: يكتشف المهاجرات المعلّقة (08…19, 21, 22, 23، وما بعدها)،
يرتّبها رقميًا، يفحص الأهلية، يطبّق، يسجّل. **لا يلمس 00–06 إطلاقًا** (قائمة سوداء صلبة).

## 10. deploy.sh Future Role — **REPLACE WITH SAFE WRAPPER**

```
./deploy.sh              → خطأ + شرح: اختر --bootstrap أو --migrate
./deploy.sh --bootstrap  → bootstrap.sh (بحوارسه)
./deploy.sh --migrate    → migrate.sh
```
**المبرّر:** يحافظ على نقطة الدخول المعروفة، ويحوّل المسار الافتراضي من «مُدمِّر صامت»
إلى «فشل مُعلَّم». (بوتستراب مُدمِّر مقبول **فقط** مع منع قويّ للتنفيذ الإنتاجي.)

## 11. update.sh Future Role

- `--deploy` ⇒ **يُعاد توجيهه إلى `migrate.sh`** (وهو ما يتوقّعه المستخدم من «تحديث»)، ويُفضَّل
  تسميته `--migrate` مع إبقاء `--deploy` كمرادف يطبع تحذيرًا.
- `--reset` ⇒ **تطوير فقط**: حظر إن كانت القاعدة مأهولة · تأكيد بكتابة اسم القاعدة · فحص
  هوية القاعدة · **ليس مسارًا افتراضيًا أبدًا** · بانر تحذيري.

## 12. Production Guards (defense-in-depth)

| # | الحارس | التصنيف |
|---|---|:---:|
| 1 | فحص البيئة (`APP_ENV`/متغيّر صريح) | **MANDATORY** |
| 2 | فحص هوية القاعدة (اسم/مضيف متوقّع) | **MANDATORY** |
| 3 | تأكيد صريح للعمليات المُدمِّرة | **MANDATORY** |
| 4 | **منع البوتستراب على قاعدة مأهولة** | **MANDATORY** |
| 5 | التحقّق من نسخة احتياطية (وجود + `gzip -t` + حداثة) | **MANDATORY** |
| 6 | مسار إنتاج migration-only | **MANDATORY** |
| 7 | التحقّق من سجلّ المهاجرات | **MANDATORY** |
| 8 | checksum للمهاجرات | RECOMMENDED |
| 9 | تحقّق ما بعد النشر | **MANDATORY** |

**Empty-DB detection — الاستراتيجية الأسلم = مركّبة، لا «وجود جدول» وحده:**
> فارغة ⇔ (لا `schema_migrations`) **و** (لا أيّ من الجداول العشرين الأساسية) **و**
> (`COUNT(*)`=0 في `members`/`payments`/`users` إن وُجدت). أي **حالة بينية ⇒ FAIL CLOSED**.

## 13. Partial Database Scenario

قاعدة موجودة + بعض الجداول + بعض المهاجرات + سجلّ مجهول ⇒ **FAIL CLOSED إلزاميًا**.
الإجراء: طباعة تقرير تسوية (ما وُجد/ما نقص/ما يبدو مُطبَّقًا)، ثم **إيقاف**. لا بوتستراب،
ولا migrate، حتى يُنشأ خطّ أساس يدوي في `schema_migrations` بعد مراجعة بشرية.

## 14. Rollback / Recovery Strategy

**تمييز إلزامي:** DDL في MariaDB **غير معاملاتي** ⇒ **لا يوجد transaction rollback** للمهاجرات.

| نوع التغيير | الاستراتيجية |
|---|---|
| إضافة فهرس (23) | **ROLLBACK** — `DROP INDEX` رخيص وآمن |
| إضافة قيد UNIQUE (22) | **ROLLBACK** — `DROP INDEX` |
| إضافة عمود nullable (21) | **ROLLBACK** — `DROP COLUMN` (يفقد قيم العمود الجديد فقط) |
| تحويل بيانات (مستقبلًا) | **RESTORE** أو **FORWARD FIX** — لا rollback |
| فشل 22 بعد نجاح 21 | **FORWARD FIX**: نظّف التكرارات ثم أعد 22 (idempotent) |
| فشل كارثي/تلف | **RESTORE** من نسخة مُثبتة الاستعادة |

## 15. Migration Table Design (مفهومي)

`migration_id` (اسم الملف، **PK/UNIQUE**) · `migration_name` · `checksum` (SHA-256) ·
`applied_at` · `execution_time_ms` · `status ENUM('success','failed','partial')` · `applied_by`.

- **checksum ضروري** — يكشف تعديل ملف مهاجرة بعد تطبيقه (انحراف صامت بين البيئات).
- **يجب تسجيل الفشل والجزئي** لا النجاح فقط: صفّ `running` يُكتب **قبل** التنفيذ ثم يُحدَّث
  ⇒ الانقطاع يترك `partial` فيمنع التشغيل التالي (fail closed) بدل «إعادة محاولة عمياء».

## 16. PR #41 Recommendation → **AMEND**

1. **هل يعتمد على الآلية غير الآمنة؟** الكود **لا**؛ لكن **توصيل 21/22/23 في `deploy.sh`**
   يجعل تطبيقها مرتبطًا بها.
2. **هل صياغته توحي بالتنفيذ عبر `deploy.sh`؟** **نعم ضمنًا** ⇒ خطِر.
3. **القرار: AMEND** — لا BLOCK (الكود سليم و37/37 خضراء ولا انحدار)، ولا SPLIT.
4. **ما يجب تغييره بالضبط:**
   - **حظر صريح:** «🚫 لا تشغّل `deploy.sh` / `reset_and_deploy.sh` / `update.sh --deploy|--reset` على الإنتاج».
   - **إضافة الأمر التفاضلي:** `mysql … < sql/21|22|23` بالترتيب.
   - **الإشارة** إلى مسار migration-only قيد التصميم (هذه الوثيقة).

## 17. Implementation Sequence (تصميم فقط)

| Step | العمل | الخطر | التبعية | التراجع | التحقّق |
|---|---|:---:|---|---|---|
| **0** | **تجميد المسار الخطِر**: تعديل وصف PR #41 + تحذير توثيقي | LOW | — | استرجاع النص | مراجعة بشرية |
| 1 | تثبيت خط الأساس (توثيق المخطط الكامل) | LOW | — | — | مطابقة ملفات↔`information_schema` |
| 2 | تقديم `schema_migrations` (مهاجرة إضافية) | LOW | 1 | `DROP TABLE` | وجود الجدول |
| 3 | `migrate.sh` (اكتشاف/ترتيب/تسجيل/فشل مُغلق) | MED | 2 | حذف الملف | اختبارات A–J |
| 4 | فصل `bootstrap.sh` + الحوارس | MED | 3 | حذف الملف | قاعدة فارغة/مأهولة |
| 5 | تقوية `update.sh` | MED | 3,4 | استرجاع | اختبار كل خيار |
| 6 | حوارس الإنتاج 1–7 | MED | 4,5 | تعطيل الحارس | محاولة تشغيل مرفوضة |
| 7 | تحقّق ما بعد النشر + `deploy.sh` كغلاف | LOW | 6 | استرجاع | تشغيل بلا وضع ⇒ فشل |
| 8 | تحديث `README` + وصف PR | LOW | 7 | — | مراجعة |
| 9 | اختبار على قاعدة قابلة للإتلاف | LOW | 3–8 | إعادة إنشاء | A–J تمرّ |
| 10 | طرح إنتاجي (نسخة+preflight+migrate+تحقّق) | **HIGH** | 9 | RESTORE | البوّابات 0–8 |

## 18. Test Strategy (تصميم — لم يُنفَّذ)

**A** قاعدة فارغة ⇒ bootstrap ينجح · **B** قاعدة مأهولة ⇒ bootstrap **يُرفض**، migrate ينجح ·
**C** قاعدة جزئية ⇒ **FAIL CLOSED** · **D** مهاجرة مُطبَّقة ⇒ تُتخطّى (no-op) ·
**E** فشل جزئي ⇒ `partial` مسجّل ويمنع التشغيل التالي · **F** قاعدة إنتاج غير متوقّعة ⇒ يُرفض ·
**G** اعتماد خاطئ ⇒ فشل مبكر واضح · **H** لا نسخة ⇒ يُرفض · **I** checksum تغيّر ⇒ يُرفض ·
**J** ترتيب خاطئ ⇒ يُرفض.

## 19. GO/NO-GO Gates — دليل القبول

**G0** اعتماد هذه الوثيقة · **G1** تقرير تسوية يطابق الملفات بالمخطط الفعلي · **G2** اختبارات
A–J خضراء · **G3** `bootstrap.sh` يفشل على قاعدة مأهولة (لقطة إثبات) · **G4** محاولة تشغيل
مُدمِّر على «إنتاج» تُرفض · **G5** نجاح على قاعدة قابلة للإتلاف بلا فقد · **G6** نجاح على
staging بنسخة إنتاج · **G7** `gzip -t` + استعادة مُثبتة + نسخ `uploads/` · **G8** موافقة بشرية.

## 20. Final Architecture Diagram

```
                      XCAMP DATABASE LIFECYCLE
   ┌──────────────────────────┐        ┌──────────────────────────┐
   │      FRESH INSTALL       │        │    EXISTING DATABASE     │
   │   (empty DB — verified)  │        │  (production / staging)  │
   └────────────┬─────────────┘        └────────────┬─────────────┘
                ↓                                    ↓
      bootstrap.sh  [Guards 1-4]            migrate.sh  [Guards 1,2,5,6,7,8]
                ↓                                    ↓
   00 → 01 → 02 → 03 → 04 → 05            read schema_migrations
                ↓                                    ↓
      08…19 + 21 + 22 + 23                  detect PENDING only
                ↓                                    ↓
        optional seed (DB_SEED)             preflight (dup checks)
                ↓                                    ↓
        record ALL in schema_migrations       21 → 22 → 23 → …
                ↓                                    ↓
                └──────────► verification ◄──────────┘
                              [Guard 9]

   deploy.sh  ⇒  غلاف يرفض العمل بلا --bootstrap | --migrate
   update.sh  ⇒  --deploy ► migrate.sh   |   --reset ► تطوير فقط (محروس)
   01–06      ⇒  قائمة سوداء صلبة داخل migrate.sh
```

---

## === PHASE 4 FINAL DECISION ===

| البند | القرار |
|---|---|
| CURRENT ARCHITECTURE | **UNSAFE** |
| PRODUCTION DEPLOYMENT | **NO-GO VIA deploy.sh** |
| RECOMMENDED PRODUCTION PATH | **MIGRATION-ONLY** |
| BOOTSTRAP | **FRESH DATABASE ONLY** |
| MIGRATION TRACKING | **REQUIRED** |
| PRODUCTION GUARD | **REQUIRED** |
| PR #41 | **AMEND** (الكود سليم؛ تعليمات النشر وحدها خطِرة) |
| IMPLEMENTATION | **NOT PERFORMED** |
| WORKTREE | **UNCHANGED** |
