-- =============================================================================
-- xcamp-gym-sql : 07_test_queries.sql
-- -----------------------------------------------------------------------------
-- Read-only sanity checks. Each block is labelled so the output is
-- self-describing. Safe to run repeatedly.
-- =============================================================================

USE xcamp_gym;

SELECT '01. Row counts per table' AS test;
SELECT 'users' AS table_name, COUNT(*) AS row_count FROM users
UNION ALL SELECT 'coaches', COUNT(*) FROM coaches
UNION ALL SELECT 'members', COUNT(*) FROM members
UNION ALL SELECT 'plans', COUNT(*) FROM plans
UNION ALL SELECT 'memberships', COUNT(*) FROM memberships
UNION ALL SELECT 'payments', COUNT(*) FROM payments
UNION ALL SELECT 'daily_attendance', COUNT(*) FROM daily_attendance
UNION ALL SELECT 'followups', COUNT(*) FROM followups
UNION ALL SELECT 'retention_flags', COUNT(*) FROM retention_flags
UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs;

SELECT '02. Active members (vw_active_members)' AS test;
SELECT * FROM vw_active_members ORDER BY days_remaining;

SELECT '03. Memberships expiring within 14 days (vw_expiring_memberships)' AS test;
SELECT * FROM vw_expiring_memberships ORDER BY end_date;

SELECT '04. Attendance last 30 days (vw_member_attendance_30d)' AS test;
SELECT * FROM vw_member_attendance_30d WHERE visits_30d > 0 ORDER BY visits_30d DESC;

SELECT '05. Revenue by month (vw_revenue_by_month)' AS test;
SELECT * FROM vw_revenue_by_month ORDER BY month;

SELECT '06. Coach roster (vw_coach_roster)' AS test;
SELECT * FROM vw_coach_roster ORDER BY active_members DESC;

SELECT '07. At-risk members (vw_at_risk_members)' AS test;
SELECT * FROM vw_at_risk_members ORDER BY FIELD(severity,'high','medium','low');

SELECT '08. Open follow-ups (vw_open_followups)' AS test;
SELECT * FROM vw_open_followups ORDER BY due_date;

-- ---------------------------------------------------------------------------
-- Procedure exercises
-- ---------------------------------------------------------------------------
SELECT '09. sp_check_in / sp_check_out for member 3' AS test;
CALL sp_check_in(3, 'app');
CALL sp_check_out(3);
SELECT member_id, attend_date, check_in_at, check_out_at
FROM daily_attendance WHERE member_id = 3 AND attend_date = CURRENT_DATE;

SELECT '10. sp_renew_membership for member 3 (extends end_date)' AS test;
CALL sp_renew_membership(3, 2, 1350.00, 'card');
SELECT membership_id, plan_id, start_date, end_date, status
FROM memberships WHERE member_id = 3 ORDER BY membership_id;

SELECT '11. sp_flag_member raises a flag and updates member status' AS test;
CALL sp_flag_member(7, 'no_show', 'medium', 'Manual test flag');
SELECT member_id, status FROM members WHERE member_id = 7;
SELECT flag_type, severity, reason FROM retention_flags WHERE member_id = 7 ORDER BY flag_id DESC LIMIT 1;

SELECT '12. Audit log tail (written by triggers)' AS test;
SELECT audit_id, entity_type, entity_id, action, created_at
FROM audit_logs ORDER BY audit_id DESC LIMIT 10;

-- ---------------------------------------------------------------------------
-- Negative test (informational): the unique (member_id, attend_date) key means
-- a second check-in the same day does NOT create a duplicate row. To see the
-- validation SIGNAL, try an unknown member id:
--   CALL sp_check_in(999999, 'app');   -- expected: 'sp_check_in: member not found'
-- ---------------------------------------------------------------------------
SELECT 'DONE: test queries complete' AS test;
