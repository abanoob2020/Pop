# Conflicts (Contradictions in the Evidence)

> Where documentation, comments, naming, and code disagree. Rule applied: **observed code behavior > comments > naming**, but the conflict is recorded rather than silently resolved. No files were modified.

## C-01 — Root `CLAUDE.md` says "greenfield / README-only" — **FALSE** `[CONFLICTING]`
- **Claim:** `CLAUDE.md` states the repo "contains only a README.md and no source code, build tooling, tests, or CI."
- **Reality:** `xcamp-gym-sql/` is a full application — ~7,585 PHP + ~1,987 SQL lines, 29 PHP pages, 48 tables, 11 procedures, 8 triggers, 13 views, ops scripts, merged PRs up to #37.
- **Resolution (observed > doc):** the code is authoritative; `CLAUDE.md` is stale. Recorded here only; **not edited** per task constraints.
- **Impact:** any tool/agent trusting `CLAUDE.md` will mis-plan. Highest-priority correction for maintainers.

## C-02 — Two different "collected / income" definitions `[CONFLICTING]`
- `revenue.php:71` — `collected` = Σ **plan.price** where `membership.payment_status='paid'`.
- `finance.php:113` — membership income = Σ **payments.amount** where `payments.status='paid'`.
- **Divergence:** installment/partial or discounted payments make the two numbers disagree (plan sticker price ≠ actual cash received).
- **Resolution:** both are VERIFIED as written; they answer different questions (contracted value vs cash collected). Neither is "wrong," but they must not be treated as the same KPI. Label them distinctly in any reporting.

## C-03 — `payments.status` vs `memberships.payment_status` ENUM mismatch `[CONFLICTING/minor]`
- `payments.status ENUM('pending','paid','failed','refunded','partial')`.
- `memberships.payment_status ENUM('unpaid','partial','paid','failed','refunded')`.
- `payments` has `pending` but no `unpaid`; `memberships` has `unpaid` but no `pending`.
- **Effect:** `sp_handle_payment_event` maps payment `paid/failed/partial` onto membership status cleanly, but `pending` payments have no membership counterpart and are ignored by the trigger. VERIFIED, low impact, worth noting for reconciliation.

## C-04 — `coaches` table vs `users.role='coach'` duality `[CONFLICTING/design]`
- Coach identity lives in **two** places: `users` (role `coach`, for login) and `coaches` (for assignment/FKs/views). Linked by `coaches.user_id`.
- Login resolves `coach_id` from `coaches WHERE user_id=?` (login.php:56–59). If a coach user has no `coaches` row, `coach_id` is null and scoping breaks (they'd see no members).
- **Resolution:** intentional split (auth vs domain), but a data-integrity dependency: every coach login needs a matching `coaches` row. VERIFIED; document as an operational invariant.

## C-05 — File naming vs contents: `training.php` holds financial helpers `[CONFLICTING/minor]`
- `training.php` header says "load intelligence: pure training-math functions," but it also defines `monthlyize`, `renewal_likelihood`, `payment_outstanding`, `money`, `net_profit` — the **financial** core.
- **Effect:** financial logic lives in a training-named file; easy to miss in an audit. VERIFIED. Naming, not behavior — behavior is correct.

## Conflicts NOT found (checked)
- Trigger logic vs procedure logic: consistent (triggers only fan-out to procedures). `[VERIFIED]`
- View column references vs table columns: consistent in the read set. `[VERIFIED]`
- Seed guard: triggers + run_all + deploy all agree on `@seeding`. `[VERIFIED]`

**Count: 5 conflicts (1 critical: C-01).**
