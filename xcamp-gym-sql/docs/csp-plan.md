# خطة CSP — تحليل وتضييق تدريجي (XCAMP GYM)

مبدأ حاكم: **لا CSP قوية دفعة واحدة.** المشروع Raw PHP فيه AJAX وinline JS؛ سياسة
صارمة مباشرة قد تكسر التبويبات/الحفظ عبر AJOX/أزرار التأكيد. نتّبع:

```
Report/analysis → identify inline scripts → safe initial CSP → test all pages → tighten gradually
```

وبلا `unsafe-eval` نهائيًا، وبلا `unsafe-inline` **دائمًا** إلا عند الضرورة **وبتوثيق**.

## 1) Report / analysis — جرد الـinline (المصدر الحالي)

| النوع | العدّ | المواقع |
|-------|------|---------|
| كتل `<script>` inline | 2 | `db.php` (`page_script()`: تبويبات + قائمة موبايل + حفظ AJAX)، `checkin.php` |
| معالجات inline (`onsubmit`/`onclick`…) | 20 | `captains.php` (8)، `portal.php` (4)، `hr.php` (2)، و1 لكل: `finance/assess/assessment_print/templates/retention/revenue` — غالبها `onsubmit="return confirm(...)"` وزر طباعة |
| أنماط inline (`style="…"` + `<style>`) | منتشرة | كل الصفحات (خطر XSS منخفض) |
| `eval` / `new Function` / `unsafe-eval` | **0** | — (مؤكَّد؛ لن يُستخدم) |
| سكربت/نمط خارجي (CDN) | **0** | كل الأصول ذاتية |
| اتصال AJAX | `fetch()` لنفس الأصل | يغطّيه `connect-src 'self'` |

## 2) Safe initial CSP — المُطبَّقة الآن (Stage 0، مُنفَّذة)

تُبعث من `db.php` مع كل صفحة:

```
Content-Security-Policy:
  script-src 'self' 'unsafe-inline';
  style-src  'self' 'unsafe-inline';
  default-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self';
  object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'
```

- تمنع فعليًا: `object`/plugins، تضمين الإطار (clickjacking)، تغيير `base`، إرسال النماذج
  لأصل آخر، وأي أصل خارجي.
- تُبقي `'unsafe-inline'` **لأنه ضروري** (الجرد أعلاه)، وبلا `'unsafe-eval'`.
- **لا تكسر شيئًا** — تُحقّق منها باختبار كل الصفحات.

## 3) Test all pages — نتيجة الاختبار

- ✅ كل صفحات الطاقم (19) تُرجع 200 مع السياسة المُطبَّقة.
- ✅ التبويبات + قائمة الموبايل + الحفظ عبر AJAX (fetch لنفس الأصل) تعمل (`connect-src 'self'`).
- ✅ معالجات `onsubmit`/`onclick` تعمل (يسمح بها `script-src 'unsafe-inline'`).
- ✅ لا موارد خارجية محجوبة (لا CDNs).

## 4) Tighten gradually — أداة القياس (Stage 1، مُنفَّذة)

بدل التخمين، نقيس أثر التضييق **قبل** فرضه:

- فعّل `CSP_REPORT_ONLY=1` ⇒ يُبثّ رأس إضافي **`Content-Security-Policy-Report-Only`**
  بسياسة الهدف الأصرم (`script-src 'self'` — بلا `unsafe-inline`) و`report-uri /csp_report.php`.
- «تقرير فقط» **لا يمنع** أي شيء — الصفحات تعمل كما هي، لكن المتصفّح يُبلّغ عن كل ما
  **سيُحجب** لو فُرِضت السياسة.
- `csp_report.php` يسجّل كل مخالفة في `logs/security.log`
  (`event=csp_violation` مع الـdirective والمصدر) — فتُرى بالضبط السكربتات/المعالجات
  التي تحتاج ترحيلًا.

استخدامه: شغّل بيئة اختبار بـ`CSP_REPORT_ONLY=1`، تصفّح كل الصفحات، ثم راجع
`csp_violation` في السجلّ. القائمة الناتجة = عمل Stage 2/3.

## 5) خارطة التضييق التدريجي (القادم — بلا تنفيذ الآن)

| Stage | الإجراء | الأثر |
|-------|---------|-------|
| 0 ✅ | Safe initial CSP (unsafe-inline ضروري، بلا eval) | مُنفَّذ |
| 1 ✅ | أداة القياس Report-Only + مُجمّع التقارير | مُنفَّذ (اختياري) |
| 2 | إضافة **nonce لكل طلب** لكتلتَي `<script>` (`page_script`, `checkin`) → `<script nonce>` | يزيل حاجة السكربت لـ`unsafe-inline` |
| 3 | ترحيل الـ20 معالج inline إلى `addEventListener` مدفوعة بـ`data-*` | يُلغي آخر اعتماد على inline handlers |
| 4 | حذف `'unsafe-inline'` من `script-src` واستبداله بـ`'nonce-…'` ثم **الفرض** | حماية XSS قصوى للسكربتات |
| 5 | الأنماط: إمّا إبقاء `style-src 'unsafe-inline'` (موثّق، خطر منخفض) أو نقل `<style>` لملف ذاتي + nonce للقليل المتبقّي | نهاية اختيارية |

مبرّرات: خطر XSS عبر **السكربت** أعلى بكثير من الأنماط، لذا نُعطي أولوية لتضييق
`script-src`. `frame-ancestors`/`object-src`/`base-uri` مضبوطة بالفعل منذ Stage 0.

## 6) قواعد ثابتة

- ❌ لا `'unsafe-eval'` أبدًا (ولا يوجد `eval` في الكود لتبريره).
- ⚠️ `'unsafe-inline'` مؤقّت وموثّق هنا؛ هدف Stage 4 إزالته من `script-src`.
- ✅ كل تضييق يمرّ بـ Report-Only + اختبار كل الصفحات قبل الفرض.
