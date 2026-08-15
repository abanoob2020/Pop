# XCAMP_ARCHITECTURE_ASSESSMENT.md

READ-ONLY تقييم معماري. نطاق: `d8bf910`. الوسوم: FACT/INFERENCE/RISK/STRATEGIC OPINION.

## 1. Current Architecture (FACT)

**Page-per-feature Raw PHP** بلا طبقات. كل صفحة تجمع: UI + Auth + Authz + Validation +
SQL + Response. ملف مشترك `db.php` (اتصال/جلسة/CSRF/ترويسات/require_*). طبقة نقية وحيدة
معزولة = `training.php`. منطق أعمال مهمّ في **القاعدة** (8 triggers → 11 procedures). لا
router/MVC/service/repository/DI. أصول inline ذاتية. جلسات كوكي. لا API.

## 2. Architectural Health Scores (0–100)

| البعد | الدرجة | السبب |
|-------|:-----:|-------|
| Separation of Concerns | 35 | UI+منطق+SQL في صفحة واحدة |
| Coupling | 45 | ربط مباشر بالقاعدة + منطق في triggers |
| Cohesion | 60 | كل صفحة متماسكة حول ميزة (عدا god file) |
| Maintainability | 62 | نواة نظيفة؛ god file + تكرار |
| Testability | 30 | منطق ممزوج بالإخراج (عدا training.php) |
| Extensibility | 55 | إضافة ميزة سهلة (صفحة)؛ تغيير أفقي صعب |
| Observability | 35 | سجلّ أمني فقط؛ لا مراقبة |
| Security | 83 | مؤمَّن جيّدًا |
| Scalability | 45 | single-tenant/بلا pagination/فهارس |

**Architectural Health الإجمالي ≈ 50/100** — «بسيط فعّال لأحمال صغيرة، محدود التوسّع».

## 3. Coupling Map (INFERENCE)

- **Presentation ↔ Data:** ربط مباشر (PDO داخل الصفحات) — أعلى ارتباط.
- **Auth ↔ Session/Cookie:** ربط بالجلسة (يمنع API بدون طبقة رموز).
- **Business ↔ Database:** منطق في triggers → القاعدة ليست مجرّد تخزين.
- **Response ↔ HTML:** لا فصل عرض/بيانات (يمنع JSON بسهولة).

## 4. Scalability Assessment

| Scale | Expected Status | Main Bottleneck |
|-------|-----------------|-----------------|
| **Current** (مئات) | ✅ ممتاز | لا شيء |
| **10x** (~آلاف) | 🟡 جيّد مع بطء بحث | `LIKE '%q%'` + لا pagination |
| **100x** (~10–50k) | 🟠 بطء ملموس | فهارس ناقصة + views ارتباطية + جلب كامل + تقارير live |
| **1000x** (100k+ / ملايين حضور) | 🔴 غير عملي بلا إعادة هيكلة | full scans + COUNT/ORDER BY بلا فهرس + لا caching + single DB |

**INFERENCE (Confidence 75%):** الفهارس + pagination تكفيان حتى ~50k عضو. ما بعده يلزم
تجميع مسبق للتقارير + قراءة مفصولة + مراجعة views الارتباطية.

## 5. One-Year Survival Test

**ما سينكسر/يبطئ أولًا (بالترتيب):**
1. **البحث** (`LIKE '%q%'`) — أول ما يُلاحَظ.
2. **التقارير واللوحات** (live على views، بلا فهارس/caching) — بطء ثم احتمال timeout.
3. **صفحات القوائم** (جلب كامل بلا pagination) — استهلاك ذاكرة/بطء.
4. **جدول الحضور** (ينمو أسرع الجميع؛ بلا فهرس تاريخ/UNIQUE).
**ما سيصبح خطرًا:** ازدواج الدفع/الحضور مع كثرة العمليات؛ غياب audit عند نزاع مالي.
**ما لن يمثّل مشكلة:** الأمن الأساسي، سلامة FK، الدوال النقية، المخطط.
**الخلاصة:** **يصمد سنة** بأحمال نادٍ واحد **بعد إضافة الفهارس + pagination**؛ بدونها يبدأ
التدهور عند بضعة آلاف.

## 6. Three-Year Vision (نجاح المنتج)

عند فروع متعدّدة + آلاف الأعضاء + موبايل + API + بيانات تاريخية ضخمة، **عنق المعمار الحالي:**
1. **Single-tenant / single DB** — لا فصل فروع/منشآت.
2. **بلا API layer** — الموبايل يحتاج فصل منطق عن الصفحات.
3. **منطق أعمال في triggers + صفحات** — يصعب مشاركته عبر قنوات (web/mobile/API).
4. **تقارير live** — تحتاج مستودع بيانات/تجميع مسبق.
5. **single server/storage/backup** — يحتاج تكرار/off-site/CDN للملفات.
**STRATEGIC OPINION:** الوصول لهذه المرحلة يتطلّب **استخراج طبقة خدمات + API + tenant_id**
تدريجيًا (18–36 شهرًا)، لا rewrite دفعة واحدة.

## 7. Readiness Scores

### API Readiness: **40/100**
- ضدّه: page-coupled، استجابة HTML، تفويض بجلسة كوكي، منطق في الصفحات/triggers.
- معه: `training.php` نقي، مخطط منظّم، تفويض/نطاق منطقي قابل لإعادة الاستخدام.
- **يلزم:** طبقة خدمات + مصادقة رموز + تنسيق JSON. ممكن تدريجيًا بلا rewrite.

### Mobile Backend Readiness: **38/100**
- تابع لـAPI (لا API = لا موبايل). المصادقة كوكي غير مناسبة للموبايل → تحتاج tokens.

### SaaS / Multi-Tenant Readiness: **25/100**
- **Blockers (FACT):** لا `tenant_id/organization_id/branch_id` على أي جدول؛ لا كيان
  organizations/branches؛ لا نطاق مستأجر في الاستعلامات/الviews/triggers.
- **INFERENCE:** الإضافة ممكنة (المخطط منظّم) لكنها **واسعة وعالية الخطر** (كل جدول +
  كل استعلام + كل view + كل trigger). ليست كارثية، لكنها مشروع كبير.

## 8. Failure Modes & SPOF (RISK)

**SPOF (FACT):** خادم/قاعدة واحدة · تخزين ملفات محلي · **نسخ احتياطية محلية بلا off-site**.
| Subsystem | Failure → Impact | Recovery |
|-----------|------------------|----------|
| DB down | النظام كامل يتوقّف (fail-closed) | إعادة تشغيل/استعادة |
| Server loss | فقد التطبيق + الملفات + النسخ المحلية | **لا تعافٍ off-site** ⚠️ |
| uploads فقد | صور التقدّم تضيع (**قد لا تُنسخ في backup.sh**) | — |
| Report overload | بطء/timeout للوحات | caching/فهارس |

## 9. Target Architecture (تصوّر — بلا تنفيذ)

```
Presentation (صفحات/Blade/‏API responses)
        ↓
Application / Services (Actions: Payment, Subscription, Attendance, Assessment…)
        ↓
Domain / Business Logic (كيانات + قواعد + آلة حالة العضو/العضوية)  ← نقل منطق triggers هنا
        ↓
Infrastructure / Database (Repositories فوق PDO/Eloquent) + File Storage + Audit + Cache
```
موديولات: Authentication · Authorization · Members · Memberships · Payments · Attendance ·
Coaching · Assessments · Workouts · Nutrition · Reporting · Audit · API.
عناصر أفقية: Audit trail، Observability، Rate-limiter مركزي، Cache/precompute للتقارير،
`tenant_id` عند الحاجة.

## 10. Migration Strategy (Strangler-Fig — اقتراح فقط)

1. **شبكة اختبارات** حول التدفّقات الحرِجة (تمكين آمن لأي تغيير).
2. **استخراج طبقة خدمات** من الدوال النقية (`training.php`) ثم المسارات المالية.
3. **إدخال API** للخدمات المستخرجة (يخدم موبايل مستقبلًا) دون لمس الصفحات.
4. **نقل منطق triggers** إلى خدمات مجال بحذر (سلوك مزدوج مؤقّت ثم إيقاف الـtrigger).
5. **tenant_id** عند وجود طلب فروع/SaaS.
6. **Laravel** كطبقة أمامية تدريجية (اختياري) بعد استقرار الخدمات.

**STRATEGIC OPINION (Confidence 85%):** لا rewrite. المعمار الحالي **قاعدة صالحة للتطوّر
التدريجي**؛ العائد الأعلى = طبقة خدمات + اختبارات + API لاحقًا، لا إعادة بناء.
