# Xcamp Gym Database

قاعدة بيانات MySQL جاهزة لإدارة نادي رياضي، تشمل الجداول الأساسية، الإجراءات
المخزنة، الـ triggers، الـ events، الـ views، وبيانات اختبارية جاهزة للتشغيل.

## Overview

هذا المشروع مصمم ليكون قابلًا للتشغيل مباشرة بعد النسخ، مع فصل المنطق إلى ملفات
SQL مستقلة لتسهيل التطوير المستقبلي والصيانة. يعتمد التصميم على:

- Schema واضح ومنظم.
- Stored Procedures للمنطق التشغيلي.
- Triggers للأتمتة الخفيفة (تستدعي الإجراءات المخزنة).
- Events للمهام المجدولة.
- Views للتقارير ولوحات التحكم.
- Seed Data ثابتة للاختبار (معرّفات ثابتة لتثبيت قابل للتكرار).
- Test Queries للتحقق السريع من النتائج.

## Features

- إدارة الأعضاء والمدربين والخطط.
- تتبع الاشتراكات والمدفوعات.
- تسجيل الحضور اليومي.
- تقييمات ومتابعات ومؤشرات خطر.
- سجلات التقدم البدني والتمارين والتغذية.
- Alerts و Tasks و Retention Flags.
- Dashboard views جاهزة للإدارة.

## Project Structure

```text
xcamp-gym-sql/
├─ sql/
│  ├─ 00_init.sql          # إنشاء القاعدة وضبط الجلسة
│  ├─ 01_tables.sql        # الجداول (20 جدولًا)
│  ├─ 02_procedures.sql    # الإجراءات المخزنة
│  ├─ 03_triggers.sql      # الـ triggers
│  ├─ 04_events.sql        # الـ events المجدولة
│  ├─ 05_views.sql         # الـ views والتقارير
│  ├─ 06_seed_data.sql     # بيانات اختبارية بمعرّفات ثابتة
│  └─ 07_test_queries.sql  # استعلامات تحقق للقراءة فقط
├─ deploy.sh               # نشر الملفات بالترتيب عبر TCP
├─ reset_and_deploy.sh     # حذف القاعدة وإعادة إنشائها ثم النشر
├─ run_all.sql             # تشغيل كل الملفات داخل جلسة mysql واحدة
├─ logs/                   # سجلّات النشر (تُنشأ وقت التشغيل)
└─ README.md
```

## Requirements

- MySQL 8.0 أو أحدث.
- Bash shell لتشغيل سكربتات النشر.
- صلاحيات كافية لإنشاء قاعدة البيانات والجداول والـ routines والـ events
  (بما في ذلك صلاحية `SET GLOBAL event_scheduler` التي يستخدمها `04_events.sql`).

## Installation

### Option 1: Bash deployment

1. تأكد أن جميع ملفات SQL موجودة داخل مجلد `sql/`.
2. امنح سكربتات bash صلاحية التنفيذ:

   ```bash
   chmod +x deploy.sh reset_and_deploy.sh
   ```

3. شغّل النشر:

   ```bash
   DB_USER=root DB_PASS='your_password' ./deploy.sh
   ```

### Option 2: Full reset and deploy

إذا أردت إعادة بناء القاعدة من الصفر (حذف ثم إعادة إنشاء ثم نشر):

```bash
DB_USER=root DB_PASS='your_password' ./reset_and_deploy.sh
```

### Option 3: mysql client (run_all.sql)

من داخل مجلد المشروع (حتى تُحل مسارات `SOURCE` النسبية):

```bash
cd xcamp-gym-sql
mysql -u root -p < run_all.sql
# أو من داخل صدفة mysql:
#   SOURCE run_all.sql;
```

## Configuration

تُضبط سكربتات النشر عبر متغيرات البيئة (مع القيم الافتراضية):

| المتغير   | الافتراضي     | الوصف                     |
| --------- | ------------- | ------------------------- |
| `DB_HOST` | `127.0.0.1`   | مضيف الخادم               |
| `DB_PORT` | `3306`        | منفذ الاتصال              |
| `DB_USER` | `root`        | اسم المستخدم              |
| `DB_PASS` | *(فارغ)*      | كلمة المرور               |
| `DB_NAME` | `xcamp_gym`   | اسم قاعدة البيانات        |

## Execution order

يُشغّل النشر الملفات بالترتيب: `00 → 01 → 02 → 03 → 04 → 05 → 06 → 07`.

`06_seed_data.sql` عبارة عن بيانات صرفة؛ يقوم المُشغّل (`run_all.sql` أو
`deploy.sh`) بضبط `@seeding=1` حول تحميل البيانات فقط، بحيث تتخطّى الـ triggers
تنفيذها أثناء إدخال البيانات ذات المعرّفات الثابتة، ثم تعود للعمل طبيعيًا وقت
التشغيل الفعلي.

## Maintenance & Development

- كل ملف قابل لإعادة التشغيل: الكائنات تُحذف ثم تُنشأ (`DROP ... IF EXISTS`).
- لتعديل المخطط أو المنطق، عدّل الملف المناسب داخل `sql/` وأعد النشر.
- لإعادة تهيئة كاملة أثناء التطوير، استخدم `reset_and_deploy.sh`.
- سجلّات النشر في `logs/deploy.log`.

## ملاحظات مهمة

- `deploy.sh` يتأكد أولًا من وجود كل ملفات SQL قبل التنفيذ.
- `reset_and_deploy.sh` يحذف القاعدة ويعيد إنشاءها ثم يستدعي `deploy.sh`.
- إن لم ترد ألوانًا، استخدم `NO_COLOR=1`.
- إن لم ترد كلمة المرور في الأمر، استخدم `mysql_config_editor` أو ملف إعدادات
  محلي (مثل `~/.my.cnf`).
- فعّل الـ event scheduler عند الحاجة لتشغيل الـ events.
