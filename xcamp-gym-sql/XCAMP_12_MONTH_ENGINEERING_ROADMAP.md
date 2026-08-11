# XCAMP_12_MONTH_ENGINEERING_ROADMAP.md

READ-ONLY — خطّة، **بلا تنفيذ**. مبنية على أدلّة `XCAMP_DEEP_SYSTEM_AUDIT.md` و
`XCAMP_RISK_REGISTER.md`. المبدأ: **لا كل شيء أولوية** — عائد استثمار قبل ضجيج.

نطاق البداية: `d8bf910` (يتضمّن إصلاح BIZ-001، بانتظار الدمج).

---

## 90-Day Plan

### 0–30 يومًا — «المال والأمان والشبكة» (Must)
| البند | Priority | Dependency | Change-Risk | العائد المتوقّع |
|-------|:---:|-----------|:---:|-----------------|
| دمج إصلاح **BIZ-001** (اتّساق الإيراد) | P0 | لا | LOW | أرقام مالية موثوقة |
| **idempotency للدفع** (منع الازدواج) | P0 | لا | MEDIUM | لا تضخيم مالي |
| **شبكة اختبارات** لـ`training.php` + مسار الدفع/التفويض | P0 | لا | LOW | أمان أي تغيير لاحق |
| **حزمة فهارس** (status/تواريخ) | P1 | migration إضافي | LOW | أداء فوري ومستقبلي |
| تأكيد `APP_DEBUG` مُطفأ + `logrotate` للسجلّ | P1 | لا | LOW | لا تسريب/انضباط سجلّ |

### 31–60 يومًا — «الصلابة والاتّساق» (Should)
| البند | Priority | Dependency | Change-Risk | العائد |
|-------|:---:|-----------|:---:|--------|
| **UNIQUE(member,date)** للحضور (بعد تنظيف تكرارات) | P1 | فحص تكرارات | MEDIUM | إغلاق سباق الحضور |
| **audit trail أعمال** (توسيع `app_log`: دفع/اشتراك/حذف/دور) | P1 | لا | LOW | تدقيق/امتثال |
| **نسخ off-site مشفّرة + تضمين `uploads/` في النسخ** | P1 | تخزين خارجي | LOW | لا فقد بيانات (SPOF) |
| **pagination** لصفحات القوائم الكبيرة | P1 | لا | MEDIUM | صمود عند النمو |
| قيد/تحقّق اتّساق حالة العضو/العضوية (أو توثيق الـevent) | P1 | فهم event | MEDIUM | لا حالات غير منطقية |

### 61–90 يومًا — «الرؤية والأداء» (Should/Debt)
| البند | Priority | Dependency | Change-Risk | العائد |
|-------|:---:|-----------|:---:|--------|
| **Observability أساسي** (سجلّ أخطاء/بطء + تنبيه بسيط) | P1 | لا | LOW | كشف مبكر للأعطال |
| **caching/precompute** لأثقل التقارير + مراجعة views الارتباطية | P2 | فهارس | MEDIUM | لوحات سريعة |
| بحث مفهرس (بديل `LIKE '%q%'` عند الحاجة) | P2 | فهارس | LOW | بحث سريع |
| قرار **audit_logs** (تفعيل كسجلّ أعمال أو إزالة) | P2 | audit trail | LOW | إزالة دَين |

---

## 12-Month Roadmap (بحسب المحور — ليس كل شيء أولوية)

### Security
- Q1: idempotency الدفع، تأكيد إطفاء APP_DEBUG، دمج تضييق CSP (#40 → Stage 2 nonces لاحقًا).
- Q2–Q4: timeout الجلسة، rate-limiter مركزي (عند تعدّد الخوادم)، مراجعة دورية.

### Reliability
- Q1: نسخ off-site مشفّرة + uploads، اختبار restore دوري، RPO/RTO مُعرَّفان.
- Q2: Observability (أخطاء/بطء/تنبيه)، migrations بإصدارات + rollback.

### Architecture
- Q2–Q3: استخراج **طبقة خدمات** من الدوال النقية ثم المسارات المالية (strangler-fig).
- Q3–Q4: تفكيك `captains.php` تدريجيًا (بعد الاختبارات)، دالة نطاق تفويض مشتركة.

### Data
- Q1: فهارس + UNIQUE حضور. Q2: audit trail أعمال + historical للحالة/المال.
- Q3: مراجعة datatypes/constraints، تنظيف جودة البيانات (READ-then-fix محكوم).

### Performance
- Q1: فهارس + pagination. Q3: caching/precompute للتقارير، مراجعة views الارتباطية.

### Coaching (تمايز المنتج — استثمار قيمة)
- Q2–Q4: توسيع بوابة العضو (اتجاهات 1RM/تغذية/حضور)، إشعارات حقيقية (WhatsApp/SMS) فوق
  `messages_log` مع طابور وإعادة محاولة.

### API
- Q3–Q4: REST API للخدمات المستخرجة (مصادقة رموز)، دون لمس الصفحات.

### Mobile
- Q4+: بعد استقرار API — تطبيق مدرّب/عضو.

### SaaS / Multi-tenancy
- **مؤجّل** حتى وجود طلب تجاري مثبت. عند البدء: `tenant_id` + نطاق إجباري + كيان
  organizations/branches (مشروع كبير، Q-متقدّمة).

---

## قواعد تنفيذ حاكمة
1. **لا refactor قبل شبكة اختبارات** (خاصةً captains.php / triggers).
2. **قِس قبل ما تُحسّن** (فعّل قياسًا قبل تحسين الأداء).
3. **المال أولًا** (اتّساق + idempotency + audit) قبل الميزات الجديدة.
4. **لا rewrite / لا Laravel / لا multi-tenancy الآن** — تطوّر تدريجي.
5. كل بند يمرّ بـ Change-Risk assessment قبل التنفيذ.

## Definition of "Ready for Growth" (نهاية 90 يومًا)
- [ ] BIZ-001 مدموج + idempotency دفع.
- [ ] اختبارات للتدفّقات الحرِجة.
- [ ] فهارس + UNIQUE حضور + pagination.
- [ ] audit trail أعمال + نسخ off-site (تشمل uploads).
- [ ] observability أساسي + APP_DEBUG مُطفأ.

بهذه، XCAMP يتحوّل من **Early Production** إلى **Production** حقيقي يصمد سنة والنمو 10x.
