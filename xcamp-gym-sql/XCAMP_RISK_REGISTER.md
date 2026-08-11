# XCAMP_RISK_REGISTER.md

READ-ONLY. سجلّ مخاطر مسنود بالأدلّة. الأولوية = Business Impact × Technical Risk ×
Probability × Future Cost (ليست الشدّة وحدها). التصنيف الإلزامي: **MUST FIX NOW /
SHOULD FIX SOON / FIX WHEN TOUCHING / NICE TO HAVE / DO NOT TOUCH.**

نطاق: `d8bf910` (main/#39 + إصلاح BIZ-001). CSP #40 غير مدموج.

| ID | Finding | Sev | Likelihood | Impact | Evidence (file:line) | Confidence | Change-Risk | التصنيف |
|----|---------|-----|-----------|--------|----------------------|:---:|:---:|---------|
| R-01 | صفر اختبارات آلية (regression صفري لنظام مالي) | HIGH | — | انحدارات صامتة | لا `tests/` (FACT) | 99% | LOW(إضافة) | **MUST FIX SOON** |
| R-02 | BIZ-001 تضارب مصدر الدفع | MEDIUM | High(قبل) | أرقام مالية خاطئة | revenue.php (مُعالَج d8bf910) | 95% | LOW | **MUST FIX NOW** (دمج) |
| R-03 | ازدواج الدفع (لا idempotency/UNIQUE receipt) | MEDIUM | Medium | تضخيم مبالغ | finance.php:90 | 85% | MEDIUM | **MUST FIX SOON** |
| R-04 | فهارس ثانوية ناقصة | MEDIUM | High(نمو) | بطء/full scan | 01_tables.sql (FACT) | 95% | LOW | **SHOULD FIX SOON** |
| R-05 | لا audit trail أعمال (مال/أدوار/حذف) | MEDIUM | Medium | ضعف تدقيق/امتثال | app_log نطاقه أمني؛ audit_logs ميّت | 90% | LOW | **SHOULD FIX SOON** |
| R-06 | نسخ احتياطية بلا off-site/تشفير + **uploads غير منسوخة** | MEDIUM | Medium | فقد/تسريب بيانات | backup.sh (يحفظ DB فقط) | 80% | LOW | **SHOULD FIX SOON** |
| R-07 | Observability blind spots (لا مراقبة أداء/قاعدة/تنبيه) | MEDIUM | High | كشف متأخّر للأعطال | لا أدوات (FACT) | 90% | LOW | **SHOULD FIX SOON** |
| R-08 | سباق الحضور (لا UNIQUE member,date) | LOW-MED | Medium(تزامن) | ازدواج حضور/أتمتة | checkin.php:32-35 | 90% | MEDIUM(تنظيف أولًا) | **FIX WHEN TOUCHING** |
| R-09 | لا pagination (جلب جداول كاملة) | MEDIUM | High(نمو) | ذاكرة/بطء | قوائم بلا LIMIT | 90% | MEDIUM | **SHOULD FIX SOON** |
| R-10 | تقارير live بلا caching/precompute | MEDIUM | High(نمو) | حمل قاعدة/timeout | finance/revenue/analytics | 80% | MEDIUM | **FIX WHEN TOUCHING** |
| R-11 | بحث `LIKE '%q%'` غير مفهرس | MEDIUM | High(نمو) | full scan الأعضاء | captains.php:471 | 90% | LOW | **FIX WHEN TOUCHING** |
| R-12 | عدم اتّساق حالة عضو/عضوية (حالات غير منطقية) | MEDIUM | Medium | بيانات مضلّلة | لا قيد؛ event غير مُتحقَّق كاملًا | 70% | MEDIUM | **SHOULD FIX SOON** |
| R-13 | Historical integrity gap (انتقالات الحالة/المال) | MEDIUM | — | لا تتبّع تاريخي | update-in-place (FACT §7) | 90% | LOW | **FIX WHEN TOUCHING** |
| R-14 | god file captains.php (1506) | MEDIUM | — | صيانة/انحدار | captains.php | 99% | HIGH | **FIX WHEN TOUCHING** (بعد اختبارات) |
| R-15 | نشر يدوي بلا migrations/rollback | MEDIUM | Medium | خطأ نشر | deploy.sh (FACT) | 85% | MEDIUM | **NICE TO HAVE** |
| R-16 | منطق أعمال في triggers (صعب اختبار/تتبّع) | LOW-MED | — | دَين استراتيجي | 03_triggers.sql (FACT) | 90% | HIGH | **DO NOT TOUCH** (الآن) |
| R-17 | لا timeout جلسة (idle/absolute) | LOW | Low | جلسة طويلة | db.php:88-95 | 95% | LOW | **NICE TO HAVE** |
| R-18 | `unsafe-inline` دائم في CSP | LOW | Low | XSS أضعف تخفيفًا | db.php:70-74 | 95% | MEDIUM | **FIX WHEN TOUCHING** (#40) |
| R-19 | refund مالي غير مُنفَّذ (تسمية فقط) | LOW | Low | فجوة محاسبية | لا حركة refund (FACT) | 85% | MEDIUM | **NICE TO HAVE** |
| R-20 | audit_logs جدول ميّت | INFO | — | نيّة غير منفّذة | لا كاتب (FACT) | 95% | LOW | **NICE TO HAVE** (فعّل/أزل) |
| R-21 | `APP_DEBUG` لو فُعِّل إنتاجًا | LOW→HIGH(لو) | Low | تسريب داخلي | db.php:12 (تشغيلي) | 90% | LOW | **DO NOT TOUCH** (إجراء تشغيلي) |
| R-22 | single-tenant / بلا API | INFO | — | حدود التوسّع | لا tenant_id (FACT) | 95% | VERY HIGH | **DO NOT TOUCH** (الآن) |

## المخاطر الأمنية-المالية (أولوية مرفوعة رغم شدّتها التقنية)
R-02, R-03, R-08, R-12 — أي منها قد يتحوّل إلى **خسارة مالية مباشرة** (تضخيم إيراد، ازدواج
دفع، تلاعب حضور/عمولة، بيانات مضلّلة للقرار). عالِجها ضمن المسار المالي أولًا.

## DO NOT TOUCH (الآن) — مبرّرات
- **triggers/procedures (R-16):** مترابطة بشدّة مع كل مسارات الإدراج؛ تعديلها الآن عالي
  الخطر بلا شبكة اختبارات.
- **multi-tenancy (R-22):** يمسّ كل جدول/استعلام؛ بلا طلب تجاري = خطر بلا عائد.
- **APP_DEBUG (R-21):** ليس عيب كود — إجراء نشر (تأكّد أنه غير مضبوط إنتاجًا).
