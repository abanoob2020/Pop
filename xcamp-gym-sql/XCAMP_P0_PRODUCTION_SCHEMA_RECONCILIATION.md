# XCAMP P0 — PRODUCTION SCHEMA RECONCILIATION (PHASE 6)

**DISCOVERY ONLY — ZERO DATABASE CHANGES.** لم يُنفَّذ أي SQL، ولم يُتَّصل بأي قاعدة بيانات
في هذه المرحلة، ولم تُقرأ أي بيانات تطبيق (أعضاء/مدفوعات). لا اعتمادات ولا أسرار في هذا التقرير.

**الحالة المحلية عند التنفيذ:** الفرع `fix/p0-xcamp-hardening` · HEAD `26ab983` == upstream ·
شجرة نظيفة.

---

## 1. Environment Verification

**النتيجة: BLOCKED — لم يُمكن إثبات هوية قاعدة إنتاج.**

بحسب بوّابة المرحلة (PHASE 2/3): لم يُعثر على أي آلية اتصال إنتاجية مشروعة، لذا **تَوقَّف
الفحص قبل أي محاولة اتصال**. لم تُخترَع اعتمادات ولم تُجرَّب أي محاولة دخول.

| الفحص | النتيجة |
|---|---|
| ملف `.env` / `.env.production` | **غير موجود** |
| ملف إعداد (`config.php`, `database.yml`, `docker-compose.yml`, `.my.cnf`) | **غير موجود** |
| `DATABASE_URL` / `PROD_DB` / `MYSQL_HOST` | **غير معرَّف في المستودع** |
| مضيف/نطاق إنتاجي مذكور | **لا يوجد** |
| متغيّرات البيئة في هذه الجلسة | `DB_HOST` و`DB_NAME` **غير مضبوطة** |
| المضيف الافتراضي في الكود | `127.0.0.1` (loopback) |

**استنتاج:** لا مسار وصول إلى قاعدة إنتاج من هذه البيئة. أي قاعدة يمكن بلوغها محليًا هي
**ساندبوكس** (سبق إثباتها في تقارير سابقة: loopback · بيانات ديمو · أُعيد بناؤها مرارًا)،
و**لا يجوز اعتبارها إنتاجًا** ولا اشتقاق أي استنتاج إنتاجي منها.

## 2. Production Access Method

**PRODUCTION READ-ONLY ACCESS: NOT AVAILABLE.**

الاتصال في التطبيق يُشتقّ حصريًا من متغيّرات بيئة (`DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, `DB_PASS`) بلا أي قيم مخزّنة في المستودع — وهذا **سلوك أمني صحيح** (لا أسرار في
الشيفرة، fail-closed عند غياب `DB_PASS`)، لكنه يعني أن **الوصول للإنتاج يتطلّب اعتمادات
يوفّرها المشغّل خارج هذه البيئة**.

> لم يُطبع أي سرّ أو اعتماد أو سلسلة اتصال في هذا التقرير.

## 3. Production Schema Inventory

**UNKNOWN — لم يُفحَص.**

لم تُجمع أي بيانات وصفية (جداول/أعمدة/فهارس/قيود/triggers/procedures/events/views) من
الإنتاج، لأن بوّابة إثبات الهوية لم تُجتَز. **لا تخمين ولا استنتاج بديل.**

## 4. Repository Schema Inventory (تحليل ساكن — مسموح)

الحالة **المتوقّعة** من المستودع عند HEAD `26ab983` (لا تُمثّل الإنتاج):

- **48 جدولًا** = 20 (`01_tables.sql`) + 28 (`08…19`).
- **المنطق:** 11 procedure (`02`) · 8 triggers (`03`) · 1 event (`04`) · 13 views (`05`).
- **مهاجرات P0:**
  - `21_payment_idempotency.sql` → `payments.idempotency_key VARCHAR(64) NULL`
    + `UNIQUE uq_payments_idem (idempotency_key)`.
  - `22_attendance_unique.sql` → `UNIQUE uq_attendance_member_day (member_id, attendance_date)`.
  - `23_perf_indexes.sql` → 6 فهارس (مفصّلة في §8).
- **بذور/اختبار (خارج المخطط القانوني):** `06_seed_data` · `20_seed_demo_portal` · `07_test_queries`.
- **يتيمان خارج `sql/`:** `dashboard/setup_logins.sql` · `dashboard/setup_member_logins.sql`
  (غير موصولين بأي مشغّل — خطر P1 موثّق سابقًا).

## 5. Object-Level Diff

| Object | Repository | Production | Result |
|---|---|---|---|
| 20 جدولًا أساسيًا (`01`) | معرَّفة | **غير مفحوصة** | **UNKNOWN** |
| 28 جدول ميزات (`08…19`) | معرَّفة | **غير مفحوصة** | **UNKNOWN** |
| الأعمدة / الأنواع / NULL / الافتراضات | معرَّفة | **غير مفحوصة** | **UNKNOWN** |
| المفاتيح الأساسية (PK) | معرَّفة | **غير مفحوصة** | **UNKNOWN** |
| المفاتيح الخارجية (59 FK) | معرَّفة | **غير مفحوصة** | **UNKNOWN** |
| الفهارس | معرَّفة | **غير مفحوصة** | **UNKNOWN** |
| القيود الفريدة | معرَّفة | **غير مفحوصة** | **UNKNOWN** |
| 8 triggers | معرَّفة | **غير مفحوصة** | **UNKNOWN** |
| 11 procedure | معرَّفة | **غير مفحوصة** | **UNKNOWN** |
| 1 event | معرَّف | **غير مفحوص** | **UNKNOWN** |
| 13 views | معرَّفة | **غير مفحوصة** | **UNKNOWN** |

**لا صفّ واحد يمكن تصنيفه `MATCH` أو `MISSING_IN_PRODUCTION` أو `DEFINITION_DRIFT`** — لأن
الطرف الإنتاجي غير مرصود بالكامل.

## 6. Migration 21 State — `21_payment_idempotency.sql`

كائنان مستقلّان يجب تقييمهما **منفصلين**:

| الكائن | التعريف المتوقّع | حالة الإنتاج |
|---|---|---|
| `payments.idempotency_key` | `VARCHAR(64) NULL` بعد `reference_no` | **UNKNOWN** |
| `uq_payments_idem` | `UNIQUE (idempotency_key)` | **UNKNOWN** |

**التصنيف: UNKNOWN.**
ما يلزم لاحقًا لحسمه (قراءة بيانات وصفية فقط): وجود العمود **وتعريفه** (نوع/nullability)،
ووجود قيد فريد على العمود **بالاسم أو بأعمدة مكافئة تحت اسم آخر** (الأخير = `CONFLICT`
لأن إضافة القيد تُنشئ فهرسًا مكرّرًا).
ملاحظة تصميمية مسنودة: العمود جديد ⇒ كل الصفوف القائمة `NULL`، وMariaDB تسمح بـNULL متعدّد
⇒ **البيانات القائمة لا يمكن أن تخرق القيد** (فحص أهلية 21 غير حاجب).

## 7. Migration 22 State — `22_attendance_unique.sql`

| الكائن | التعريف المتوقّع | حالة الإنتاج |
|---|---|---|
| `uq_attendance_member_day` | `UNIQUE (member_id, attendance_date)` | **UNKNOWN** |

**التصنيف: UNKNOWN.** لم يُنشأ القيد ولم يُلمس شيء.

**منهجية الحسم لاحقًا — بيانات وصفية أولًا:**
1. فحص `information_schema.statistics` لوجود قيد فريد على `(member_id, attendance_date)`
   — **بيانات وصفية بحتة، لا تمسّ بيانات الأعضاء**.
2. **خاصية بنيوية حاسمة:** إن وُجد القيد فعليًا ⇒ **يستحيل** وجود تكرارات (القاعدة تفرضه)
   ⇒ **لا حاجة إطلاقًا لاستعلام بيانات الحضور**.
3. فقط إن **غاب** القيد يلزم فحص تجميعي للتكرارات
   (`GROUP BY member_id, attendance_date HAVING COUNT(*)>1`) — وهو **تجميعي**، يعيد
   مُعرّفات وتواريخ لا سجلّات كاملة، ويبقى **حاجبًا** قبل أي تطبيق.
4. إن ظهرت تكرارات: **FAIL CLOSED** — ❌ لا حذف · ❌ لا دمج · ❌ لا اختيار آلي للسجلّ
   «الصحيح» ⇒ **قرار أعمال بشري**.

## 8. Migration 23 State — `23_perf_indexes.sql`

| # | Index | Table | Columns | Order | Existence | Equivalent existing index |
|---|---|---|---|---|---|---|
| 1 | `idx_att_date` | `daily_attendance` | `attendance_date` | ASC | **UNKNOWN** | **UNKNOWN** |
| 2 | `idx_ms_end_date` | `memberships` | `end_date` | ASC | **UNKNOWN** | **UNKNOWN** |
| 3 | `idx_ms_pay_status` | `memberships` | `payment_status` | ASC | **UNKNOWN** | **UNKNOWN** |
| 4 | `idx_ms_renew_status` | `memberships` | `renewal_status` | ASC | **UNKNOWN** | **UNKNOWN** |
| 5 | `idx_members_status` | `members` | `status` | ASC | **UNKNOWN** | **UNKNOWN** |
| 6 | `idx_fu_next_date` | `followups` | `next_followup_date` | ASC | **UNKNOWN** | **UNKNOWN** |

**التصنيف الإجمالي: UNKNOWN** (لا يمكن قول `6/6` ولا `0/6`).
**قاعدة الحسم لاحقًا:** المقارنة بـ**(الجدول، العمود)** لا بالاسم وحده — لكشف فهرس مكافئ
باسم مختلف، أو فهرس مركّب يبدأ بنفس العمود فيُغني عنه (إضافته حينها = تكرار وهدر).
سِتّة كائنات مستقلّة ⇒ **حالات جزئية متعدّدة ممكنة** (4/6، 5/6…).

## 9. Migration History Evidence

**لا يوجد أي دليل على تاريخ نشر إنتاجي.**

| مصدر الدليل | النتيجة |
|---|---|
| جدول تتبّع (`schema_migrations` أو ما يعادله) | **غير موجود في المستودع** |
| سجلّات نشر (`logs/`) | مُستثناة من git · لا سجلّ إنتاجي متاح |
| CI/CD | **لا يوجد** (لا `.github/`، لا `Jenkinsfile`) |
| سجلّات إصدارات / release notes | **لا توجد** |
| بيانات وصفية للنسخ الاحتياطية | **غير متاحة** |
| تاريخ المستودع | يُظهر **إنشاء** المهاجرات، لا **تطبيقها** |

**التصنيف: UNKNOWN لكل المهاجرات.**

> **قاعدة صارمة مُطبَّقة:** وجود ملف مهاجرة في المستودع **ليس دليلًا** على تطبيقه على
> الإنتاج. لم تُرفَّع أي حالة من `UNKNOWN` إلى `LIKELY` أو `CONFIRMED`.
> ملاحظة سياقية: `21/22/23` أُنشئت على فرع **غير مدموج** (`fix/p0-xcamp-hardening`)، وهو ما
> يجعل تطبيقها على الإنتاج **غير مرجّح** — لكن هذا **استدلال ظرفي لا دليل**، فتبقى `UNKNOWN`.

## 10. Schema Drift

**UNKNOWN — لا يمكن قياس الانحراف بلا رصد الطرف الإنتاجي.**

لم تُصنَّف أي فئة (جدول/عمود/نوع/nullability/افتراض/فهرس/قيد/trigger/procedure/view)
لأن ذلك يتطلّب مقارنة فعلية. **لم يُصلَح شيء ولم يُقترح إصلاح.**

## 11. Baseline Decision

# BASELINE: BLOCKED — INSUFFICIENT EVIDENCE

**المبرّر:** لم تُجتَز بوّابة إثبات هوية الإنتاج، فلم يُرصد أي كائن إنتاجي. اختيار
`RECONCILED` هنا سيكون **افتراضًا لا دليلًا** — وهو بالضبط ما تمنعه هذه المرحلة.

## 12. Migration Tracking Decision

# MIGRATION TRACKING: NO-GO

حالة الإنتاج ما زالت غامضة بالكامل. **بناء `schema_migrations` الآن سيؤدّي حتمًا إلى ملئه
بحالات مفترضة**، فيُعلَّم ما لم يُطبَّق كأنه مُطبَّق ⇒ يُتخطّى إلى الأبد.
**هذا أسوأ من غياب التتبّع أصلًا.** التصميم جاهز (موثّق في
`XCAMP_P0_PRODUCTION_BASELINE_RECONCILIATION.md`) لكنه **لا يُنفَّذ**.

## 13. Remaining P0 Blockers

| # | المانع | الحالة |
|---|---|---|
| **B1** | لا وصول قراءة-فقط لقاعدة الإنتاج | **مفتوح — حاجب لكل ما يليه** |
| **B2** | هوية/مخطط الإنتاج غير مُثبتين | **مفتوح** |
| **B3** | تاريخ المهاجرات مجهول (لا تتبّع ولا سجلّات) | **مفتوح** |
| **B4** | تكرارات الحضور في الإنتاج مجهولة (حاجب قبل `22`) | **مفتوح** |
| **B5** | قابلية الاستعادة غير مُتحقَّقة على بيانات شبيهة بالإنتاج | **مفتوح** |
| **B6** | `deploy.sh` مُدمِّر ولا مشغّل مهاجرات معتمد | **مفتوح (مُجمَّد بالتوثيق)** |
| **B7** | ملفّان يتيمان يضبطان كلمات مرور ديمو | **مفتوح — P1 أمني منفصل** |

## 14. Recommended Next Step

**خطوة واحدة فقط، ومسؤوليتها على المشغّل لا على هذه البيئة:**

> توفير **وصول قراءة-فقط مؤقّت** إلى قاعدة الإنتاج (حساب بصلاحية `SELECT` على
> `information_schema` فقط إن أمكن)، ثم تشغيل **استعلامات بيانات وصفية** لتحديد:
> (1) وجود `payments.idempotency_key` وتعريفه · (2) وجود قيد فريد على
> `(member_id, attendance_date)` · (3) أيّ من الفهارس الستة موجود وبأي أعمدة ·
> (4) جرد الجداول/الأعمدة للمقارنة بخط الأساس.
>
> **بدون هذه البيانات لا يمكن — ولا يجوز — إنشاء أي خط أساس أو تتبّع مهاجرات.**

الفحص المطلوب **بيانات وصفية بحتة**، لا يمسّ سجلّات الأعضاء/المدفوعات، ولا يتطلّب أي كتابة.

---

## Audit Traceability

```
Phase:        6 — PRODUCTION READ-ONLY SCHEMA RECONCILIATION
Repo HEAD:    26ab983 (== upstream, worktree clean)
Related:      XCAMP_P0_PRODUCTION_BASELINE_RECONCILIATION.md
Related:      XCAMP_DEPLOYMENT_DESTRUCTIVE_AUDIT.md
Related:      XCAMP_PRODUCTION_READINESS_AUDIT.md
Related:      XCAMP_P0_DEPLOYMENT_REMEDIATION_ARCHITECTURE.md
Database:     NOT TOUCHED · NOT CONNECTED
SQL EXECUTED: NONE
Production:   NOT ACCESSED (identity gate not passed)
Credentials:  NONE USED · NONE INVENTED · NONE PRINTED
App data:     NOT READ
Status:       BASELINE BLOCKED / MIGRATION TRACKING NO-GO
```
