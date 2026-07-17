-- =============================================================================
-- xcamp-gym-sql : 07_test_queries.sql
-- -----------------------------------------------------------------------------
-- Read-only checks against the operational / dashboard views, plus a couple of
-- roll-ups. Safe to run repeatedly. Restores the session state saved in
-- 00_init.sql at the end.
-- =============================================================================

USE xcamp_gym;

SELECT * FROM vw_dashboard_kpis;
SELECT * FROM vw_member_operational_status ORDER BY member_id;
SELECT * FROM vw_at_risk_members ORDER BY risk_score DESC;
SELECT * FROM vw_daily_coach_queue;
SELECT * FROM vw_dashboard_today_actions;
SELECT * FROM vw_dashboard_coach_workload;
SELECT * FROM vw_dashboard_risk_pipeline;
SELECT * FROM vw_dashboard_renewals;
SELECT * FROM vw_membership_expiry_soon ORDER BY days_to_expiry ASC;
SELECT * FROM vw_overdue_payments;
SELECT * FROM vw_due_followups;
SELECT * FROM vw_assessment_summary ORDER BY assessment_date DESC;
SELECT * FROM vw_progress_trends ORDER BY total_entries DESC;

SELECT severity, COUNT(*) AS total
FROM retention_flags
WHERE status = 'open'
GROUP BY severity;

SELECT priority, COUNT(*) AS total
FROM tasks
WHERE status = 'open'
GROUP BY priority
ORDER BY FIELD(priority, 'urgent', 'high', 'medium', 'low');
