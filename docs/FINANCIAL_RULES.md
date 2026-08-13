# Financial Rules

> All formulas read directly from `dashboard/training.php` (pure functions), `dashboard/revenue.php`, `dashboard/finance.php`, `dashboard/hr.php`. `[VERIFIED]`
> Currency is Egyptian pounds (ج.م) per UI labels. `[VERIFIED — finance.php]`

## 1. Revenue analytics (`revenue.php` + helpers in `training.php`)

### MRR — Monthly Recurring Revenue
`monthlyize(price, durationDays)` = `round(price / durationDays * 30)` (if duration ≤ 0 → `round(price)`). `[VERIFIED — training.php:720]`
MRR = Σ `monthlyize(plan.price, plan.duration_days)` over **current** memberships, where *current* = `days_left ≥ 0 AND renewal_status ≠ 'cancelled'`. `[VERIFIED — revenue.php:64,68–69]`

### Active contract value
`activeValue` = Σ `plan.price` over current memberships (full sticker price, not monthlyized). `[VERIFIED — revenue.php:69]`

### Collected
`collected` = Σ `plan.price` where `membership.payment_status = 'paid'`. `[VERIFIED — revenue.php:71]`
> Caveat: this sums the **plan price** of paid memberships, not rows from the `payments` table. The `finance.php` P&L uses the actual `payments.amount` instead (see §3). Two different "collected" definitions coexist. `[CONFLICTING]` → CONFLICTS C-02.

### Outstanding (owed)
`payment_outstanding(price, status)`: unpaid/failed → full `price`; partial → `price × 0.5`; paid/refunded → 0. `[VERIFIED — training.php:739]`
`outstanding` = Σ owed across **all** memberships in scope. `[VERIFIED — revenue.php:70]`

### Projected renewal revenue (30/60/90-day)
`renewal_likelihood(renewalStatus, paymentStatus, autoRenew, daysLeft)`: `[VERIFIED — training.php:729]`
- `renewed` → 1.0; `cancelled` → 0.0.
- base = `autoRenew ? 0.85 : 0.50`.
- payment adjustment: paid +0.10, partial 0, unpaid −0.15, failed −0.35, refunded −0.40.
- if `expired` or `daysLeft < 0`: base −0.25.
- clamped to [0, 1].

Projection per window W ∈ {30,60,90}: add `price × likelihood` when `0 ≤ daysLeft ≤ W`; expired-but-not-renewed/cancelled memberships are added to **all** windows. `[VERIFIED — revenue.php:73–78]`

### Renewal pipeline
Memberships ending within 30 days, or already ended, excluding `renewed`/`cancelled`; sorted by days_left ascending. `[VERIFIED — revenue.php:80]`

## 2. Renewal / payment status edits
`revenue.php` POST `set_renewal` updates `memberships.renewal_status` + `payment_status` (values whitelisted against fixed arrays), CSRF-checked, coach-scoped (a coach may only edit their own members' memberships). The "✓ mark as paid" button posts `payment_status='paid'`. `[VERIFIED — revenue.php:20–31]`

## 3. Accounting & POS (`finance.php`)
- **Payment methods:** cash, card, bank_transfer, wallet, other. `[VERIFIED — finance.php:11]`
- **Product categories:** supplement, drink, apparel, accessory, service, other. `[VERIFIED]`
- **Expense categories:** rent, salaries, equipment, utilities, marketing, maintenance, supplies, other. `[VERIFIED — finance.php:15]`
- **Income (period):** membership income = `SUM(payments.amount) WHERE status='paid'` (+ month filter); POS income = `SUM(pos_sales.total)`. `[VERIFIED — finance.php:113–114]`
- **Expenses (month):** `SUM(expenses.amount)` for current year+month. `[VERIFIED — finance.php:116]`
- **Net profit:** `net_profit(income, expenses)` = `round(income − expenses, 2)`. `[VERIFIED — training.php:762, finance.php:117]`
- POS subscription payments remain in the `payments` table; retail sales go to `pos_sales`/`pos_sale_items`; the two income streams are summed for P&L. `[VERIFIED]`

## 4. Personal-training pricing
`PT_PRICE = 150` (default session price, ج.م), `PT_SLOT_MIN = 60`. `[VERIFIED — db.php:109–110]` PT revenue derives from completed `pt_sessions`. `[VERIFIED — pt.php header]`

## 5. Coach payroll & commission (`hr.php`)
- **Commission base:** `hr_commission_base()` = per coach, `SUM(payments.amount)` for that coach's members in the month. `[VERIFIED — hr.php:21–23]`
- **Commission:** `round(base × commission_rate / 100, 2)`, `commission_rate` clamped 0–100. `[VERIFIED — hr.php:44,67]`
- **Payroll record:** inserts into `coach_payroll (base, commission, total, status, paid_at)`. `[VERIFIED — hr.php:71]`
- **Double-entry side effect:** paying salary also inserts an `expenses` row with category `salaries`, so payroll flows straight into the P&L. `[VERIFIED — hr.php:75–76]`
- Coach `coach_hr` carries `base_salary`, `commission_rate`, `capacity_members` (member-capacity planning). `[VERIFIED — hr.php:39]`

## 6. Discounts / referrals (`promos.php`, `db.php`)
- `discount_evaluate(pdo, code, base, context)` and `discount_redeem(...)` validate and apply discount codes; kinds = percent/fixed, scopes = membership/pos/both. `[VERIFIED — db.php:156,174; promos.php:12]`
- Redemptions logged in `discount_redemptions`. `[VERIFIED — schema]`
- Full validation logic (usage limits, expiry) not line-read here `[UNKNOWN — partial]`.

## 7. Money formatting
`money(v)` formats to the display string used across dashboards. `[VERIFIED — training.php:746]`

## Financial integrity notes
- **Two "collected/income" definitions** (revenue.php plan-price vs finance.php payments.amount) can diverge if a member pays an amount different from sticker price, or pays in installments. `[CONFLICTING]` → CONFLICTS C-02.
- Projected revenue is a **heuristic** (hand-tuned likelihood weights), not a statistical model. Documented as INFERRED business intent, VERIFIED as code. `[VERIFIED code / INFERRED intent]`
