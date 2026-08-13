# Database Reference

> Engine: **MySQL 8.0+, InnoDB, utf8mb4 / utf8mb4_unicode_ci**. `[VERIFIED — sql/00_init.sql, sql/01_tables.sql]`
> Tags: `[VERIFIED]` · `[INFERRED]` · `[UNKNOWN]` · `[CONFLICTING]`

## 1. Object inventory `[VERIFIED — grep across sql/]`
- **48 tables** — 20 core (`01_tables.sql`) + 28 module (`08–19`, `11`).
- **11 stored procedures** (`02_procedures.sql`).
- **8 AFTER INSERT triggers** (`03_triggers.sql`).
- **1 scheduled event** `ev_daily_retention_scan` (`04_events.sql`).
- **13 views** `vw_*` (`05_views.sql`).

## 2. Core tables (20) — data dictionary highlights `[VERIFIED — 01_tables.sql]`

### users (staff)
`user_id` PK · `full_name` · `email` UNIQUE · `phone` UNIQUE · `password_hash` · `role ENUM('admin','manager','coach','reception') DEFAULT 'coach'` · `is_active` · `last_login_at`.

### coaches
`coach_id` PK · `user_id` UNIQUE→users (SET NULL) · `full_name` · `specialty` · `active`.

### members
`member_id` PK · identity (`full_name`,`gender`,`birth_date`,`phone`U,`email`U,`address`,`job_title`) · `join_date` · `status ENUM('new','onboarding','active','corrective','at_risk','paused','expired','reactivated','upgraded') DEFAULT 'new'` · `coach_id`→coaches (SET NULL).

### plans
`plan_id` PK · `plan_name` · `duration_days` · `price DECIMAL(10,2)` · `access_level ENUM('basic','standard','pro','elite')` · `active`.

### memberships
`membership_id` PK · `member_id`→members (CASCADE) · `plan_id`→plans (RESTRICT) · `start_date`/`end_date` · `renewal_status ENUM('pending','renewed','expired','cancelled')` · `payment_status ENUM('unpaid','partial','paid','failed','refunded')` · `auto_renew`.

### payments
`payment_id` PK · `member_id`→members (CASCADE) · `membership_id`→memberships (SET NULL) · `payment_date` · `amount DECIMAL(10,2)` · `method ENUM('cash','card','bank_transfer','wallet','other')` · `status ENUM('pending','paid','failed','refunded','partial')` · `receipt_no` · `reference_no`.

### assessments
`assessment_id` PK · `member_id` (CASCADE) · `coach_id` (SET NULL) · `assessment_date` · `parq_risk_count` · `overhead_squat_score` · `posture_score` · `movement_score` · `risk_score DECIMAL(5,2)` · `classification ENUM('excellent','good','moderate','high_risk','critical')` · `recommendation` · `next_review_date`.

### injury_history
`injury_id` PK · `member_id` (CASCADE) · `injury_date` · `body_area` · `severity ENUM('low','medium','high','critical')` · `current_status` · `doctor_clearance`.

### daily_attendance
`attendance_id` PK · `member_id` (CASCADE) · `coach_id` (SET NULL) · `attendance_date` · `check_in_time`/`check_out_time` · `attended TINYINT(1)` · `session_type`.

### followups
`followup_id` PK · `member_id` (CASCADE) · `coach_id` (SET NULL) · `followup_date` · `reason ENUM(...)` · `contact_channel ENUM(...)` · `response_status ENUM('no_response','replied','booked','converted','escalated')` · `next_followup_date`.

### progress_tracking
`progress_id` PK · `member_id` (CASCADE) · `record_date` · `weight`,`body_fat`,`muscle_mass`,`waist`,`chest`,`hips` · `performance_note` · `photo_ref`.

### workout_plans / workout_sessions
Plans: `goal_type`, `phase ENUM('corrective','stabilization','hypertrophy','strength','power','maintenance')`, `status ENUM('active','paused','completed','cancelled')`. Sessions: `workout_plan_id` (CASCADE), muscle group, exercises/sets/reps/load text, `completion_status`.

### nutrition_plans / supplements
Macro targets (`calories`,`protein_g`,`fat_g`,`carbs_g`,`hydration_target_l`), refeed/diet-break protocols; supplements per member.

### retention_flags
`flag_id` PK · `member_id` (CASCADE) · `assessment_id` (SET NULL) · `flag_type ENUM('low_attendance','no_show','payment_failed','injury','low_motivation','no_progress','low_response','high_risk')` · `severity` · `status ENUM('open','in_progress','resolved','dismissed')` · `detected_at`/`resolved_at` · `owner_coach_id`.

### tasks
`task_id` PK · nullable `member_id`/`coach_id`/`flag_id` (all SET NULL) · `task_type ENUM('call','whatsapp','reassess','program_update','payment_followup','medical_referral','manager_review','renewal')` · `priority ENUM('low','medium','high','urgent')` · `status ENUM('open','doing','done','cancelled')` · `due_at`/`completed_at`.

### messages_log
Outreach log: `channel`, `message_type ENUM('welcome','followup','reminder','winback','renewal','progress','warning','other')`, `content`, `sent_at`, `status`.

### milestones
`milestone_type ENUM('first_week','first_month','weight_loss','strength_gain','attendance_streak','program_completion','renewal','upgrade')` · `reward_status ENUM('none','badge','gift','promotion','discount')`.

### audit_logs
`user_id` (SET NULL) · `entity_name` · `entity_id` · `action_type ENUM('insert','update','delete','login','logout','export')` · `old_data JSON` · `new_data JSON`. `[VERIFIED — table exists]` Whether the app writes to it on every action is **not** fully traced `[UNKNOWN]` — see UNKNOWNS.

## 3. Module tables (28) `[VERIFIED — grep of 08–19, 11]`
| File | Tables |
|---|---|
| `08_workout_v2` | exercises, session_exercises, program_templates, template_sessions, template_session_exercises |
| `09_nutrition_v2` | nutrition_logs |
| `10_member_portal` | member_auth |
| `11_finance_pos` | products, pos_sales, pos_sale_items, expenses |
| `12_checkin_qr` | member_qr |
| `13_coach_hr` | coach_hr, coach_shifts, coach_payroll |
| `14_pt_sessions` | pt_sessions |
| `15_referrals` | discount_codes, discount_redemptions |
| `16_assessments` | member_assessments, assessment_parq, assessment_measurements |
| `17_assessment_clinical` | assessment_fms, assessment_posture, assessment_imbalances |
| `18_recipes` | ingredients, recipes, recipe_ingredients |
| `19_training_max` | training_max |

> Note: module tables use `CREATE TABLE IF NOT EXISTS` (additive), whereas core tables use `DROP … ; CREATE` (rebuilt on each load). `[VERIFIED]`

## 4. Stored procedures (11) `[VERIFIED — 02_procedures.sql]`
**Helpers:** `sp_create_task`, `sp_create_retention_flag`, `sp_mark_member_status`, `sp_log_message`, `sp_open_onboarding_workflow`.
**Orchestrators:** `sp_handle_payment_event`, `sp_handle_attendance_event`, `sp_handle_assessment_event`, `sp_handle_injury_event`, `sp_handle_progress_event`, `sp_handle_followup_event`.
Full rules → `BUSINESS_RULES.md`.

## 5. Triggers (8) `[VERIFIED — 03_triggers.sql]`
All `AFTER INSERT`, each guarded by `IF @seeding IS NULL`:
`trg_members_after_insert`, `trg_memberships_after_insert`, `trg_payments_after_insert`, `trg_attendance_after_insert`, `trg_assessments_after_insert`, `trg_injury_history_after_insert`, `trg_progress_tracking_after_insert`, `trg_followups_after_insert`.

## 6. Event (1) `[VERIFIED — 04_events.sql]`
`ev_daily_retention_scan` — `EVERY 1 DAY`: (a) sets members `expired` where membership end_date past and not renewed and not paused; (b) inserts `low_attendance` flags for active/onboarding/at_risk members with no attended visit in 7 days. **Requires the MySQL event scheduler to be ON** (`SET GLOBAL event_scheduler = ON;` needs privilege). `[VERIFIED]`

## 7. Views (13) `[VERIFIED — 05_views.sql]`
`vw_assessment_summary`, `vw_progress_trends`, `vw_overdue_payments`, `vw_membership_expiry_soon`, `vw_at_risk_members`, `vw_due_followups`, `vw_daily_coach_queue`, `vw_member_operational_status`, `vw_dashboard_kpis`, `vw_dashboard_coach_workload`, `vw_dashboard_today_actions`, `vw_dashboard_risk_pipeline`, `vw_dashboard_renewals`.

> ⚠️ Views reference a `coaches` table (`JOIN coaches c`), while several base FKs and procedures use `coach_id`. `vw_dashboard_coach_workload` also references `f.status` on `retention_flags` (whose column is `status`) — consistent. No contradiction found in the read set, but the `coaches` vs `users.role='coach'` duality is worth noting → CONFLICTS C-04. `[INFERRED]`

## 8. Referential integrity summary `[VERIFIED]`
- Deleting a **member** cascades to memberships, payments, assessments, attendance, followups, progress, plans-links, flags, messages, milestones, injuries.
- Deleting a **plan** is `RESTRICT` (blocked while memberships reference it).
- Deleting a **coach** or **user** is `SET NULL` on dependents (history preserved, ownership cleared).
