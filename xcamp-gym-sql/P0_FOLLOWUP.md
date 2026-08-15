# P0_FOLLOWUP.md — Out-of-Scope Findings (وُثّقت، لم تُصلَح)

اكتُشفت أثناء تنفيذ P0. **لم تُعالَج** (خارج نطاق P0 المقفل). للمراجعة والجدولة لاحقًا.

## يجب النظر فيها قريبًا (P1)
1. **POS duplicate protection (pos_sales):** مسار `pos_checkout` في `finance.php` يُنشئ
   `pos_sales` بلا مفتاح idempotency ⇒ نفس خطر الازدواج (نقر مزدوج/تحديث). لم يُعالَج (P0-B
   ركّز على دفتر `payments`/الإيراد). *الحل المقترح:* نفس نمط idempotency على pos_sales.
2. **Historical audit trail:** انتقالات `members.status` / `memberships.payment_status /
   renewal_status` و الحذف = update-in-place بلا تاريخ (من/متى/قبل/بعد). `audit_logs` جاهز
   وغير مفعّل. (RISK تدقيق مالي.)
3. **State-machine consistency:** لا قيد يمنع «عضو نشِط + عضوية منتهية». حقول حالة لا آلة
   حالة. يحتاج قواعد اتّساق (أو توثيق سلوك الـevent).
4. **Backup off-site/تشفير + تضمين `uploads/`:** `backup.sh` يحفظ القاعدة محليًا فقط بلا
   تشفير/off-site، وقد لا يشمل صور `uploads/` ⇒ خطر فقد (SPOF).

## تحسينات أداء مؤجّلة (P2)
5. **بحث الأعضاء `LIKE '%q%'` (captains.php:471):** لا يستفيد من فهرس B-Tree (بادئة عامّة).
   يلزم **FULLTEXT** أو بحث مخصّص. (رُفض فهرس عادي عمدًا في P0-D.)
6. **payments(status) / payments(payment_date):** مؤجّلان — الأول منخفض الانتقائية
   ('paid' غالبة)، والثاني يتطلّب إعادة صياغة استعلام الدخل (`YEAR()/MONTH()` غير sargable
   → استبدالها بمدى تواريخ) قبل أن يفيد الفهرس.
7. **Pagination:** صفحات القوائم تجلب الجداول كاملة (لا LIMIT) — تدهور عند النمو.
8. **تقارير live بلا caching/precompute** — أول عنق عند الحجم.

## قرارات عمل مؤجّلة
9. **الحضور اليدوي (captains) عند التكرار = no-op:** لا يدمج `check_out_time` لصفّ موجود.
   دمج check-out يحتاج قرار عمل (`ON DUPLICATE KEY UPDATE check_out_time=VALUES(...)`؟).
10. **دفعة أكبر/أقل من سعر الخطة:** لا فحص `amount==price` (جزئي يُعلّم paid). قرار عمل.
11. **Refund:** `'refunded'` تسمية حالة بلا حركة مال مسجّلة (لا دفعة سالبة). فجوة محاسبية.
12. **«محصّل» في revenue = دفعات العضويات فقط** (يستبعد POS) — مقصود (revenue خاص
   بالعضويات) لكن يُنبَّه أن دخل النادي الكامل في `finance.php` (عضويات + POS).

## مبدأ
كل ما سبق **موثّق فقط**. لا تُصلَح ضمن P0. تُجدوَل عبر
`XCAMP_12_MONTH_ENGINEERING_ROADMAP.md` بعد قبول P0.
