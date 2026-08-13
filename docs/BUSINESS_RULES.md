# Business Rules (Event-Driven Automation)

> Every rule below is read directly from `sql/02_procedures.sql`, `sql/03_triggers.sql`, `sql/04_events.sql`. `[VERIFIED]` unless tagged otherwise.
> Mechanism: an `AFTER INSERT` trigger on a domain table calls a stored procedure. Triggers are **skipped while `@seeding=1`** (bulk seed load).

## Trigger → procedure map `[VERIFIED — 03_triggers.sql]`
| Insert into | Trigger | Calls |
|---|---|---|
| members | trg_members_after_insert | inline: manager_review task (+1d) + welcome WhatsApp log |
| memberships | trg_memberships_after_insert | status `new→onboarding` + `sp_open_onboarding_workflow` |
| payments | trg_payments_after_insert | `sp_handle_payment_event` |
| daily_attendance | trg_attendance_after_insert | `sp_handle_attendance_event` |
| assessments | trg_assessments_after_insert | `sp_handle_assessment_event` |
| injury_history | trg_injury_history_after_insert | `sp_handle_injury_event` |
| progress_tracking | trg_progress_tracking_after_insert | `sp_handle_progress_event` |
| followups | trg_followups_after_insert | `sp_handle_followup_event` |

## R1 — New member onboarding
- On `members` insert: create `manager_review` task (medium, due +1 day, "Review profile and onboarding flow") and log a `welcome` WhatsApp message (status sent). `[VERIFIED]`
- On first `memberships` insert: `UPDATE members SET status='onboarding' WHERE status='new'`, then `sp_open_onboarding_workflow` creates **3 tasks**: `manager_review` (medium, +1d), `reassess` "First assessment required" (high, +1d), `renewal` "Membership expiry reminder" (medium, due = **end_date − 7 days**). `[VERIFIED]`

## R2 — Payment events (`sp_handle_payment_event`)
| Payment status | Effect `[VERIFIED]` |
|---|---|
| `paid` | `memberships.payment_status='paid'`; log `renewal` WhatsApp "Payment received…" |
| `failed` | `payment_status='failed'`; create **high** `payment_failed` retention flag; create **urgent** `payment_followup` task (due now) |
| `partial` | `payment_status='partial'`; create **high** `payment_followup` task (due now) |

## R3 — Attendance events (`sp_handle_attendance_event`)
- **Attended (=1):** status transitions `onboarding→active`, `at_risk→reactivated` (else unchanged); resolve any open `low_attendance`/`no_show` flags for the member. `[VERIFIED]`
- **Absent (=0):** count absences in last 7 days; **if ≥3** → create **high** `low_attendance` flag ("missed 3+ visits in 7 days") + **urgent** `call` task ("Call member today") + set status `at_risk` (unless paused/expired). `[VERIFIED]`

## R4 — Assessment events (`sp_handle_assessment_event`)
| risk_score | Effect `[VERIFIED]` |
|---|---|
| ≥ 80 | status `corrective`; **critical** `high_risk` flag; **urgent** `reassess` task (+1d) |
| ≥ 60 (and <80) | status `at_risk`; **high** `no_progress` flag; **high** `program_update` task (+2d) |
| < 60 | no automation |

> Note: the procedure branches on numeric `risk_score`, independent of the `classification` ENUM. If the two disagree, the number wins. `[VERIFIED]`

## R5 — Injury events (`sp_handle_injury_event`)
- If severity ∈ {high, critical}: pause active workout plans (`workout_plans.status='paused'`); set member `paused`; create injury retention flag (severity mirrors injury); create **urgent** `medical_referral` task. `[VERIFIED]`

## R6 — Progress events (`sp_handle_progress_event`)
- Compare new `weight` to the most recent prior record. If new weight `< previous − 2` (kg): insert a `weight_loss` milestone (reward `badge`) and log a `progress` WhatsApp encouragement. `[VERIFIED]`
- If `body_fat > 30`: create a **medium** `no_progress` retention flag. `[VERIFIED]`

## R7 — Follow-up events (`sp_handle_followup_event`)
- `no_response` → create **high** `call` task (retry, +1d). `[VERIFIED]`
- `booked` or `converted` → resolve all open retention flags for the member. `[VERIFIED]`

## R8 — Daily retention scan (`ev_daily_retention_scan`, EVERY 1 DAY)
- Expire memberships: members with a membership `end_date < today`, `renewal_status <> 'renewed'`, not paused → status `expired`. `[VERIFIED]`
- Inactivity flag: active/onboarding/at_risk members with **no attended visit in the last 7 days** → insert **medium** `low_attendance` flag. `[VERIFIED]`
- **Precondition:** MySQL event scheduler must be ON, else this rule silently does not run. `[VERIFIED]` → see SECURITY / UNKNOWNS.

## Status lifecycle (observed transitions) `[VERIFIED]`
```
new ──(membership insert)──► onboarding ──(attended)──► active
 active/onboarding ──(≥3 absences/7d or risk≥60)──► at_risk ──(attended)──► reactivated
 any ──(risk≥80)──► corrective
 any ──(high/critical injury)──► paused
 membership end_date passed & not renewed ──(daily scan)──► expired
```
Values `upgraded` exists in the ENUM but no automation setting it was found in the read procedures/triggers `[UNKNOWN]` — likely set by PHP flows not fully traced.
