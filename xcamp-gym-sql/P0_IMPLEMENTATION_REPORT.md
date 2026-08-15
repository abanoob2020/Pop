# P0_IMPLEMENTATION_REPORT.md — XCAMP GYM

Controlled, production-safe P0 hardening. Scope-locked (P0-A..E). **لا scope creep.**
كل تغيير مُختبَر بالأدلّة (EXPLAIN + اختبارات + HTTP end-to-end).

## Executive Summary
نُفِّذت حزمة P0 كاملة بأقل تغيير وأعلى حماية: تصحيح الإيراد (مصدر الدفتر)، حماية ازدواج
الدفع بقيد قاعدة، سلامة الحضور بقيد UNIQUE، فهارس مبنية على أدلّة EXPLAIN، واختبارات
انحدار حرِجة بلا framework. **لا CRITICAL مفتوح، لا انحدار أمني، لا فقد بيانات.**

## Pre-Implementation State
- Branch البدء: `fix/biz-001-revenue-ledger` @ `d8bf910` (يحوي P0-A). أُنشئ فرع P0:
  **`fix/p0-xcamp-hardening`** منه.
- Working tree: نظيف من التعديلات المُتتبَّعة (فقط تقارير `.md` غير مُتتبَّعة — لم تُلمس).
- بيئة: PHP 8.4.19، MariaDB 10.11.14 (MEASURED).
- **بوّابات البيانات (READ-ONLY):** تكرارات الحضور = **0**، تكرارات الدفع = **0**،
  `reference_no/receipt_no` = كلها NULL. ⇒ آمن لإضافة القيود الفريدة.

## Changes Made (Files)
| ملف | P0 | التغيير |
|-----|----|---------|
| `dashboard/revenue.php` | A | «محصّل» = SUM(payments status='paid') بنطاق الجلسة (مُنجز في d8bf910) |
| `dashboard/finance.php` | B | مفتاح idempotency في نموذج الدفع + معالجة ازدواج (نجاح idempotent) |
| `dashboard/checkin.php` | C | INSERT حضور ⇐ `ON DUPLICATE KEY UPDATE` |
| `dashboard/captains.php` | C | INSERT حضور يدوي ⇐ `ON DUPLICATE KEY UPDATE` |
| `dashboard/session.php` | C | INSERT حضور من الجلسة ⇐ `ON DUPLICATE KEY UPDATE` |
| `sql/21_payment_idempotency.sql` | B | عمود `idempotency_key` + `UNIQUE uq_payments_idem` |
| `sql/22_attendance_unique.sql` | C | `UNIQUE uq_attendance_member_day (member_id, attendance_date)` |
| `sql/23_perf_indexes.sql` | D | 6 فهارس مبنية على أدلّة |
| `deploy.sh`, `run_all.sql` | B/C/D | توصيل المهاجرات (21/22/23) |
| `tests/test_pure.php`, `tests/test_db_invariants.php` | E | اختبارات انحدار |

## Database Changes (كلها إضافية، لا تمسّ 01_tables.sql)
- `ALTER payments ADD COLUMN idempotency_key VARCHAR(64) NULL` + `UNIQUE(idempotency_key)`
  (NULL متعدّد مسموح ⇒ الصفوف القديمة آمنة).
- `ALTER daily_attendance ADD UNIQUE(member_id, attendance_date)`.
- 6 فهارس: `idx_att_date`, `idx_ms_end_date`, `idx_ms_pay_status`, `idx_ms_renew_status`,
  `idx_members_status`, `idx_fu_next_date`.
- **قابلة لإعادة التشغيل** (`IF NOT EXISTS`)، **قابلة للتراجع** (§Rollback).

## P0-A — Revenue (VERIFIED)
«محصّل» يُحسب من دفتر `payments` (النقد الفعلي)، لا من علم `memberships.payment_status`.
تحقّق: `SELECT SUM(amount) WHERE status='paid'` = 12800.00 (متطابق مع منطق revenue.php).

## P0-B — Payment Idempotency (VERIFIED — HTTP end-to-end)
- **Business identity:** لا مُعرّف عمل موجود مأهول (`reference_no/receipt_no` NULL). أُدخِل
  مفتاح idempotency يُولّد لكل نموذج (`bin2hex(random_bytes(16))`).
- **حماية القاعدة (invariant):** `UNIQUE(idempotency_key)` ⇒ الإرسال المكرّر (نفس المفتاح)
  يفشل INSERT الثاني ويُعامَل نجاحًا idempotent (`ok=1&dup=1`) بلا صفّ مكرّر.
- **فعّال ضدّ:** نقر مزدوج/تحديث/إعادة محاولة/طلبات متزامنة (القيد على مستوى القاعدة، لا
  JS وحده). المعاملة (payment + membership + كود) تبقى ذرّية؛ الازدواج يُلغى بالكامل.
- **MEASURED:** إرسالان متطابقان ⇒ `payments` +1 فقط (4→5). دفعة بمفتاح جديد ⇒ +1.

## P0-C — Attendance Integrity (VERIFIED)
- Business rule: **حضور واحد/عضو/يوم**. القيد `UNIQUE(member_id, attendance_date)` يفرضه.
- المسارات الثلاثة (QR/كابتن/جلسة) تستخدم `ON DUPLICATE KEY UPDATE member_id=member_id`
  ⇒ سباق SELECT-ثم-INSERT مُغلق، والتكرار عملية idempotent بلا خطأ.
- بوّابة البيانات: 0 تكرارات حالية ⇒ القيد أُنشئ بأمان.

## P0-D — Indexes (evidence-based)
| Query | Before | After (MEASURED) | Decision |
|-------|:---:|:---:|:---:|
| memberships.payment_status IN(...) | ALL / key=NULL | **range / idx_ms_pay_status / Using index** | ADD |
| memberships.end_date range | ALL / key=NULL | **range / idx_ms_end_date / Using index** | ADD |
| daily_attendance by date | ALL / key=NULL | **ref / idx_att_date** | ADD |
| members.status | ALL / key=NULL | **ref / idx_members_status / Using index** | ADD |
| memberships.renewal_status | ALL / key=NULL | idx متاح | ADD |
| followups.next_followup_date | ALL / key=NULL | idx متاح | ADD |
| **members search `LIKE '%q%'`** | ALL / key=NULL | لا يستفيد من B-Tree | **REJECT** (FULLTEXT لاحقًا) |
| **payments(status)** | — | 'paid' غالبة (انتقائية منخفضة) | **DEFER** |
| **payments(payment_date)** | — | `YEAR()` يلفّه (غير sargable) | **DEFER** (يلزم إعادة صياغة) |

## Tests Added / Executed
- `tests/test_pure.php` (بلا قاعدة/framework): **10/10 PASS** — المحرّك التكيّفي (1RM≠وزن العمل،
  up/hold/warn)، 1RM/round25، حاسبة الوصفات (المطبوخ/الخام).
- `tests/test_db_invariants.php` (معاملات تُلغى — reversible): **12/12 PASS** — وجود القيود/
  الفهارس، دلالة الإيراد، حجب ازدواج الدفع، حجب تكرار الحضور، سلامة ON DUPLICATE.
- **HTTP end-to-end:** إرسال مزدوج ⇒ +1 فقط · مبلغ 0 ⇒ مرفوض بلا صفّ · دفعة مميّزة ⇒ +1 ·
  CSRF بلا رمز ⇒ مرفوض.
- **لا framework جديد** (لا PHPUnit/composer) — سكربتات PHP نقية، منخفضة المخاطر.

## Before/After Verification
| القياس | Before | After |
|--------|:---:|:---:|
| payments (إرسال مزدوج) | 4 | 5 (+1 فقط) |
| قيود/فهارس | غير موجودة | 2 UNIQUE + 6 index (VERIFIED) |
| EXPLAIN (4 استعلامات) | type=ALL, key=NULL | range/ref, key=idx_* |
| المحصّل (ledger) | — | 12800.00 = SUM(paid) |

## Security Regression (PASS)
`csrf_check()` باقٍ في finance، `require_role(['admin','manager','reception'])` بلا تغيير،
كل INSERT مُعامَل (idempotency_key وحقول الحضور bound بـ `?`)، لا concatenation لمدخل خام.

## Risks
- **منخفض:** القيود إضافية على بيانات نظيفة (بوّابات مرّت)؛ الفهارس تُحسّن القراءة وتضيف
  overhead كتابة ضئيلًا.
- **ملاحظة سلوك:** الحضور اليدوي (captains) عند التكرار = no-op (لا يدمج check-out) — قرار
  عمل مؤجّل (P0_FOLLOWUP).

## Rollback Plan
- **كود:** `git revert`/`git checkout` للملفات الستة (الفرع منفصل، لم يُدمج).
- **قاعدة (كلها reversible):**
  - `ALTER TABLE payments DROP INDEX uq_payments_idem, DROP COLUMN idempotency_key;`
  - `ALTER TABLE daily_attendance DROP INDEX uq_attendance_member_day;`
  - `DROP INDEX idx_att_date ON daily_attendance;` (وبقية الفهارس الخمسة).
- **قبل النشر الإنتاجي:** نسخة احتياطية (`backup.sh`) + تشغيل بوّابة التكرارات على بيانات
  الإنتاج الفعلية (قد تختلف عن الديمو) قبل إضافة UNIQUE.

## Remaining P0/P1 + Out-of-Scope
انظر `P0_FOLLOWUP.md`.

## Final Verdict
**P0 PASS** — كل معايير القبول محقّقة، بلا انحدار أمني/بيانات، أقل تغيير + أعلى حماية +
دليل قابل للتحقق + rollback واضح.
