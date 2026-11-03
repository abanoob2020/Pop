# سياسة الأمان — XCAMP GYM

## الإبلاغ عن ثغرة

أبلِغ عن أي ثغرة أمنية بشكل خاص لمشرف المشروع (لا تفتح Issue عامًّا يكشف التفاصيل).
ضمِّن: الوصف، خطوات إعادة الإنتاج، والأثر المحتمل. سنردّ ونعالج حسب الخطورة.

## معمارية الأمان (نظرة عامة)

- **المصادقة:** مساران منفصلان — طاقم (`users` / `login.php`) وعضو (`member_auth` /
  `member_login.php`)، كلاهما bcrypt (`password_hash`/`password_verify`).
  `session_regenerate_id(true)` بعد الدخول؛ فلتر `is_active`؛ تقييد محاولات
  (لكل IP+بريد ولكل IP)؛ رسائل خطأ عامة لا تكشف وجود الحساب.
- **الجلسة:** كوكي `HttpOnly` + `SameSite=Lax` + `Secure` تلقائيًا تحت HTTPS.
  معرّف الجلسة لا يُوضع في URL/سجلّات/HTML.
- **التفويض (RBAC):** أدوار `admin/manager/reception/coach` + عضو البوابة.
  الصفحات الحسّاسة تُحمى خادِميًا بـ`require_role()` (لا بإخفاء الروابط). نطاق الكابتن
  على `coach_id`، وأفعال التعديل تتحقّق من ملكية العضو (`member_allowed`) — حماية IDOR.
- **CSRF:** رمز `random_bytes(32)` مربوط بالجلسة، تحقّق `hash_equals` عبر `csrf_check()`
  في كل POST؛ الرمز لا يُوضع في URL.
- **إدخال المستخدم:** لا يُفترض آمنًا لأي مستخدم. استعلامات مُجهَّزة (Prepared) في كل مكان
  مع `EMULATE_PREPARES=false`؛ المُعرّفات الديناميكية عبر allowlist أو `(int)`.
- **الإخراج (XSS):** يمرّ عبر `h()` (`htmlspecialchars` + `ENT_QUOTES|ENT_SUBSTITUTE`).
- **رفع الملفات:** صور فقط — تحقّق MIME بالمحتوى (`finfo`) + allowlist + `getimagesize`
  + امتداد مفروض خادِميًا + اسم عشوائي + حد حجم + منع تنفيذ في المجلد (`.htaccess`).
- **الأسرار:** من البيئة فقط (لا افتراضات في الشيفرة؛ فشل مُغلق عند غياب `DB_PASS`).
- **الأخطاء:** تُخفى تفاصيلها في الإنتاج (رسائل عامة) وتُسجّل داخليًا؛ التفصيل خلف
  `APP_DEBUG=1` فقط.
- **السجلّ:** أحداث أمنية إلى `logs/security.log` بحجب الحقول الحسّاسة.
- **الترويسات:** CSP، X-Frame-Options، nosniff، Referrer-Policy، Permissions-Policy،
  وHSTS تحت HTTPS.

## أساسيات الاستجابة للحوادث

1. **احتواء:** عطّل الحساب المتأثّر (`is_active=0`) أو أوقف الخدمة عند الحاجة.
2. **تدوير الأسرار:** غيّر `DB_PASS` وأي مفاتيح؛ اعتبر أي سرّ سبق تسريبه مُخترَقًا.
3. **تحقيق:** راجع `logs/security.log` وسجلّات الخادم (دخول/رفض صلاحية/أخطاء).
4. **استعادة:** من نسخة نظيفة (`restore.sh`) إن لزم.
5. **توثيق:** سجّل السبب الجذري والإجراء التصحيحي.

## قفل بيانات الدخول المسرّبة (xcamp)

كلمات المرور التجريبية (`admin123` / `manager123` / `coach123` / `member123`) وأمثلة
قديمة منشورة في المستودع وتسرّبت في محادثات سابقة، لذا تُعامل كمُخترَقة
(`dashboard/compromised.php`):

- **الدخول مقفول بها** (موظّفين + أعضاء) حتى لو طابقت الهاش — إلا في تطوير محلي
  صريح `APP_DEBUG=1`. كل محاولة تُسجّل `login_blocked_compromised_password`.
- **ممنوع اختيارها** كلمةً جديدة في `account.php` و`captains.php` و`provision_admin.php`.
- أضف أي كلمة مرور تسرّبت لاحقًا عبر `COMPROMISED_PASSWORDS` (فواصل) أو
  `COMPROMISED_PASSWORDS_FILE` (سطر لكل كلمة، `chmod 600`) — لا تضعها في Git.
- فكّ القفل عن حساب: `php provision_admin.php --email=… --pass='<كلمة جديدة قوية>'`.

### أسرار خارجية يجب تدويرها يدويًا (خارج هذا المستودع)

لا يحتوي المستودع (ولا تاريخ Git) على مفاتيح Claude/ElevenLabs/تيليجرام، لكنها تسرّبت
على جهاز جهة العمل وفي محادثات، فتُلغى من لوحة كل مزوّد ثم تُنشأ بديلة في البيئة فقط:

| السر | أين يُلغى |
|---|---|
| Claude API key | console.anthropic.com → API Keys → Delete، ثم مراجعة Usage |
| ElevenLabs API key | elevenlabs.io → Profile/API Keys → Regenerate |
| Telegram bot token | `@BotFather` → `/revoke` (ثم `/token`)، وراجع `getWebhookInfo` |
| xcamp logins + `DB_PASS` | `provision_admin.php` لكل حساب + `ALTER USER … IDENTIFIED BY …` |

## المراجع

- تقرير المراجعة الكامل: `docs/security-audit.md`
- دليل النشر الآمن + قائمة التحقّق: `docs/deployment-security.md`
- قرارات معمارية مؤجّلة: `FUTURE_ARCHITECTURE.md`
