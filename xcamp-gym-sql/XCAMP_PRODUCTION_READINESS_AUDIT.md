# XCAMP_PRODUCTION_READINESS_AUDIT.md

**READ-ONLY pre-flight audit.** لا تعديل/commit/push/merge/migration. المخرج تقرير فقط.
الوسوم: `FACT` (دليل مباشر) · `MEASURED` · `ESTIMATED` · `INFERRED` · `NOT VERIFIABLE`.

---

## 1. Executive Summary

كود P0 نفسه **سليم ومُختبَر** (37/37) والمهاجرات الثلاث **إضافية وآمنة بذاتها**. لكن
**عملية النشر ليست جاهزة للإنتاج**، وأخطر ما وجدته ليس في P0 بل في أداة النشر:

> 🔴 **`deploy.sh` مُدمِّر:** يشغّل `01_tables.sql` بلا شرط، وهو يبدأ بـ**20 `DROP TABLE`**
> (`users`, `members`, `memberships`, `payments`, `daily_attendance`, …). تشغيله على قاعدة
> إنتاج = **محو كامل للبيانات**. تعليمات النشر في PR #41 («Apply migrations in documented
> order») **مُضلِّلة** لأن الأداة الوحيدة في المستودع تفعل ذلك.

**Verdict: CONDITIONAL GO** — الدمج مقبول بشروط، والنشر الإنتاجي **محظور** حتى تُغلق 3 موانع.

## 2. Environment (FACT)

| البند | القيمة |
|---|---|
| Branch / HEAD | `fix/p0-xcamp-hardening` @ `7c4d4dc` (متطابق مع البعيد) |
| Remote | `github.com/abanoob2020/Pop` · PR **#41** (Draft → main) |
| Working tree | **نظيفة** (0 تعديلات، 0 غير مُتتبَّع) |
| PHP | 8.4.19 (CLI) |
| DB engine | MariaDB 10.11.14 |
| Config source | متغيّرات بيئة فقط · `.env` **غير موجود** (لا أسرار في المستودع) |
| Scripts | `backup.sh` `restore.sh` `deploy.sh` `reset_and_deploy.sh` |

**ENVIRONMENT = LOCAL SANDBOX — ليست إنتاجية (مُثبَت).** الأدلّة: ربط loopback فقط ·
حاوية مؤقّتة · `reset_and_deploy.sh` نُفِّذ مرارًا في هذه الجلسة (إسقاط/إعادة إنشاء) ·
بيانات تجريبية (`admin@xcamp.com`) · قاعدة وحيدة `xcamp_gym`.
⇒ **Production preflight لم يُنفَّذ** (القيد 13): لا قاعدة إنتاج يمكن الوصول إليها من هنا.
كل أرقام الصفوف أدناه **بيانات ديمو** ولا تُعمَّم على الإنتاج.

## 3. Current Schema (sandbox — بعد تطبيق 21/22/23)

**`payments`** — `payment_id` PK · `member_id` NOT NULL (FK→members, CASCADE) ·
`membership_id` NULL (FK→memberships, SET NULL) · `payment_date` DATETIME ·
`amount` **DECIMAL(10,2)** ✅ (لا FLOAT) · `method` ENUM(5) · `status`
ENUM(`pending,paid,failed,refunded,partial`) · `receipt_no`/`reference_no` NULL ·
**`idempotency_key` VARCHAR(64) NULL** · فهارس: PK, fk_member, fk_membership,
**`uq_payments_idem` UNIQUE**.

**`daily_attendance`** — `attendance_id` PK · `member_id` NOT NULL · `coach_id` NULL ·
`attendance_date` DATE · `check_in_time`/`check_out_time` TIME NULL · `attended` TINYINT ·
فهارس: PK, fk_coach, **`idx_att_date`**, **`uq_attendance_member_day` UNIQUE(member_id,attendance_date)**.

**`memberships`** — فهارس: PK, fk_member, fk_plan, `idx_ms_end_date`, `idx_ms_pay_status`,
`idx_ms_renew_status`. **`members`** — PK, UNIQUE(email), UNIQUE(phone), fk_coach, `idx_members_status`.

**أحجام (ديمو):** payments 8 · daily_attendance 9 · memberships 5 · members 5 · followups 4.

## 4. Migration Compatibility

| Migration | التوافق | ملاحظة |
|---|---|---|
| `21_payment_idempotency.sql` | ✅ **متوافق** | `ADD COLUMN IF NOT EXISTS` (nullable) + `ADD UNIQUE IF NOT EXISTS`. الصفوف القائمة تصبح `NULL`، و MariaDB تسمح بـNULL متعدّد ⇒ لا كسر. **قابل لإعادة التشغيل.** |
| `22_attendance_unique.sql` | ⚠️ **مشروط** | `ADD UNIQUE(member_id, attendance_date)` — **يفشل إن وُجد تكرار في الإنتاج**. الساندبوكس نظيف (0)، والإنتاج **UNKNOWN**. |
| `23_perf_indexes.sql` | ✅ متوافق | 6 `CREATE INDEX IF NOT EXISTS`. غير مُدمِّر؛ التكلفة زمن `ALTER` على جداول كبيرة. |

الثلاثة **لا تمسّ `01_tables.sql`** ولا تغيّر أعمدة قائمة ⇒ آمنة بذاتها.

## 5. Production Preflight Results

**NOT EXECUTED — لا وصول لقاعدة إنتاج (ENVIRONMENT = SANDBOX).** الاستعلامات الواجبة على
المشغّل (قراءة فقط):

```sql
-- (A) BLOCKING — يجب أن يعود بصفر صفوف قبل 22
SELECT member_id, attendance_date, COUNT(*) c FROM daily_attendance
GROUP BY member_id, attendance_date HAVING c > 1;

-- (B) حالة ما قبل الترحيل المتوقّعة: العمود غير موجود ⇒ الخطأ 1054 طبيعي هنا
SELECT COUNT(*) FROM payments WHERE idempotency_key IS NOT NULL;   -- EXPECTED PRE-MIGRATION: Unknown column
-- بعد 21 فقط: تحقّق من مفاتيح فارغة/مشوّهة
SELECT COUNT(*) FROM payments WHERE idempotency_key = '';

-- (C) تقدير زمن ALTER
SELECT table_name, table_rows, ROUND((data_length+index_length)/1024/1024,1) mb
FROM information_schema.tables WHERE table_schema=DATABASE()
  AND table_name IN ('payments','daily_attendance','memberships','members','followups');
```
نتيجة (A) بأي صفوف ⇒ **STOP**: تُدمج/تُنظَّف التكرارات أولًا (خارج نطاق P0).

## 6. Backup / Restore Assessment

**`backup.sh` (FACT):** `set -Eeuo pipefail` · فحص توفّر `mysqldump`/`gzip` ·
اعتماد عبر ملف مؤقّت `0600` (لا سرّ في `ps`) · `--single-transaction --routines --triggers
--events` · كتابة إلى `.partial` ثم `mv -f` **ذرّي** · التقاط stderr في `.last_error` ·
`exit 1` عند الفشل · تشذيب `BACKUP_KEEP` (14).
⇒ **لا يمكن أن يعلن نجاحًا بملف ناقص** (شرط الـpipeline + النقل الذرّي) ✅.

**الثغرات (FACT):**
- ❌ **لا ينسخ `dashboard/uploads/`** — صور تقدّم الأعضاء **خارج النسخة** ⇒ فقدها عند فقد الخادم.
- ❌ **لا تشفير · لا off-site** — النسخ محلية بجوار القاعدة (SPOF).
- ❌ **لا تحقّق سلامة** بعد النسخ (لا `gzip -t` ولا restore تجريبي).
- ⚠️ الجدولة عبر `backup.cron.example` — **تنصيبها الفعلي NOT VERIFIABLE**.

**`restore.sh` (FACT):** `set -Eeuo pipefail` · يتحقّق من وجود الملف · `--latest` ·
**يطلب كتابة اسم القاعدة للتأكيد** · فحص اتصال قبل التنفيذ · تقرير عدد الجداول بعده.
⚠️ **خطر الكتابة فوق الإنتاج:** `FORCE=1` يتخطّى التأكيد بالكامل، ولا يوجد أي حاجز يمنع
توجيهه إلى قاعدة إنتاج (`DB_NAME` من البيئة). لا يتطلّب قاعدة منفصلة.
⚠️ **RPO/RTO: Not defined.** · **قابلية الاستعادة على بيانات إنتاجية: NOT VERIFIED**
(دورة restore اختُبرت على ساندبوكس صغير فقط — وجود ملف ≠ قابلية استعادة).

## 7. Deployment Assessment

🔴 **`deploy.sh` مُدمِّر (FACT):** `FILES` تتضمّن `01_tables.sql` **بلا شرط**، وهو يحوي
**20 `DROP TABLE IF EXISTS`** (بينها `payments`, `members`, `memberships`,
`daily_attendance`, `users`). كذلك `02/03/04/05` تُسقط 11 procedure + 8 triggers +
1 event + 6 views. ⇒ **تشغيل `deploy.sh` على الإنتاج = فقد كامل للبيانات.**
`reset_and_deploy.sh` أسوأ (يُسقط القاعدة كلها) وهو مخصّص للتطوير.

**النتيجة:** المستودع **لا يملك أداة نشر تفاضلية (incremental)**. الطريقة الصحيحة لتطبيق
P0 على الإنتاج هي تشغيل **الملفات الثلاثة فقط** مباشرة:
```bash
mysql --defaults-extra-file=<cnf> xcamp_gym < sql/21_payment_idempotency.sql
mysql --defaults-extra-file=<cnf> xcamp_gym < sql/22_attendance_unique.sql
mysql --defaults-extra-file=<cnf> xcamp_gym < sql/23_perf_indexes.sql
```

**سلوك الفشل (FACT):** `set -Eeuo pipefail` ⇒ توقّف عند أول خطأ، ولا يُعلن نجاحًا كاذبًا
لأمر فاشل. **لكن:** DDL في MariaDB **غير معاملاتي** (implicit commit) ⇒ **الترحيل الجزئي
ممكن** (21 ينجح ثم 22 يفشل). التخفيف: الثلاثة **idempotent** (`IF NOT EXISTS`) فإعادة
التشغيل بعد إصلاح السبب آمنة. ⚠️ ملاحظة: مخرجات SQL تذهب إلى `logs/deploy.log` فقط، فالخطأ
لا يظهر على الشاشة — يجب مراجعة السجلّ.

## 8. Revenue Integrity

`SUM(payments.amount WHERE status='paid')` = **نقد محصَّل حقيقي — بشروط (FACT):**
- كاتب واحد فقط لـ`payments` (`finance.php`) وبـ`status='paid'` مثبَّتة؛ **لا كود يكتب**
  `refunded/failed/pending/partial` ⇒ لا تلوّث للمجموع اليوم.
- **POS مستبعَد** عمدًا (`pos_sales` منفصل) ⇒ «محصّل» revenue = **دفعات العضويات فقط**، لا كل نقد النادي.
- نطاق الكابتن يُبنى من الجلسة `(int)$myCoach` (لا من الطلب) ✅.

**Known limitations (موثّقة، غير مُصلَحة):**
- ❌ **لا refund/void/reversal flow** — استرداد خارج النظام ⇒ الإيراد يبالغ (`// TODO` مضاف).
- ⚠️ دفعة **جزئية** تُعلّم العضوية `paid` (قرار عمل).
- ⚠️ **المنطقة الزمنية** = توقيت الخادم (`NOW()/CURDATE()`)؛ حدود الشهر/اليوم تتبعه — لا معالجة مناطق.
- ⚠️ حذف عضوية ⇒ `payments.membership_id = NULL` ⇒ الصفّ يسقط من `JOIN` المحصّل (يُنقِص لا يُضخّم).

## 9. Payment Idempotency — تحليل منطقي كامل

| # | الحالة | السلوك | الحكم |
|---|---|---|---|
| 1 | مفتاح مفقود | 400 قبل أي إدراج | ✅ |
| 2 | فارغ | 400 | ✅ |
| 3 | مسافات فقط | 400 (يُطبَّع إلى فراغ) | ✅ |
| 4 | رموز فقط `!!!` | 400 (**التطبيع قبل الفحص** — أُصلح في 7c4d4dc) | ✅ |
| 5 | مفتاح صالح | إدراج + 302 | ✅ |
| 6 | نفس المفتاح + نفس الحمولة | 302 `dup=1&pid=` بلا صفّ جديد | ✅ |
| 7 | نفس المفتاح + **عضو مختلف** | 409 (مقارنة `member_id`) | ✅ |
| 8 | نفس المفتاح + **اشتراك مختلف** | 409 (مقارنة `membership_id`) | ✅ |
| 9 | نفس المفتاح + **مبلغ مختلف** | 409 (تسامح 0.005) | ✅ |
| 10 | نفس المفتاح + **طريقة مختلفة** | 409 (مقارنة `method`) | ✅ |
| 11 | **طلبان متزامنان** | الفهرس الفريد يحجز؛ الخاسر ينتظر ثم يحصل 1062، فيقرأ الصفّ **المُلتزَم** ويقارن ⇒ 302 dup أو 409 | ✅ **لا سباق** |

**Semantic gaps (غير حاجبة):**
- ⚠️ المفتاح **عالمي غير مُنطَّق** (لا يرتبط بالعضو/الجلسة) ⇒ موظّف يرسل مفتاحًا مصادفًا
  يحصل على 409 ودفعته لا تُسجَّل (إزعاج، لا خسارة مالية).
- ⚠️ المفتاح يُولَّد **لكل عرض للنموذج** ⇒ فتح النموذج مرّتين وإرسالهما = **دفعتان مشروعتان**
  (سلوك مقصود؛ الحماية من *إعادة الإرسال* لا من *الازدواج المنطقي* بالمعنى الأوسع).
- ⚠️ `status` مُختار في استعلام المقارنة وغير مُستخدَم في الشرط (غير ضار).
- 🔶 **مسارا 400/409 يُرجعان JSON خامًا** بينما طبقة AJAX في `page_script()` تتوقّع HTML ⇒
  تسقط إلى إرسال أصلي فتظهر الـJSON للمستخدم. **الحماية سليمة (لا كتابة/لا ازدواج)** لكن
  التجربة خشنة. HIGH لتجربة المستخدم، ليس لسلامة البيانات.
- ⚠️ **POS (`pos_sales`) بلا حماية idempotency** — نفس خطر الازدواج قائم هناك (خارج نطاق P0).

## 10. Attendance Integrity

**المسارات الثلاثة (FACT):** QR (`checkin.php`) · يدوي (`captains.php`) · جلسة حيّة
(`session.php`). **كلها** تفحص التكرار تطبيقيًا أولًا (`captains.php:396` يرمي تعارضًا
صريحًا)، و`ON DUPLICATE KEY UPDATE member_id = member_id` احتياطي سباق فقط.
- **لا كتابة فوق سجلّ قائم** — أُثبت بمعاملة مُلغاة: محاولة كتابة (`20:00/22:00/attended=0`)
  تركت الأصل (`08:00/10:00/attended=1`) سليمًا ✅.
- **قاعدة العمل** «حضور واحد/عضو/يوم» **مُستنتَجة من نموذج البيانات** (عمودا check_in/out في
  صفّ واحد) — **لم تُؤكَّد من المالك**. القيد يمنع نهائيًا تسجيل زيارتين في اليوم.
- ⚠️ التاريخ من `CURDATE()` = توقيت الخادم (نفس ملاحظة المنطقة الزمنية).
- ⚠️ عند التكرار في المسار اليدوي، `check_out_time` المُدخَل **يُهمَل** في نافذة السباق فقط
  (المسار الاعتيادي يعطي خطأ صريح).

## 11. Security Regression (READ-ONLY)

**لا انحدار (FACT).** `require_role(['admin','manager','reception'])` و`csrf_check()`
قائمان في `finance.php`؛ كل القيم مربوطة `?` (شمل `idempotency_key`)؛ المفتاح مُعقَّم
`[^A-Za-z0-9]` ومحدود 64؛ لا concatenation لمدخل خام؛ نطاق revenue من الجلسة؛ الرفع
والجلسات والترويسات كما هي. **لا أسرار في المستودع** (`.env` غير موجود).
⚠️ **Auditability:** `security.log` يغطّي المصادقة/التفويض فقط — **لا سجلّ تدقيق أعمال**
(من غيّر دفعة/اشتراكًا/دورًا)، و`audit_logs` معرّف وغير مُفعَّل.

## 12. Performance & Growth (MEASURED على ديمو + ESTIMATED للنمو)

**مقيس بـEXPLAIN:** `idx_ms_pay_status` · `idx_ms_end_date` · `idx_members_status` ·
`idx_att_date` ⇒ **مستخدمة فعلًا** (`ALL/NULL → range/ref`).
🔶 **`idx_ms_renew_status` و`idx_fu_next_date` — غير مستخدمين** (نفي `<>` وشرط `OR` في الـview)
⇒ تكلفة كتابة بلا فائدة قراءة. **P2 (إزالة أو تبرير).**

| Scale | الحالة | العنق الأول | التصنيف |
|---|---|---|---|
| 1× | ✅ | — | — |
| 10× | 🟡 | بحث `LIKE '%q%'` (full scan) | **P1** |
| 100× | 🟠 | + تقارير live بلا caching + لا pagination | **P1/P2** |
| 1 سنة / 3 سنوات | 🔴 | `daily_attendance` أسرع نموًا؛ لوحات ثقيلة | **P2** |

**N+1:** غير واسع على مستوى التطبيق (التجميع في PHP فوق نتائج views) ✅.

## 13. Failure / Recovery Analysis

| # | السيناريو | الأثر | الكشف | التعافي / فقد البيانات |
|---|---|---|---|---|
| A | 21 ينجح، 22 يفشل | مخطط جزئي (عمود+UNIQUE للدفع فقط) | خروج غير صفري + `deploy.log` | أصلِح التكرارات ثم أعد 22 (idempotent). **لا فقد** |
| B | 22 ينجح، 23 يفشل | فهارس ناقصة فقط | كما أعلاه | أعد 23. **لا فقد** |
| C | المخطط ينجح، نشر الكود يفشل | الكود القديم + مخطط جديد | خطأ نشر | **متوافق:** العمود nullable والقديم لا يذكره. تحفّظ: خطأ 23000 خشن في سباق الحضور. **لا فقد** |
| D | الكود ينجح ثم خطأ تطبيقي | صفحة 500 عامة + سجلّ | `security.log`/error_log | تراجع الكود (المخطط يبقى). **لا فقد** |
| E | النسخة لا تُستعاد | **كارثي** | لا يُكتشف إلا وقت الحاجة | ❌ **لا تعافٍ** — لذا التحقّق من الاستعادة **حاجب** |
| F | تكرار حضور قائم | 22 يفشل ⇒ توقّف النشر | خطأ 1062 | فحص preflight يمنعه مسبقًا. **لا فقد** |
| G | تصادم مفتاح idempotency | 302 dup (تطابق) أو 409 (اختلاف) | استجابة HTTP | لا ازدواج ولا كتابة. **لا فقد** |
| H | انقطاع القاعدة أثناء طلب | استثناء ⇒ `rollBack` | 500 + سجلّ | المعاملة ذرّية. **لا حالة جزئية** |
| 🔴 X | **تشغيل `deploy.sh` على الإنتاج** | **محو كل الجداول** | فوري وكارثي | **فقد كامل** ما لم توجد نسخة قابلة للاستعادة |

## 14. Risk Register

| ID | الخطورة | البند | الحالة |
|---|---|---|---|
| PR-01 | 🔴 **BLOCKER** | `deploy.sh` يُسقط 20 جدولًا (مُدمِّر للإنتاج) | مفتوح |
| PR-02 | 🔴 **BLOCKER** | فحص تكرار الحضور على الإنتاج غير مُنفَّذ (22 قد يفشل) | مفتوح |
| PR-03 | 🔴 **BLOCKER** | قابلية الاستعادة غير مُتحقَّقة على بيانات إنتاجية | مفتوح |
| PR-04 | 🟠 HIGH | النسخ لا تشمل `uploads/` ⇒ فقد صور | مفتوح |
| PR-05 | 🟠 HIGH | لا off-site/تشفير للنسخ (SPOF) | مفتوح |
| PR-06 | 🟠 HIGH | `restore.sh --force` قد يكتب فوق الإنتاج بلا حاجز | مفتوح |
| PR-07 | 🟠 HIGH | 400/409 JSON يكسر تجربة AJAX | مفتوح |
| PR-08 | 🟠 HIGH | لا observability/تنبيه إنتاجي | مفتوح |
| PR-09 | 🟡 MED | POS بلا حماية ازدواج | مفتوح |
| PR-10 | 🟡 MED | لا refund/reversal ⇒ الإيراد قد يبالغ | مفتوح |
| PR-11 | 🟡 MED | لا audit trail أعمال (`audit_logs` ميّت) | مفتوح |
| PR-12 | 🟡 MED | فهرسان غير مستخدمين (مقيس) | مفتوح |
| PR-13 | 🟡 MED | `LIKE '%q%'` full scan + لا pagination | مفتوح |
| PR-14 | 🟡 MED | قاعدة «حضور واحد/يوم» غير مؤكَّدة من المالك | مفتوح |
| PR-15 | 🟡 MED | DDL غير معاملاتي ⇒ ترحيل جزئي (مخفَّف بالـidempotency) | مقبول |
| PR-16 | 🟡 MED | المنطقة الزمنية = الخادم (حدود اليوم/الشهر) | مفتوح |
| PR-17 | 🔵 LOW | مفتاح idempotency عالمي غير مُنطَّق | مقبول |
| PR-18 | 🔵 LOW | لا timeout للجلسة | مفتوح |
| PR-19 | 🔵 LOW | `unsafe-inline` في CSP | مفتوح |
| PR-20 | 🔵 LOW | `test_db_invariants` يحتاج متغيّرات بيئة (DX) | مقبول |

## 15. P0 Blockers (3)
1. **PR-01** — لا تستخدم `deploy.sh` للإنتاج (مُدمِّر). يلزم إجراء نشر تفاضلي موثَّق.
2. **PR-02** — تنفيذ فحص تكرار الحضور على الإنتاج قبل `22`.
3. **PR-03** — إثبات استعادة نسخة على بيئة مطابقة (وجود ملف ≠ تعافٍ).

## 16. P1 Required
PR-04 (uploads في النسخ) · PR-05 (off-site/تشفير) · PR-06 (حاجز restore) ·
PR-07 (تجربة 400/409) · PR-08 (observability) · PR-09 (POS idempotency).

## 17. P2 Improvements
PR-10 refund flow · PR-11 audit trail · PR-12 إسقاط الفهرسين · PR-13 بحث/pagination ·
PR-14 تأكيد قاعدة الحضور · PR-16 المنطقة الزمنية.

## 18. P3 Nice-to-have
PR-18 timeout الجلسة · PR-19 CSP nonces · PR-20 سكربت تشغيل الاختبارات · تنظيف `status`
غير المستخدم في استعلام المقارنة.

## 19. Production Go / No-Go

**Merge (PR #41): CONDITIONAL GO** — الكود صحيح ومُختبَر ولا يُدخل انحدارًا؛ الموانع
**تشغيلية لا برمجية**، لكن **يجب تصحيح تعليمات النشر في وصف الـPR** قبل الدمج (PR-01).

**Production deployment: NO-GO** حتى تُغلق الموانع الثلاثة.

## 20. Exact Conditions Required Before Merge
1. تصحيح قسم *Production Deployment Preconditions* في PR #41 ليقول صراحةً:
   **«لا تشغّل `deploy.sh` أو `reset_and_deploy.sh` على الإنتاج — مُدمِّران»**، مع أمر
   `mysql < sql/21|22|23` التفاضلي.
2. تأكيد المالك لقاعدة «حضور واحد لكل عضو/يوم» (PR-14).
3. الإقرار بأن 400/409 تُعرض كـJSON خام حاليًا (PR-07) كقيد معروف.

## 21. Exact Conditions Required Before Production Deployment
1. **نسخة احتياطية موثَّقة** (`backup.sh`) + **`gzip -t`** + **استعادة تجريبية على قاعدة
   منفصلة** ⇒ إثبات التعافي (PR-03).
2. **نسخ يدوي منفصل لـ`dashboard/uploads/`** (خارج تغطية `backup.sh`) (PR-04).
3. **فحص preflight للتكرارات** — صفر صفوف قبل `22` (PR-02). أي صفوف ⇒ توقّف.
4. **تطبيق تفاضلي فقط:** `21` → `22` → `23` عبر `mysql` مباشرة، **لا `deploy.sh`** (PR-01).
5. **نشر الكود بعد المخطط** (وعند التراجع: الكود أولًا ثم المخطط).
6. **تحقّق ما بعد النشر:** وجود القيود/الفهارس · `tests/test_db_invariants.php` ·
   `tests/test_payment_idempotency.php` على بيئة مطابقة · مطابقة «المحصّل» مع
   `SUM(payments WHERE status='paid')` · مراجعة `logs/deploy.log`.
7. **نافذة صيانة** مقدَّرة من حجم `daily_attendance`/`payments` (زمن `ALTER`) — غير معروف الآن.
8. **خطة تراجع مكتوبة وجاهزة** (`DROP INDEX`/`DROP COLUMN` + `git revert`).

---

## 22. Production Readiness Scores

| المحور | Score | السبب |
|---|:---:|---|
| Security | **85** | لا انحدار؛ ضوابط قائمة؛ ينقص audit trail أعمال |
| Data Integrity | **80** | قيود قاعدة قوية؛ حضور/دفع محميّان؛ تكرارات الإنتاج مجهولة |
| Database | **78** | مخطط سليم + مهاجرات idempotent؛ فهرسان بلا فائدة |
| Deployment | **35** | 🔴 لا أداة نشر تفاضلية؛ الأداة الوحيدة مُدمِّرة |
| Backup / Recovery | **45** | سكربت متين لكن بلا uploads/off-site/تشفير/إثبات استعادة |
| Performance | **62** | فهارس نافعة مقيسة؛ بحث/pagination/تقارير عالقة |
| Observability | **35** | سجلّ أمني فقط؛ لا مراقبة ولا تنبيه |
| Maintainability | **62** | نواة نظيفة + اختبارات جديدة؛ god file قائم |

### **Overall Production Readiness: 60/100**
> الدرجة **لا تُخفي الموانع**: النشر محظور رغم أن الكود جاهز — لأن **عملية** النشر
> والتعافي هي الحلقة الضعيفة، لا البرمجة.
