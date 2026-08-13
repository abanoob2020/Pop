# Authentication & RBAC

> Evidence: `dashboard/db.php`, `dashboard/login.php`, `dashboard/member_login.php`, page headers. `[VERIFIED]`

## 1. Two separate auth realms
| Realm | Login page | Table | Session key | Guard |
|---|---|---|---|---|
| Staff | `login.php` | `users` | `$_SESSION['user']` | `require_login()` / `require_role()` |
| Member | `member_login.php` | `member_auth` | `$_SESSION['member']` | `require_member()` |
`[VERIFIED — db.php:67–106, login.php, portal.php]`

## 2. Staff login flow (`login.php`) `[VERIFIED]`
1. Already-authenticated users redirected (manager→`index.php`, else `captains.php`).
2. `csrf_check()` on POST.
3. **Rate limit:** `RL_MAX=5` failed attempts per `IP|email` within `RL_WINDOW=900s`, stored file-based in `sys_get_temp_dir()/xcamp_login_attempts.json`; window auto-cleaned on load.
4. Lookup: `SELECT ... FROM users WHERE email = ? AND is_active = 1` (prepared).
5. Verify: `password_verify($pass, password_hash)` — **bcrypt**.
6. On success: clear attempts; if role `coach`, resolve `coach_id` from `coaches`; `session_regenerate_id(true)`; store `{uid, name, role, coach_id}`; redirect by role.
7. On failure: increment counter, generic error with remaining-attempts hint.

## 3. Session hardening (`db.php:9–22`) `[VERIFIED]`
- `httponly=true` always.
- `samesite='Lax'`.
- `secure` = auto (detected HTTPS via `HTTPS`, `X-Forwarded-Proto`, or port 443) or forced by `SESSION_SECURE=1`.
- `lifetime=0` (session cookie).
- `session_regenerate_id(true)` on successful login (fixation defense).

## 4. CSRF (`db.php`) `[VERIFIED]`
- `csrf_token()` mints `bin2hex(random_bytes(32))` once per session.
- `csrf_field()` renders the hidden input; `csrf_check()` compares with `hash_equals()` (constant-time) and throws on mismatch.
- Verified present on state-changing POSTs in revenue.php, finance.php, hr.php, checkin.php, recipes.php, etc. `[VERIFIED — sampled]`

## 5. Roles & the `is_manager` grouping
`users.role ENUM('admin','manager','coach','reception')`. `[VERIFIED — 01_tables.sql:38]`
`is_manager()` returns true for **admin, manager, reception** (NOT coach). `[VERIFIED — db.php:81–83]`

> ⚠️ **Design note / risk:** `reception` is grouped with `admin`/`manager` as "management" everywhere — it can reach finance, analytics, HR, promos, and all-member data. This is intentional in code but worth flagging for least-privilege review. `[VERIFIED code / INFERRED as a policy choice]` → SECURITY.

## 6. Permission matrix (page-level) `[VERIFIED — page headers + db.php nav]`
| Page | admin | manager | reception | coach | member |
|---|:--:|:--:|:--:|:--:|:--:|
| index.php (mgmt dashboard) | ✅ | ✅ | ✅ | ↪ redirected to captains | — |
| finance.php | ✅ | ✅ | ✅ | ❌ | — |
| analytics.php | ✅ | ✅ | ✅ | ❌ | — |
| hr.php | ✅ | ✅ | ✅ | ❌ | — |
| promos.php | ✅ | ✅ | ✅ | ❌ | — |
| revenue.php | ✅ | ✅ | ✅ | ✅ (own members) | — |
| crm.php / retention.php | ✅ all | ✅ all | ✅ all | ✅ own members | — |
| captains.php | ✅ browse all | ✅ | ✅ | ✅ own | — |
| assess.php / pt.php / checkin.php / recipes.php / templates.php / calendar.php | ✅ | ✅ | ✅ | ✅ (scoped) | — |
| portal.php / account (member) | — | — | — | — | ✅ own data |

- `require_role([...])` redirects unauthorized staff to `captains.php` (not a hard 403). `[VERIFIED — db.php:78]`
- Nav in `page_head()` hides manager-only links from coaches, but **enforcement is the page guard**, not the nav. `[VERIFIED — db.php:213–229]`

## 7. Coach data scoping `[VERIFIED]`
Coach-scoped pages set `$isCoach = ($me['role']==='coach')` and `$myCoach = (int)$me['coach_id']`, then filter queries by `coach_id` (integer-cast, e.g. `revenue.php:46`, `crm.php`, `retention.php`, `captains.php`, `pt.php`, `assess.php`). Write paths re-check ownership before mutating (e.g. `revenue.php:27` rejects memberships whose member is not the coach's). `[VERIFIED]`

## 8. Member auth
`member_auth` table + `member_login.php` + `require_member()`; portal restricts every query to `$_SESSION['member']['member_id']`. QR check-in tokens minted per member (`member_qr_token()`, `XCG-` + 6 random bytes). `[VERIFIED — db.php:87–98, portal.php]`

## 9. Default seed credentials `[VERIFIED — login.php:88–92]`
The login page **displays** default accounts (`admin@xcamp.com/admin123`, `coach1@xcamp.com/coach123`) created by `setup_logins.sql`. Fine for a demo, a hardening item for production → SECURITY S-02.
