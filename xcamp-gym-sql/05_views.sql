-- =============================================================================
-- xcamp-gym-sql : 05_views.sql
-- -----------------------------------------------------------------------------
-- Reporting / convenience views.
-- =============================================================================

USE xcamp_gym;

DROP VIEW IF EXISTS vw_active_members;
DROP VIEW IF EXISTS vw_expiring_memberships;
DROP VIEW IF EXISTS vw_member_attendance_30d;
DROP VIEW IF EXISTS vw_revenue_by_month;
DROP VIEW IF EXISTS vw_coach_roster;
DROP VIEW IF EXISTS vw_at_risk_members;
DROP VIEW IF EXISTS vw_open_followups;

-- -----------------------------------------------------------------------------
-- Members with a currently-valid active membership, plus plan & coach.
-- -----------------------------------------------------------------------------
CREATE VIEW vw_active_members AS
SELECT
  m.member_id,
  m.full_name,
  m.phone,
  m.email,
  m.status AS member_status,
  c.full_name AS coach_name,
  p.name AS plan_name,
  ms.start_date,
  ms.end_date,
  DATEDIFF(ms.end_date, CURRENT_DATE) AS days_remaining
FROM members m
JOIN memberships ms
  ON ms.member_id = m.member_id
 AND ms.status = 'active'
 AND (ms.end_date IS NULL OR ms.end_date >= CURRENT_DATE)
JOIN plans p ON p.plan_id = ms.plan_id
LEFT JOIN coaches c ON c.coach_id = m.coach_id;

-- -----------------------------------------------------------------------------
-- Active memberships ending within the next 14 days.
-- -----------------------------------------------------------------------------
CREATE VIEW vw_expiring_memberships AS
SELECT
  m.member_id,
  m.full_name,
  m.phone,
  p.name AS plan_name,
  ms.end_date,
  DATEDIFF(ms.end_date, CURRENT_DATE) AS days_remaining
FROM memberships ms
JOIN members m ON m.member_id = ms.member_id
JOIN plans p ON p.plan_id = ms.plan_id
WHERE ms.status = 'active'
  AND ms.end_date IS NOT NULL
  AND ms.end_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 14 DAY);

-- -----------------------------------------------------------------------------
-- Attendance count per member over the last 30 days.
-- -----------------------------------------------------------------------------
CREATE VIEW vw_member_attendance_30d AS
SELECT
  m.member_id,
  m.full_name,
  COUNT(a.attendance_id) AS visits_30d,
  MAX(a.attend_date) AS last_visit
FROM members m
LEFT JOIN daily_attendance a
  ON a.member_id = m.member_id
 AND a.attend_date >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
GROUP BY m.member_id, m.full_name;

-- -----------------------------------------------------------------------------
-- Paid revenue aggregated by calendar month.
-- -----------------------------------------------------------------------------
CREATE VIEW vw_revenue_by_month AS
SELECT
  DATE_FORMAT(paid_at, '%Y-%m') AS month,
  COUNT(*) AS payment_count,
  SUM(amount) AS total_revenue
FROM payments
WHERE status = 'paid'
GROUP BY DATE_FORMAT(paid_at, '%Y-%m');

-- -----------------------------------------------------------------------------
-- Coach roster: active member counts per coach.
-- -----------------------------------------------------------------------------
CREATE VIEW vw_coach_roster AS
SELECT
  c.coach_id,
  c.full_name AS coach_name,
  c.specialty,
  c.active,
  COUNT(m.member_id) AS total_members,
  SUM(CASE WHEN m.status IN ('active','reactivated','upgraded','corrective') THEN 1 ELSE 0 END) AS active_members
FROM coaches c
LEFT JOIN members m ON m.coach_id = c.coach_id
GROUP BY c.coach_id, c.full_name, c.specialty, c.active;

-- -----------------------------------------------------------------------------
-- Members with unresolved retention flags (highest severity first).
-- -----------------------------------------------------------------------------
CREATE VIEW vw_at_risk_members AS
SELECT
  m.member_id,
  m.full_name,
  m.phone,
  m.status AS member_status,
  rf.flag_type,
  rf.severity,
  rf.status AS flag_status,
  rf.action_required,
  rf.detected_at
FROM retention_flags rf
JOIN members m ON m.member_id = rf.member_id
WHERE rf.status IN ('open','in_progress');

-- -----------------------------------------------------------------------------
-- Open (pending) follow-ups with owning coach.
-- -----------------------------------------------------------------------------
CREATE VIEW vw_open_followups AS
SELECT
  f.followup_id,
  m.member_id,
  m.full_name AS member_name,
  m.phone,
  c.full_name AS coach_name,
  f.channel,
  f.due_date,
  DATEDIFF(f.due_date, CURRENT_DATE) AS days_until_due
FROM followups f
JOIN members m ON m.member_id = f.member_id
LEFT JOIN coaches c ON c.coach_id = f.coach_id
WHERE f.status = 'pending';
