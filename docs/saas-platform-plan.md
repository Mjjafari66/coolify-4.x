# طرح پلتفرم SaaS مبتنی بر Coolify

> تاریخ به‌روزرسانی: ۲۰۲۶-۰۸-۰۵  
> وضعیت: تصمیم‌های استک/استقرار قفل شد + Blueprint اجرایی MVP  
> مخاطب: تیم محصول / فنی

این فایل جمع‌بندی تمام گفتگوها و تصمیم‌های مربوط به ساخت یک پلتفرم ابری شبیه هم‌روش/لیارا با استفاده از Coolify به‌عنوان موتور زیرساخت است.

---

## ۱. خلاصه تصمیم

| موضوع | تصمیم |
|--------|--------|
| موتور زیرساخت | Coolify (همین ریپو / VM فعلی) |
| محصول مشتری‌پسند | اپ جدا (برند خودمان) |
| ارتباط دو سیستم | Coolify API (`/api/v1`) از شبکه داخلی بین VMها |
| تیم فنی | TypeScript |
| استک اپ کسب‌وکار | **Next.js فول‌استک** (App Router + Route Handlers / Server Actions) |
| Express جدا؟ | **خیر** — برای MVP لازم نیست |
| ریپوی کد | **ریپوی جدا** از Coolify (ورک‌اسپیس Cursor می‌تواند مشترک باشد) |
| چندمستأجری | بله — با `organization_id` به‌عنوان tenant (نه لزوماً نام `tenant_id`) |
| استقرار | دو VM روی یک سرور فیزیکی: اپ (عمومی) + Coolify (خصوصی) |
| دسترسی کاربر | فقط به اپ کسب‌وکار از اینترنت |
| دسترسی Coolify | خصوصی/داخلی؛ دامنه عمومی برای پنل خام لازم نیست |
| مدل هدف MVP | دارکوب سبک ایرانی (دیپلوی اپ + دیتابیس + سرویس‌های پرکاربرد) |
| مدل غیرهدف در MVP | Managed Kubernetes، DBaaS با HA/PITR بانکی، Jira/Confluence سازمانی |
| لایسنس Coolify | Apache 2.0 — استفاده تجاری و ارائه به‌عنوان سرویس مجاز است |

**جمله کلیدی:**

> Coolify = موتور دیپلوی پشت صحنه (خصوصی)  
> اپ Next.js = محصول، برند، پرداخت، پلن، UX مشتری (عمومی)

---

## ۲. تاریخچه تصمیم‌ها (از گفتگوها)

### ۲.۱ تبدیل Coolify به SaaS شبیه لیارا

- از نظر فنی و لایسنس امکان‌پذیر است.
- Coolify از قبل دارد: Team multi-tenancy، Stripe (مدل cloud)، دیپلوی، دیتابیس، سرویس، پروکسی، API.
- فاصله تا لیارا/هم‌روش: برندینگ، پرداخت محلی، ایزولاسیون سخت مشتری‌ها، عملیات، SLA.
- Coolify بیشتر self-host است؛ مدل لیارا/هم‌روش اغلب «سرور ما + مشتری فقط اپ می‌سازد» است.

### ۲.۲ معماری پیشنهادی نهایی

به‌جای فورک سنگین UI کول‌آیفای:

1. Coolify را پایدار روی سرور نگه می‌داریم.
2. یک اپ جدا برای کسب‌وکار می‌سازیم.
3. از API کول‌آیفای برای ساخت/دیپلوی/استارت/استاپ استفاده می‌کنیم.
4. فقط وقتی API کافی نبود، کول‌آیفای را فورک/توسعه می‌دهیم.

### ۲.۲.۱ تصمیم‌های قفل‌شده استک و استقرار (به‌روز)

| تصمیم | نتیجه |
|--------|--------|
| تیم TS است | استک اصلی TypeScript |
| بک‌اند Express؟ | لازم نیست؛ Next.js فول‌استک کافی است |
| ریپو | کد اپ در ریپوی جدا؛ این ریپو فقط موتور Coolify |
| ورک‌اسپیس | می‌توان دو ریپو را کنار هم در Cursor باز کرد |
| Tenant | مفهوم tenant لازم است = `organizations.id` / `organization_id` روی همه منابع |
| Tenant در Coolify | در MVP لازم نیست per-customer Team؛ یک Project per org کافی است |
| دو VM روی یک سرور | بله — اپ از داخل شبکه خصوصی API کول‌آیفای را صدا می‌زند |
| دامنه Coolify | فعلاً عمومی نیست و لازم هم نیست؛ فقط دسترسی داخلی/بین VM |
| دامنه اپ | عمومی + HTTPS برای کاربر نهایی |
| کاربر نهایی | فقط اپ را می‌بیند؛ پنل خام Coolify در اینترنت باز نمی‌شود |

### ۲.۳ بررسی هم‌روش (hamravesh.com)

هم‌روش یک ارائه‌دهنده ابری مبتنی بر Kubernetes است. سبد محصول:

#### محصولات اصلی صفحه اصلی
1. **دارکوب (Darkube)** — PaaS روی K8s  
2. **دیتابیس مدیریت‌شده (DBaaS)** — PostgreSQL / MySQL با HA، PITR، replica  
3. **کوبرنتیز مدیریت‌شده اختصاصی** — ابر یا on-prem  
4. **بازارچه ابری** — سرویس مدیریت‌شده + اپ آماده  
5. **هم‌گیت** — GitLab مدیریت‌شده  
6. **گیت‌لب رانر اختصاصی**  
7. **سنتری (Sentry مدیریت‌شده)**  
8. **پشتیبانی سازمانی**  
9. **مانیتورینگ و جمع‌آوری لاگ**

#### محصولات تکمیلی مستندات
- آبجکت استورج S3  
- لودبالانسر اختصاصی  
- شبکه (IP ورودی/خروجی + SSL)  
- کانتینر رجیستری خصوصی  
- بکاپ مدیریت‌شده  
- مشاوره نرم‌افزاری  

#### بازارچه
- مدیریت‌شده: Jira، Confluence، n8n  
- اپ آماده: Grafana، Metabase، Nextcloud، Jupyter، Kibana، Rocket.Chat، Pyroscope، Nginx، Prometheus، Keycloak، Wiki.js، Supabase، Jitsi  

#### داخل دارکوب
- دیتابیس‌ها: PostgreSQL، MySQL، MariaDB، MongoDB، Redis، MSSQL، Elasticsearch، MinIO  
- پیام/سرچ: Kafka، RabbitMQ، NATS، Typesense، ClickHouse، …  
- قالب‌ها: Django، Laravel، Node، React، Kong، SonarQube، …

منبع: [hamravesh.com](https://hamravesh.com/) و [docs.hamravesh.com](https://docs.hamravesh.com/)

### ۲.۴ مقایسه هم‌روش vs Coolify vs مدل ما

| حوزه | هم‌روش | Coolify | نقش اپ جدا |
|------|--------|---------|------------|
| PaaS / دیپلوی | دارکوب روی K8s | قوی (Docker) | UX + پلن |
| دیتابیس ساده | اپ آماده | قوی + API | قیمت‌گذاری |
| DBaaS حرفه‌ای | قوی | ضعیف‌تر | بعداً |
| Marketplace | محدود ولی پخته | ~۳۳۳ قالب | کاتالوگ برندشده |
| Managed K8s | هست | ندارد | فاز ۳ |
| GitLab / Runner | هست | اتصال Git خارجی | فاز ۲ |
| Sentry | محصول جدا | قابل نصب به‌عنوان سرویس | فاز ۲ |
| S3 | محصول سازمانی | با MinIO قابل ارائه | فاز ۱.۵ |
| صورتحساب ایران | بومی | Stripe پیش‌فرض | **حتماً در اپ ما** |

**نتیجه:** برای شروع کسب‌وکار، Coolify + اپ جدا مسیر درست است؛ MVP = دارکوب سبک، نه کل هم‌روش.

---

## ۳. معماری هدف

### ۳.۱ استقرار فیزیکی / شبکه (تصمیم قطعی)

وضعیت فعلی: Coolify روی یک VM داخل سرور (مثلاً Windows Server / هاست) است و از بیرون اینترنت دامنه عمومی ندارد؛ از داخل در دسترس است. این برای مدل زیر **مشکلی ایجاد نمی‌کند**.

```text
[اینترنت]
        │
        ▼ فقط HTTPS دامنه اپ کسب‌وکار
┌──────────────────────────────────────────────┐
│           سرور فیزیکی (هاست)                  │
│                                              │
│  ┌──────────────────┐   شبکه خصوصی / LAN    ┌──────────────────┐
│  │ VM اپ کسب‌وکار   │ ───────────────────► │ VM کول‌آیفای     │
│  │ Next.js          │   COOLIFY_URL=IP:port │ API /api/v1      │
│  │ دامنه عمومی      │   Bearer token        │ بدون دامنه عمومی │
│  └──────────────────┘                       └────────┬─────────┘
│                                                      │ SSH/Docker
│                                                      ▼
│                                             سرور(های) دیپلوی /
│                                             containers / Traefik
└──────────────────────────────────────────────┘
```

| مسیر | باز است برای |
|------|----------------|
| اینترنت → اپ Next.js | کاربران نهایی |
| اپ VM → Coolify VM (IP خصوصی) | فقط orchestration سمت سرور |
| اینترنت → پنل/API خام Coolify | **بسته** (مگر VPN/ادمین) |

نکات عملی شبکه:
1. فایروال بین دو VM باید پورت API کول‌آیفای را باز کند.
2. `COOLIFY_URL` می‌تواند `http://<IP-خصوصی-coolify>:<port>` باشد؛ دامنه عمومی لازم نیست.
3. توکن API فقط در env سمت سرور Next.js بماند (`server-only`).
4. برای اپ دامنه + HTTPS بگذارید؛ برای Coolify فعلاً نه.

### ۳.۲ معماری نرم‌افزاری

```text
Browser (کاربر)
    → Next.js (UI + Route Handlers)
        → CoolifyClient (server-only)
            → Coolify API (خصوصی)
                → Docker / apps / DBs
```

### ۳.۳ اصول
- مشتری به پنل خام Coolify دسترسی ندارد (مگر ادمین داخلی/VPN).
- هر مشتری = یک **Organization** (`organization_id` = tenant منطقی).
- به‌ازای هر Organization یک **Project** در Coolify نگاشت می‌شود.
- سهمیه منابع در اپ Next.js enforce می‌شود؛ Coolify فقط اجرا می‌کند.
- توکن Coolify فقط سمت سرور Next.js؛ هرگز به مرورگر فرستاده نشود.
- کد اپ در ریپوی جدا از این monorepo Coolify نگهداری می‌شود.

---

## ۴. Blueprint اجرایی MVP

### ۴.۱ هدف MVP

در ۸–۱۲ هفته به این برسیم:

> مشتری ثبت‌نام کند → پلن بخرد → اپ از Git/Image بسازد → دیتابیس بسازد → چند سرویس محبوب بزند → دامنه وصل کند → start/stop/logs ببیند.

خارج از MVP (عمداً نه):
- Managed Kubernetes  
- Jira/Confluence مدیریت‌شده  
- Sentry سازمانی کامل  
- HA/PITR سطح بانکی  

### ۴.۲ استک قطعی اپ کسب‌وکار

تیم TypeScript است → استک قفل شد:

| لایه | تصمیم |
|------|--------|
| فریم‌ورک | **Next.js** (App Router) فول‌استک |
| UI | React در همان Next.js |
| API داخلی محصول | Route Handlers و/یا Server Actions |
| Express جدا | **خیر** — برای MVP لازم نیست |
| ORM / DB اپ | Prisma یا Drizzle + PostgreSQL (پیشنهادی) |
| Auth | Auth.js / راه‌حل معادل در اکوسیستم Next |
| صف (در صورت نیاز بعدی) | BullMQ / Inngest / Trigger.dev — نه از روز اول اجباری |
| پرداخت | درگاه ایرانی (زرین‌پال / مشابه) |
| ارتباط Coolify | `CoolifyClient` فقط سمت سرور → `/api/v1` با token دارای `read`, `write`, `deploy` |

قوانین استک:
- همه کال‌های Coolify فقط در کد `server-only` / Route Handler.
- ریپوی اپ **جدا** از `coolify-4.x` باشد تا آپدیت موتور قاطی محصول نشود.
- در Cursor می‌توان هر دو ریپو را در یک ورک‌اسپیس باز کرد.

### ۴.۳ مدل داده و Tenant

#### مفهوم Tenant

| سوال | جواب |
|------|------|
| آیا tenant لازم است؟ | **بله** |
| نام فیلد | ترجیحاً `organization_id` (لازم نیست حتماً `tenant_id` نام‌گذاری شود) |
| واحد صورتحساب و سهمیه | Organization |
| نگاشت Coolify در MVP | یک Project به‌ازای هر Organization |
| multi-team داخل Coolify per customer | در MVP لازم نیست |

همه جداول منابع و عضویت باید با `organization_id` scope شوند. درخواست‌های API اپ همیشه در context همان org فعال اجرا شوند.

#### جداول اصلی

**users**  
کاربر نهایی مشتری

**organizations**  
سازمان مشتری = tenant؛ واحد صورتحساب و سهمیه

**organization_members** (یا `organization_user`)  
عضویت کاربر در org + نقش (`owner` / `admin` / `member`)

**plans**
- `code` (free, starter, pro)
- `price_monthly`
- `max_apps`, `max_databases`, `max_services`
- `max_cpu_millicores` / `max_memory_mb` / `max_disk_gb`
- `max_team_members`

**subscriptions**
- `organization_id`, `plan_id`
- `status` (active, past_due, canceled)
- `starts_at`, `ends_at`
- `wallet_balance` یا اتصال به سیستم کیف پول

**coolify_links** (نگاشت منابع)
- `organization_id`
- `resource_type` (project, application, database, service)
- `local_uuid` / `id`
- `coolify_uuid`
- `coolify_server_uuid`
- `meta` (json)

**deployable_resources** (نمای محصولی)
- `organization_id`
- `type` (app, database, service, worker)
- `name`, `status`
- `plan_quota_units`
- `coolify_link_id`

**domains**
- `resource_id`
- `hostname`
- `ssl_status`

**payments / invoices**
- تراکنش درگاه، فاکتور رسمی

**support_tickets**
- پشتیبانی سطح ۱ محصول

### ۴.۴ جریان‌های کلیدی MVP

#### A) Onboarding مشتری
1. ثبت‌نام در اپ Next.js  
2. ساخت Organization (tenant) و عضویت کاربر  
3. (اختیاری) پلن رایگان یا شارژ کیف پول  
4. از سرور اپ، روی شبکه خصوصی: `POST /api/v1/projects` برای پروژه اختصاصی همان org  
5. ذخیره `coolify_project_uuid` در `coolify_links` با `organization_id`

#### B) ساخت اپ از Git/Image
1. بررسی سهمیه پلن  
2. `POST /api/v1/applications/public` یا `dockerimage` / `dockerfile` / `private-deploy-key`  
3. set envs: `POST /applications/{uuid}/envs`  
4. deploy: `POST /applications/{uuid}/start`  
5. نمایش دامنه/وضعیت در UI ما

#### C) ساخت دیتابیس
1. بررسی سهمیه  
2. `POST /api/v1/databases/postgresql` (یا mysql/redis/…)  
3. `POST /databases/{uuid}/start`  
4. نمایش connection string به مشتری (با ماسک امن)

#### D) ساخت سرویس از کاتالوگ
1. انتخاب از کاتالوگ MVP  
2. `POST /api/v1/services` با `type` = کلید قالب Coolify  
3. start در صورت نیاز  

#### E) Worker / استارت مجدد
- `POST /applications/{uuid}/start|restart|stop`  
- همین برای databases و services  

#### F) حذف / لغو اشتراک
1. stop منابع  
2. delete در Coolify  
3. پاک‌سازی `coolify_links`  
4. قطع دسترسی UI

### ۴.۵ قرارداد Coolify API برای MVP

Base: `{COOLIFY_URL}/api/v1`  
Auth: `Authorization: Bearer {TOKEN}`  
Abilityهای لازم: `read`, `write`, `deploy`

#### سلامت و نسخه
| Method | Path | کاربرد |
|--------|------|--------|
| GET | `/health` | healthcheck |
| GET | `/version` | نسخه موتور |

#### پروژه / محیط
| Method | Path | کاربرد |
|--------|------|--------|
| GET | `/projects` | لیست |
| POST | `/projects` | ساخت پروژه مشتری |
| GET | `/projects/{uuid}` | جزئیات |
| GET | `/projects/{uuid}/environments` | محیط‌ها |
| POST | `/projects/{uuid}/environments` | ساخت env (مثلاً production) |
| DELETE | `/projects/{uuid}` | حذف |

#### اپلیکیشن
| Method | Path | کاربرد |
|--------|------|--------|
| GET | `/applications` | لیست |
| POST | `/applications/public` | از ریپوی عمومی |
| POST | `/applications/private-deploy-key` | ریپوی خصوصی با deploy key |
| POST | `/applications/private-github-app` | GitHub App |
| POST | `/applications/dockerfile` | Dockerfile |
| POST | `/applications/dockerimage` | Image آماده |
| GET/PATCH/DELETE | `/applications/{uuid}` | مدیریت |
| GET/POST/PATCH/DELETE | `/applications/{uuid}/envs` | متغیرها |
| GET | `/applications/{uuid}/logs` | لاگ |
| GET/POST/PATCH/DELETE | `/applications/{uuid}/storages` | دیسک |
| POST | `/applications/{uuid}/start` | دیپلوی/استارت |
| POST | `/applications/{uuid}/restart` | ریستارت |
| POST | `/applications/{uuid}/stop` | استاپ |

#### دیتابیس
| Method | Path | کاربرد |
|--------|------|--------|
| POST | `/databases/postgresql` | ساخت PG |
| POST | `/databases/mysql` | MySQL |
| POST | `/databases/mariadb` | MariaDB |
| POST | `/databases/mongodb` | MongoDB |
| POST | `/databases/redis` | Redis |
| POST | `/databases/clickhouse` | ClickHouse |
| GET/PATCH/DELETE | `/databases/{uuid}` | مدیریت |
| POST | `/databases/{uuid}/backups` | زمان‌بندی بکاپ |
| POST | `/databases/{uuid}/start\|restart\|stop` | چرخه عمر |

#### سرویس / Marketplace
| Method | Path | کاربرد |
|--------|------|--------|
| GET | `/services` | لیست |
| POST | `/services` | body: `type`, `project_uuid`, `server_uuid`, … |
| GET/PATCH/DELETE | `/services/{uuid}` | مدیریت |
| POST | `/services/{uuid}/start\|restart\|stop` | چرخه عمر |

#### سرور (ادمین پلتفرم، نه مشتری)
| Method | Path | کاربرد |
|--------|------|--------|
| GET | `/servers` | ظرفیت |
| GET | `/servers/{uuid}/resources` | منابع سرور |
| POST | `/servers` | افزودن سرور جدید به استخر |

#### دیپلوی
| Method | Path | کاربرد |
|--------|------|--------|
| POST | `/deploy` | تریگر دیپلوی |
| GET | `/deployments/{uuid}` | وضعیت |
| POST | `/deployments/{uuid}/cancel` | لغو |

### ۴.۶ الگوی نگاشت مشتری → Coolify (پیشنهادی MVP)

برای سادگی و امنیت در MVP:

1. یک (یا چند) **Team توکن Coolify سمت ادمین** برای orchestration  
2. به‌ازای هر Organization مشتری: یک **Project** در Coolify  
3. Environment پیش‌فرض: `production` (بعداً `staging`)  
4. همه app/db/service مشتری داخل همان project  
5. سرور از استخر داخلی انتخاب می‌شود (فعلاً یک سرور؛ بعداً scheduler ظرفیت)

> در فاز بعد می‌توان multi-team واقعی Coolify یا destination جدا برای ایزولاسیون قوی‌تر اضافه کرد.

### ۴.۷ ده سرویس اول برای فروش (کاتالوگ MVP)

اولویت بر اساس تقاضای رایج ایران + وجود قالب در Coolify:

| # | محصول فروش | معادل Coolify / پیاده‌سازی | یادداشت |
|---|------------|------------------------------|---------|
| 1 | اپ سفارشی (Git/Dockerfile/Image) | Applications API | هسته درآمد |
| 2 | PostgreSQL | `databases/postgresql` | ضروری |
| 3 | MySQL / MariaDB | `databases/mysql` یا `mariadb` | ضروری |
| 4 | Redis | `databases/redis` | برای queue/cache |
| 5 | MongoDB | `databases/mongodb` | تقاضای متوسط |
| 6 | MinIO (Object Storage) | service template / compose | جایگزین S3 سبک |
| 7 | n8n | service type `n8n` / `n8n-with-postgresql` | اتوماسیون پرطرفدار |
| 8 | WordPress یا Directus | service templates | محتوای سریع |
| 9 | Umami / Analytics ساده یا Metabase | `metabase` | داشبورد |
| 10 | Chatwoot یا Rocket.Chat | `chatwoot` / `rocketchat` | پشتیبانی/ارتباط |

آماده‌سازی بعدی (فاز ۱.۵، نه روز اول): Nextcloud، Keycloak، Supabase، Grafana.

### ۴.۸ پلن‌های پیشنهادی اولیه

| پلن | قیمت (نمونه) | اپ | DB | سرویس | RAM مجموعی | دیسک |
|-----|---------------|----|----|--------|------------|------|
| Free | ۰ | ۱ | ۱ | ۰ | ۱ GB | ۵ GB |
| Starter | توافقی | ۳ | ۲ | ۲ | ۴ GB | ۲۰ GB |
| Pro | توافقی | ۱۰ | ۵ | ۵ | ۱۶ GB | ۱۰۰ GB |

قوانین:
- overrun → بلاک ساخت منبع جدید (نه لزوماً حذف فوری)  
- عدم پرداخت → stop منابع پس از مهلت  
- دامنه سفارشی از Starter به بالا  

### ۴.۹ امنیت و چندمستأجری (حداقل MVP)

- [ ] توکن Coolify فقط در سرور Next.js (`server-only`)  
- [ ] هر query/mutation با `organization_id` فعلی scope شود  
- [ ] مشتری هرگز UUID خام سرور/کلید SSH نبیند مگر لازم  
- [ ] authorization روی organization در تمام Route Handlerها  
- [ ] rate limit روی عملیات deploy/start  
- [ ] audit log برای create/delete منابع  
- [ ] جداسازی Project کول‌آیفای per organization  
- [ ] فایروال: API کول‌آیفای از اینترنت مستقیم در دسترس نباشد  
- [ ] بکاپ منظم دیتابیس اپ + منابع مشتریان  
- [ ] عدم اجرای عملیات مخرب روی production از کلاینت مرورگر  

### ۴.۱۰ backlog فنی فازبندی‌شده

#### فاز MVP
- [ ] ریپوی جدا Next.js (auth + Organization/tenant + plan)  
- [ ] `CoolifyClient` TypeScript (`server-only`)  
- [ ] شبکه خصوصی VM اپ → VM کول‌آیفای + توکن API  
- [ ] ساخت project per organization  
- [ ] CRUD اپ / DB / سرویس از کاتالوگ ۱۰تایی  
- [ ] start/stop/restart/logs  
- [ ] دامنه عمومی فقط برای اپ کنترل‌پنل  
- [ ] پرداخت و کیف پول  
- [ ] پنل مصرف سهمیه per organization 

#### فاز ۱.۵
- [ ] MinIO به‌عنوان محصول S3  
- [ ] بکاپ/restore قابل فهم برای مشتری  
- [ ] چند سرور + انتخاب مقصد بر اساس ظرفیت  
- [ ] بهبود ایزولاسیون  

#### فاز ۲
- [ ] CI بهتر / GitLab runner  
- [ ] مانیتورینگ و لاگ مشتری‌پسند  
- [ ] Sentry add-on  
- [ ] پشتیبانی سازمانی بسته‌بندی‌شده  

#### فاز ۳
- [ ] مسیر Kubernetes در صورت کشش بازار  
- [ ] DBaaS HA واقعی  
- [ ] محصولات Atlassian-like فقط با تقاضای B2B قطعی  

---

## ۵. کارهای عملی بعدی (همین الان)

ترتیب پیشنهادی اجرا:

1. **توکن API کول‌آیفای** با abilityهای `read` / `write` / `deploy` روی VM فعلی  
2. **تأیید شبکه خصوصی:** از VM اپ (یا موقتاً از هاست) `curl` به `http://<IP-خصوصی-coolify>:<port>/api/v1/health`  
3. **ساخت ریپوی جدا** Next.js برای اپ کسب‌وکار  
4. **پیاده‌سازی `CoolifyClient` (TypeScript)** با متدهای project/app/db/service  
5. **مدل Organization + membership** (`organization_id` به‌عنوان tenant)  
6. **مسیر E2E داخلی:** org تست → project در Coolify → Postgres → dockerimage app → start  
7. **کاتالوگ ۱۰ سرویس** + سهمیه پلن  
8. **دامنه عمومی فقط برای اپ** + HTTPS؛ Coolify خصوصی بماند  
9. **پرداخت** و قفل سهمیه  

### تعریف Done برای هفته اول فنی
- از اپ Next.js (حتی یک Route Handler تست) بتوان با IP خصوصی به API کول‌آیفای برسد.
- یک Postgres و یک اپ نمونه ساخته و استارت شود.
- نگاشت UUIDها با `organization_id` در دیتابیس اپ ذخیره شود.

### ۴.۱۱ اسکلت `CoolifyClient` (TypeScript)

این ماژول را در **ریپوی اپ Next.js** بگذارید (مثلاً `src/lib/coolify/client.ts`) و فقط از سرور import کنید:

```ts
import "server-only";

type Json = Record<string, unknown>;

export class CoolifyClient {
  constructor(
    private readonly baseUrl: string,
    private readonly token: string,
  ) {}

  private url(path: string): string {
    return `${this.baseUrl.replace(/\/$/, "")}/api/v1${path}`;
  }

  private async request<T>(path: string, init?: RequestInit): Promise<T> {
    const res = await fetch(this.url(path), {
      ...init,
      headers: {
        Authorization: `Bearer ${this.token}`,
        Accept: "application/json",
        "Content-Type": "application/json",
        ...(init?.headers ?? {}),
      },
      cache: "no-store",
    });

    if (!res.ok) {
      const body = await res.text();
      throw new Error(`Coolify ${res.status}: ${body}`);
    }

    return res.json() as Promise<T>;
  }

  health() {
    return this.request<Json>("/health");
  }

  createProject(name: string, description?: string) {
    return this.request<Json>("/projects", {
      method: "POST",
      body: JSON.stringify({ name, description }),
    });
  }

  createPostgresql(payload: Json) {
    return this.request<Json>("/databases/postgresql", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  }

  createDockerImageApplication(payload: Json) {
    return this.request<Json>("/applications/dockerimage", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  }

  createService(type: string, payload: Json) {
    return this.request<Json>("/services", {
      method: "POST",
      body: JSON.stringify({ type, ...payload }),
    });
  }

  startApplication(uuid: string) {
    return this.request<Json>(`/applications/${uuid}/start`, { method: "POST" });
  }

  startDatabase(uuid: string) {
    return this.request<Json>(`/databases/${uuid}/start`, { method: "POST" });
  }

  startService(uuid: string) {
    return this.request<Json>(`/services/${uuid}/start`, { method: "POST" });
  }

  applicationLogs(uuid: string) {
    return this.request<Json | string>(`/applications/${uuid}/logs`);
  }

  servers() {
    return this.request<Json[]>("/servers");
  }
}

export const coolify = new CoolifyClient(
  process.env.COOLIFY_URL!,
  process.env.COOLIFY_TOKEN!,
);
```

`.env` پیشنهادی اپ کسب‌وکار (روی VM اپ):

```env
# IP/hostname خصوصی VM کول‌آیفای — دامنه عمومی لازم نیست
COOLIFY_URL=http://10.x.x.x:8000
COOLIFY_TOKEN=xxxxxxxx
COOLIFY_DEFAULT_SERVER_UUID=xxxxxxxx

# دیتابیس خود اپ Next.js
DATABASE_URL=postgresql://...
```

### ۴.۱۲ چک‌لیست هفته ۱ (وضعیت)

| # | کار | وضعیت |
|---|-----|--------|
| 1 | مستندسازی تصمیم‌ها و مقایسه هم‌روش | انجام شد |
| 2 | Blueprint API + مدل داده + کاتالوگ ۱۰تایی | انجام شد |
| 3 | قفل استک Next.js فول‌استک + ریپوی جدا + استقرار دو VM | انجام شد (همین فایل) |
| 4 | قفل tenant = `organization_id` | انجام شد |
| 5 | ساخت توکن API در Coolify (`read`/`write`/`deploy`) | در انتظار اجرا روی سرور |
| 6 | تأیید دسترسی شبکه خصوصی اپ VM → Coolify VM | در انتظار زیرساخت |
| 7 | ایجاد ریپوی جدا Next.js | در انتظار نام/مسیر ریپو |
| 8 | پیاده‌سازی `CoolifyClient` + تست health | بعد از گام ۵–۷ |
| 9 | مسیر E2E: org → project → postgres → app → start | بعد از گام ۸ |

---

## ۶. منابع داخل این ریپو

| مورد | مسیر |
|------|------|
| مسیرهای API کول‌آیفای | `routes/api.php` |
| کنترلر سرویس‌ها | `app/Http/Controllers/Api/ServicesController.php` |
| کنترلر اپ‌ها | `app/Http/Controllers/Api/ApplicationsController.php` |
| کنترلر دیتابیس‌ها | `app/Http/Controllers/Api/DatabasesController.php` |
| قالب‌های one-click (~۳۳۳) | `templates/service-templates-latest.json` |
| مستندات AI پروژه | `.cursor/rules/coolify-ai-docs.mdc`, `CLAUDE.md`, `AGENTS.md` |

مستندات رسمی API کول‌آیفای: https://coolify.io/docs

---

## ۷. تصمیم‌های گرفته‌شده vs باز

### قطعی شده
1. استک اپ: **Next.js فول‌استک** (تیم TS) — بدون Express جدا  
2. کد اپ: **ریپوی جدا** از Coolify  
3. Tenant: **`organization_id`** روی همه منابع محصول  
4. استقرار: **دو VM روی یک سرور** — اپ عمومی، Coolify خصوصی  
5. کاربر فقط به اپ دسترسی دارد؛ API کول‌آیفای از داخل شبکه خصوصی کال می‌شود  
6. دامنه عمومی برای Coolify در MVP لازم نیست  

### هنوز باز
1. نام برند محصول نهایی چیست؟  
2. نام و مسیر ریپوی اپ Next.js؟  
3. آیا مشتری در MVP فقط روی سرورهای ما دیپلوی می‌شود؟ (پیشنهاد پیش‌فرض: **بله**)  
4. استراتژی دامنه پیش‌فرض اپ‌های مشتری: `*.yourplatform.ir`؟  
5. قیمت دقیق پلن‌ها و ارز (تومان / ریال)؟  
6. انتخاب ORM: Prisma یا Drizzle؟  

---

## ۸. پیوند با گفتگوهای قبلی

- بحث SaaS شبیه لیارا و لایسنس Apache 2.0  
- تصمیم معماری: اپ جدا + Coolify API  
- استخراج کامل محصولات هم‌روش  
- مقایسه هم‌روش / Coolify / مدل کسب‌وکار  
- Blueprint اجرایی MVP  
- تصمیم استک TS / Next.js فول‌استک (بدون Express)  
- تصمیم tenant = Organization  
- تصمیم ریپوی جدا + ورک‌اسپیس مشترک اختیاری  
- تصمیم استقرار دو VM: کاربر → فقط اپ؛ اپ → API خصوصی Coolify  

---

*این سند منبع حقیقت داخلی برای مسیر محصول است. با پیشرفت پیاده‌سازی، بخش backlog و تصمیم‌های باز را به‌روز کنید.*
