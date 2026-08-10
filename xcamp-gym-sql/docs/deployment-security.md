# دليل النشر الآمن — XCAMP GYM

## 1. متغيّرات البيئة (لا أسرار في الشيفرة)

التطبيق وسكربتات النشر تقرأ الإعداد من البيئة فقط:

| المتغيّر | الوصف |
|----------|-------|
| `DB_HOST` `DB_PORT` `DB_NAME` | اتصال القاعدة |
| `DB_USER` `DB_PASS` | **مطلوبة** — لا قيمة افتراضية في الشيفرة (فشل مُغلق) |
| `APP_DEBUG` | `1` يُظهر تفاصيل الأخطاء (تطوير فقط). الإنتاج: اتركه غير مضبوط |
| `APP_LOG_DIR` | مجلّد السجلّات (افتراضي `xcamp-gym-sql/logs`) |
| `SESSION_SECURE` | `1` لفرض كوكي Secure خلف بروكسي TLS |

مرّرها عبر مدير الخدمة (systemd `Environment=`/`EnvironmentFile=`) أو إعداد PHP-FPM
pool (`env[DB_PASS]=…`)، بملف `chmod 600`. لا تضعها في الشيفرة أو Git.

## 2. أقل امتياز لقاعدة البيانات (SEC-005)

**افصل حساب النشر عن حساب التشغيل:**

- **النشر (مرّة/عند الترقية):** حساب إداري يملك DDL (`CREATE/ALTER/DROP`, `TRIGGER`,
  `EVENT`, `CREATE ROUTINE`) لتشغيل `deploy.sh`.
- **التشغيل (التطبيق):** حساب محدود — DML + تنفيذ الإجراءات فقط:

```sql
CREATE USER 'xcamp_app'@'127.0.0.1' IDENTIFIED BY 'STRONG_UNIQUE_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON xcamp_gym.* TO 'xcamp_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

لا تمنح `GRANT ALL` ولا `FILE`/`SUPER` لحساب التطبيق. اضبط `DB_USER=xcamp_app` للتطبيق.

## 3. المدير الأول والحسابات

النشر النظيف (`DB_SEED=0`) يبدأ بلا مستخدمين. جهّز المدير عبر CLI (يرفض HTTP):

```bash
cd dashboard
DB_USER=xcamp_app DB_PASS='…' php provision_admin.php \
  --email=admin@yourgym.com --pass='Change-Me-Now!' --name='المدير'
```

كلمات المرور تُخزَّن bcrypt. غيّر أي كلمات تجريبية قبل الإنتاج.

## 4. HTTPS والكوكيز

- شغّل خلف Apache/nginx + PHP-FPM (لا `php -S` في الإنتاج).
- الجلسة مُقوّاة: `HttpOnly` دائمًا، `SameSite=Lax`، و`Secure` تلقائيًا تحت HTTPS
  (أو `SESSION_SECURE=1` خلف بروكسي TLS).
- `Strict-Transport-Security` تُرسَل تلقائيًا **فقط** عند HTTPS. لا تفعّل HSTS قبل التأكد
  أن HTTPS يعمل على كل النطاق.
- أعِد توجيه HTTP→HTTPS على مستوى الخادم.

## 5. منع تنفيذ السكربتات في مجلد المرفوعات (SEC-007)

Apache: ملف `dashboard/uploads/.htaccess` مضمّن (يتطلب `AllowOverride`).
nginx: أضِف —

```nginx
location ^~ /uploads/ {
    location ~ \.(php|phar|phtml|pl|py|cgi|sh)$ { deny all; return 403; }
}
```

الأفضل مستقبلًا: تخزين المرفوعات خارج جذر الويب وخدمتها عبر سكربت وسيط.

## 6. الترويسات الأمنية

تُبعث مركزيًا من `db.php`: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: same-origin`, `Permissions-Policy`, و`Content-Security-Policy`
(أصول ذاتية، بلا `'unsafe-eval'`). CSP تُبقي `'unsafe-inline'` مؤقتًا (ضروري: معالجات/أنماط
inline).

**تضييق CSP تدريجيًا (قياس قبل الفرض):** فعّل `CSP_REPORT_ONLY=1` في بيئة اختبار ليُبثّ
رأس `Content-Security-Policy-Report-Only` بسياسة أصرم (بلا `unsafe-inline` للسكربت) دون
منع أي شيء؛ تُجمع المخالفات في `logs/security.log` عبر `csp_report.php`. لا تفعّله في
الإنتاج كإجراء دائم. الخطة الكاملة والمراحل: `docs/csp-plan.md`.

## 7. السجلّات والنسخ الاحتياطي

- **السجلّ الأمني:** `logs/security.log` (JSON، بلا أسرار). اضبط `logrotate` للتدوير،
  ولا تُتِح المجلّد عبر الويب.
- **النسخ الاحتياطي:** `backup.sh`/`restore.sh` + `backup.cron.example` (راجع README).

## 8. قائمة تحقّق ما قبل الإنتاج

- [ ] `DB_PASS` مضبوط (وحساب `xcamp_app` محدود الصلاحيات).
- [ ] `APP_DEBUG` غير مضبوط.
- [ ] HTTPS يعمل + إعادة توجيه HTTP→HTTPS.
- [ ] كل كلمات المرور التجريبية غُيّرت أو نُشِر نظيفًا (`DB_SEED=0`).
- [ ] مجلّدا `uploads/` و`logs/` غير قابلين للتنفيذ/التصفّح عبر الويب.
- [ ] نسخ احتياطي مجدول + استعادة مُختبَرة.
- [ ] `logrotate` للسجلّات.
