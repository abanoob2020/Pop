# Audit Decision Memo — XCAMP GYM

**المرحلة:** Business Decision Validation (تحقّق بالأدلّة + قرار معماري فقط).
**لا كود/SQL/schema/migration/refactor في هذه الوثيقة.** كل بند مسنود بموقع في الكود.
التصنيف: `Confirmed` / `Likely` / `Possible` / `Not an issue`.

---

## Executive Decision

`SYSTEM_AUDIT.md` **صالح للاعتماد كمرجع.** التحقّق العميق أكّد الأساسيات القوية، وضيّق
بعض الأحكام:
- **BIZ-001** حقيقي لكن **أضيق وأدقّ** ممّا بدا: ليس «حقلين متضاربين» بل **دورَان مشروعان**
  (علم تشغيلي vs دفتر مالي)، والعيب الفعلي **سطر واحد** في `revenue.php` يعامل العلم
  التشغيلي كمقياس نقدي. الإصلاح جراحي، بلا تغيير schema.
- **BIZ-002 (التواريخ)** ← **Not an issue** فعليًا (تواريخ محسوبة نظاميًا، غير قابلة لإدخال المستخدم).
- **INTEG-001 (سباق الحضور)** ← **Confirmed** ممكن.
- **audit_logs** ← **Confirmed** غير مستخدم إطلاقًا.

---

## 1. BIZ-001 — Payment Source of Truth (تتبّع كامل)

### الأدلّة (المواقع الفعلية)
| السؤال | الجواب المسنود |
|--------|----------------|
| **1. أين يُنشأ payment؟** | مكان **واحد فقط**: `finance.php:90` `INSERT INTO payments (... status='paid')`. (مبيعات POS تذهب لـ`pos_sales` لا `payments`.) |
| **2. أين يُعدّل payment_status؟** | ثلاثة: (أ) `index.php:31` إنشاء العضوية بـ`'unpaid'` (محسوب)؛ (ب) `finance.php:93` `UPDATE ...='paid'` **مع** إدراج صف payment (داخل transaction)؛ (ج) `revenue.php:30` `UPDATE ...=<اختيار الطاقم>` **بدون** أي صف payments. |
| **3. هل تصبح membership=paid بلا payment؟** | **نعم، Confirmed** — عبر `revenue.php::set_renewal` (المسار ج). |
| **4. هل يوجد سيناريو أعمال مشروع لذلك؟** | **نعم** — الدفع النقدي/اليدوي/المجاملة (complimentary)/المُعفى (waived)/الخارجي، حيث يعلّم الاستقبال الحالة دون تسجيل صف دفع رسمي. |
| **5. حالات تفسّر ذلك؟** | cash/manual/complimentary/waived/external — كلها مشروعة تشغيليًا، لكن الأفضل تسجيل صف payment (حتى نقدي) ليكتمل الدفتر. |
| **6. هل finance.php مصدر الإيراد الفعلي؟** | **نعم** — الدخل = `SUM(payments.amount WHERE status='paid')` + `SUM(pos_sales.total)` (`finance.php:113-115`). هذا **دفتر المال**. |
| **7. هل revenue.php يستخدم الحالة لغرض مختلف؟** | **نعم جزئيًا** — يستخدمها لـ`likelihood`/`owed`/pipeline (تشغيلي، صحيح)، **لكنه أيضًا** يحسب «محصّل» ماليًا: `revenue.php:71` `if payment_status==='paid' $collected += $price` — **هنا العيب**: يخلط العلم التشغيلي بمقياس نقدي، ويستخدم **سعر الخطة** لا مبلغ الدفعة الفعلي. |
| **8. تقارير أخرى بمصدر مختلف؟** | نعم، وهذا يكشف الدورَين: **`payments`** يغذّي `finance.php` و`vw_at_risk_members`/`vw_member_operational_status`(last_payment_status)/`vw_dashboard_kpis`(failed_payments). **`memberships.payment_status`** يغذّي `vw_overdue_payments`/`vw_membership_expiry_soon`/`vw_dashboard_renewals`/`revenue.php`/`crm.php`/`checkin.php`(بوّابة الدخول)/`portal.php`/`training.php`(الجاهزية). |

### دورة الحياة الحالية (أين يدخل payments)
```
[index.php] إنشاء عضو + عضوية
        payment_status = UNPAID   ,   renewal_status = PENDING
        (start_date=CURDATE, end_date=CURDATE+duration — محسوبة نظاميًا)
                 │
      ┌──────────┴───────────────────────────────┐
      │ مسار مالي (finance.php)                    │ مسار تشغيلي (revenue.php::set_renewal)
      │ INSERT payments(status=paid, amount)      │ UPDATE memberships.payment_status = paid
      │ + UPDATE membership.payment_status=paid   │ (لا صف payments)  ← نقدي/مجاملة/معفى…
      │ (transaction ذرّي)                         │
      └──────────┬───────────────────────────────┘
                 ▼
        PAID (علم تشغيلي) ───► RENEWED / EXPIRED / CANCELLED (renewal_status)
                 │
   دفتر المال الحقيقي = صفوف payments فقط (finance)
   خطر التضارب = revenue «محصّل» يجمع price×(علم=paid) لا SUM(payments)
```

### أثر التضارب (Confirmed، ثلاثة مصادر)
1. **دفعة نقدية عبر revenue** (بلا صف payments): revenue «محصّل» يحسبها، finance لا.
2. **دفعة جزئية عبر finance**: `amount < price` لكن العضوية تُعلّم `paid`؛ revenue يحسب **السعر الكامل** كمحصّل، finance يحسب المبلغ الفعلي.
3. **النتيجة:** «محصّل» في revenue ≈ قيمة تعاقدية للعضويات المُعلّمة paid، **ليست نقدًا محصّلًا**؛ التسمية «✅ محصّل (مدفوع)» مضلّلة.

### النماذج المقترحة (بلا تنفيذ)

**Model A — `payments` مصدر الحقيقة الوحيد**
اشتقاق `payment_status` من `payments` (paid إذا `SUM(payments)≥price`)؛ إلغاء التعليم اليدوي في revenue.
- ✅ اتّساق مالي مطلق، مصدر واحد.
- ❌ يكسر الدور التشغيلي المستخدم في ٨ views/صفحات (بوّابة الدخول، الاحتفاظ، الجاهزية) — تغيير واسع.
- Migration: إعادة اشتقاق الحالة + تعديل كل قارئ للعلم. **مرتفع.**
- Reporting: موحّد. Risk: **مرتفع** (يمسّ منطقًا تشغيليًا واسعًا).
- التوصية: أقوى نظريًا، لكنه الأعلى كلفة/خطرًا على هذا الكود.

**Model B — `payment_status` علم تشغيلي، `payments` الحقيقة المالية** ⭐
إبقاء العلم للتشغيل (بوّابة/تحصيل/خطر)، وجعل **كل مقاييس المال** (finance + revenue «محصّل») تقرأ من `payments`.
- ✅ يطابق البنية الفعلية (الدورَان موجودان أصلًا)؛ إصلاح **جراحي** (سطر revenue «محصّل» → `SUM(payments)`).
- ✅ بلا تغيير schema؛ يحافظ على كل السلوك التشغيلي.
- ⚠️ يبقى العلم قابلًا للتعليم يدويًا (مقبول تشغيليًا)؛ يُستحسن أن يُنشئ التعليم اليدوي «paid» صفَّ دفعة نقدية (اختياري) لاكتمال الدفتر.
- Migration: **منخفض** (تعديل قراءة revenue + توثيق الدور). Reporting: متّسق. Risk: **منخفض**.
- **التوصية: ✅ المعتمَدة.**

**Model C — فصل تام (الحالة ≠ الدفع)**
`payment_status` مجرّد تسمية بلا معنى مالي؛ الدفع يُربط منفصلًا كليًا.
- ✅ فصل نظري نظيف.
- ❌ يلغي فائدة العلم في بوّابة الدخول/الاحتفاظ (يحتاج مصدرًا بديلًا)؛ إعادة تصميم.
- Migration/Risk: **مرتفع** بلا قيمة إضافية على Model B هنا.

### القرار (BIZ-001)
**Model B.** المبرّر: البنية الحالية **بالفعل** تستخدم الدورَين؛ العيب الوحيد هو مقياس
«محصّل» في `revenue.php` (سطر 71) الذي يجمع `price` حسب العلم بدل `SUM(payments)`.
الإصلاح المقترح (للتنفيذ لاحقًا بعد موافقتك، **لا الآن**):
1. `revenue.php` «محصّل» يُحسب من `payments` (نقد فعلي)، مع إبقاء «القيمة التعاقدية
   للعضويات المدفوعة» كمقياس منفصل مُسمّى بوضوح إن رغبت.
2. توثيق الدورَين صراحةً (علم تشغيلي vs دفتر مالي).
3. (اختياري) جعل تعليم «paid» اليدوي في revenue يُنشئ صف دفعة نقدية ليكتمل الدفتر.

---

## 2. INTEG-001 — Attendance Race

- **Current state (Confirmed):** ثلاثة مسارات إدراج — `checkin.php:35` (QR)، `captains.php:400` (يدوي)، `session.php:70` (جلسة حيّة). `checkin.php` يمنع التكرار بـ`SELECT ... WHERE member_id,attendance_date=CURDATE()` ثم `INSERT` **خارج transaction**. لا قيد `UNIQUE(member_id, attendance_date)`؛ فقط PK على `attendance_id` + فهرسا FK.
- **Risk:** طلبان متزامنان لنفس العضو/اليوم يمرّان الفحص كلاهما → صفّان. كذلك مسارات مختلفة (QR + جلسة) لا تتنسّق → حضور مكرّر. وكل إدراج يُشغّل `trg_attendance_after_insert` → `sp_handle_attendance_event` مرّتين (احتمال ازدواج أتمتة). **Likely عند التزامن؛ Confirmed بنيويًا.**
- **صفوف مكرّرة حالية:** لا يمكن تأكيدها بلا بيانات إنتاج؛ ممكنة بنيويًا.
- **Safe constraint design (للتنفيذ لاحقًا):** `UNIQUE KEY uq_attendance_member_day (member_id, attendance_date)` + تحويل الإدراج إلى `INSERT ... ON DUPLICATE KEY UPDATE` أو `INSERT IGNORE`. هذا يُلغي السباق على مستوى القاعدة ويجعل كل المسارات آمنة.
- **Potential migration issue:** إن وُجدت صفوف مكرّرة مسبقًا، سيفشل إنشاء القيد الفريد —
  يجب **إزالة/دمج التكرارات أولًا** (إبقاء الأقدم لكل member/يوم) ثم إضافة القيد. لا تنفيذ الآن.

---

## 3. Financial Validation

| الفحص | الحالة | الدليل |
|-------|--------|--------|
| مبلغ سالب | **Not an issue** | `finance.php:78` `if ($amount <= 0) throw` |
| مبلغ صفر | **Not an issue** | نفس الفحص يرفض 0 |
| خصم سالب/يتجاوز المبلغ | **Not an issue** | `discount_evaluate` يقصّ `max(0,min(disc,base))` |
| دقّة عشرية | **Not an issue** | `DECIMAL(10,2)` في payments/plans |
| دفعة بلا عضوية | **Not an issue** | دفعات العضوية دائمًا بـ`membership_id`؛ POS منفصل في pos_sales |
| عضوية بلا دفعة | **Business decision** | مقصود (unpaid افتراضي؛ نقدي/مجاملة) |
| دفعة أكبر/أقل من سعر الخطة | **Business decision / Potential** | لا فحص `amount==price`؛ جزئي يُعلّم paid ويُحسب سعرًا كاملًا في revenue (جزء من BIZ-001) |
| ازدواج دفعة | **Potential bug (Confirmed لا حارس)** | لا مفتاح idempotency ولا `UNIQUE` على `receipt_no/reference_no`؛ تعطيل الزر عميلي فقط |
| معالجة استرداد (refund) | **Business decision / gap** | `'refunded'` تسمية حالة فقط + تعديل جاهزية (`training.php:733`)؛ لا حركة مال مسجّلة (لا دفعة سالبة) |
| مجاميع متّسقة | **جزء من BIZ-001** | finance(payments) vs revenue(price×flag) |

---

## 4. Date / Status Integrity

- `start_date`/`end_date`: **محسوبة نظاميًا** في `index.php:31`
  (`CURDATE()` و`DATE_ADD(CURDATE(), INTERVAL duration_days DAY)` من `plans`) — **غير قابلة
  لإدخال المستخدم**. `revenue.php::set_renewal` يعدّل الحالة فقط لا التواريخ.
- **`end_date < start_date`:** **غير قابل للحدوث** في المسارات الحالية (Confirmed). لا حاجة
  لتحقّق إضافي ما دامت التواريخ محسوبة. **Validation location: Database-computed (لا مدخل مستخدم).**
- `payment_date`: `NOW()` عند التسجيل (`finance.php`). سليم.
- **ملاحظة (Possible future):** لو أُضيف لاحقًا تحرير تواريخ يدوي (تمديد/تجميد)، يلزم عندها
  تحقّق `end_date > start_date` (frontend + PHP + يفضّل CHECK في القاعدة). حاليًا **None needed**.

---

## 5. audit_logs

1. **لماذا أُنشئ؟** كسجلّ **تدقيق تغييرات الكيانات** (من غيّر ماذا، قبل/بعد).
2. **الأعمدة:** `user_id, entity_name, entity_id, action_type ENUM(insert/update/delete/login/logout/export), old_data JSON, new_data JSON, created_at`.
3. **triggers/procedures تشير إليه؟** **لا** (Confirmed — لا في 02/03/04).
4. **أكان مقصودًا كـ Security Audit Log؟** التصميم أقرب إلى **Business/Entity change-audit** (old/new JSON)؛ الأمان الآن يغطّيه `security.log` الملفّي (من التحصين).
5. **توثيق يشير إليه؟** **لا** (غير مذكور في README).
6. **مسارات كتابة غير مباشرة؟** **لا** — لا يكتب فيه أي كود.
- **القرار المقترح: KEEP + DEFER.** لا يضرّ وجوده (فارغ)، وله قيمة مستقبلية كسجلّ تدقيق
  أعمال (تغييرات المال/العضويات). يُفعّل لاحقًا (P2) أو يُزال رسميًا إن لم يُعتمد. **لا تنفيذ الآن.**

---

## 6. PR #40 — Impact Review (بلا تعديل)

- **CSP:** `script-src 'self' 'unsafe-inline'` → **لا يكسر** inline JS ولا الـ20 معالجًا؛
  `connect-src 'self'` → AJAX (`fetch` لنفس الأصل) يعمل. `style-src 'unsafe-inline'` → الأنماط inline تعمل.
- **AJAX/inline JS:** غير متأثّرة (السياسة تسمح بـinline عمدًا). تأكيد عملي سابق: كل الصفحات 200
  تحت الوضعين، وتسجيل الدخول/captains/checkin تعمل.
- **Report-Only:** اختياري (`CSP_REPORT_ONLY=1`)، **مُطفأ افتراضيًا** → صفر أثر إنتاجي؛ لا يمنع شيئًا.
- **Regressions:** **لا يوجد** واضح. **Security findings جديدة:** **لا**. `csp_report.php` بلا تسجيل
  دخول لكنه لا يُخرج شيئًا، يحدّ الحجم 16KB، ويسجّل فقط.
- **الخلاصة:** **PR #40 آمن ولا regression** — لا يغيّر أي افتراض في الـAudit. (لن يُدمج إلا بأمرك.)

---

## 7. القرارات

### P0
- **BIZ-001 → Model B** (اعتماد `payments` كمصدر مالي؛ إصلاح مقياس «محصّل» في revenue؛ توثيق الدورَين). *لا تنفيذ قبل موافقتك.*

### P1
- **Indexes (PERF-001):** فهارس على أعمدة التصفية/التاريخ (migration إضافي، جهد منخفض).
- **Tests (TEST-001):** بدء بدوال `training.php` النقية + مصادقة/تفويض.
- **Attendance uniqueness (INTEG-001):** تنظيف تكرارات (إن وُجدت) ثم `UNIQUE(member_id, attendance_date)` + `INSERT ... ON DUPLICATE`.
- **Pagination (PERF-002):** لصفحات القوائم.
- **Validation:** حارس idempotency للمدفوعات (منع الازدواج)؛ التواريخ **لا تحتاج** حاليًا.

### Deferred (بلا تنفيذ)
- `captains.php` refactor · `audit_logs` (KEEP+DEFER) · API · multi-tenancy · migrations بإصدارات · framework · CSP nonces (Stage 2+).

### Recommended Implementation Order
1. **BIZ-001 (Model B)** — أعلى قيمة، إصلاح جراحي، بلا schema. *(P0)*
2. **Attendance UNIQUE (INTEG-001)** — منخفض الجهد، يغلق سباقًا. *(P1)*
3. **Indexes (PERF-001)** — منخفض الجهد، مكسب أداء. *(P1)*
4. **Payment idempotency** — يمنع ازدواج الدفع. *(P1)*
5. **Tests (training.php ثم auth/authz)** — شبكة أمان قبل أي refactor. *(P1)*
6. **Pagination** — عند الحاجة للتوسّع. *(P1/P2)*
7. **قرار audit_logs (تفعيل/إزالة)** ثم تفكيك `captains.php`. *(P2)*

---

## ملاحظة سير عمل
- `SYSTEM_AUDIT.md` و`docs/audit-decision-memo.md` جاهزان في شجرة العمل، **غير ملتزَمين**
  (لم أُنشئ PR ولم أدمج #40 — بانتظار مراجعتك وأمرك بالتنفيذ).
