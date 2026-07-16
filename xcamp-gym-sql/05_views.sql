-- =============================================================================
-- xcamp-gym-sql : 05_views.sql
-- -----------------------------------------------------------------------------
-- Reporting / operational views, including the coach dashboard set.
-- CREATE OR REPLACE keeps these re-runnable; old (removed) view names are
-- dropped first for cleanliness.
-- =============================================================================

USE xcamp_gym;

DROP VIEW IF EXISTS vw_active_members;
DROP VIEW IF EXISTS vw_expiring_memberships;
DROP VIEW IF EXISTS vw_member_attendance_30d;
DROP VIEW IF EXISTS vw_revenue_by_month;
DROP VIEW IF EXISTS vw_coach_roster;
DROP VIEW IF EXISTS vw_open_followups;

CREATE OR REPLACE VIEW vw_assessment_summary AS
SELECT
    a.assessment_id,
    a.member_id,
    m.full_name AS member_name,
    a.coach_id,
    c.full_name AS coach_name,
    a.assessment_date,
    a.parq_risk_count,
    a.overhead_squat_score,
    a.posture_score,
    a.movement_score,
    a.risk_score,
    a.classification,
    a.recommendation,
    a.next_review_date
FROM assessments a
JOIN members m ON m.member_id = a.member_id
LEFT JOIN coaches c ON c.coach_id = a.coach_id;

CREATE OR REPLACE VIEW vw_progress_trends AS
SELECT
    p.member_id,
    m.full_name AS member_name,
    MIN(p.record_date) AS first_record_date,
    MAX(p.record_date) AS last_record_date,
    COUNT(*) AS total_entries,
    MAX(p.weight) AS latest_weight,
    MAX(p.body_fat) AS latest_body_fat,
    MAX(p.muscle_mass) AS latest_muscle_mass
FROM progress_tracking p
JOIN members m ON m.member_id = p.member_id
GROUP BY p.member_id, m.full_name;

CREATE OR REPLACE VIEW vw_overdue_payments AS
SELECT
    mem.member_id,
    mem.full_name AS member_name,
    mem.phone,
    mem.status AS member_status,
    ms.membership_id,
    ms.start_date,
    ms.end_date,
    ms.payment_status,
    ms.renewal_status,
    DATEDIFF(ms.end_date, CURDATE()) AS days_to_expiry
FROM memberships ms
JOIN members mem ON mem.member_id = ms.member_id
WHERE ms.payment_status IN ('unpaid', 'failed', 'partial')
   OR ms.end_date < CURDATE();

CREATE OR REPLACE VIEW vw_membership_expiry_soon AS
SELECT
    mem.member_id,
    mem.full_name AS member_name,
    mem.phone,
    ms.membership_id,
    ms.end_date,
    DATEDIFF(ms.end_date, CURDATE()) AS days_to_expiry,
    ms.renewal_status,
    ms.payment_status
FROM memberships ms
JOIN members mem ON mem.member_id = ms.member_id
WHERE ms.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY);

CREATE OR REPLACE VIEW vw_at_risk_members AS
SELECT DISTINCT
    m.member_id,
    m.full_name,
    m.phone,
    m.status,
    COALESCE(a.risk_score, 0) AS risk_score,
    COALESCE(a.classification, 'moderate') AS classification,
    COALESCE(att.last_attendance_date, NULL) AS last_attendance_date,
    COALESCE(pay.last_payment_status, 'unknown') AS last_payment_status,
    COALESCE(pr.last_progress_date, NULL) AS last_progress_date
FROM members m
LEFT JOIN (
    SELECT member_id, MAX(assessment_date) AS last_assessment_date, MAX(risk_score) AS risk_score, MAX(classification) AS classification
    FROM assessments
    GROUP BY member_id
) a ON a.member_id = m.member_id
LEFT JOIN (
    SELECT member_id, MAX(attendance_date) AS last_attendance_date
    FROM daily_attendance
    WHERE attended = 1
    GROUP BY member_id
) att ON att.member_id = m.member_id
LEFT JOIN (
    SELECT member_id, MAX(payment_date) AS last_payment_date,
           SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY payment_date DESC), ',', 1) AS last_payment_status
    FROM payments
    GROUP BY member_id
) pay ON pay.member_id = m.member_id
LEFT JOIN (
    SELECT member_id, MAX(record_date) AS last_progress_date
    FROM progress_tracking
    GROUP BY member_id
) pr ON pr.member_id = m.member_id
WHERE m.status IN ('at_risk', 'corrective', 'paused')
   OR COALESCE(a.risk_score, 0) >= 60;

CREATE OR REPLACE VIEW vw_due_followups AS
SELECT
    f.followup_id,
    f.member_id,
    m.full_name AS member_name,
    f.coach_id,
    c.full_name AS coach_name,
    f.followup_date,
    f.reason,
    f.contact_channel,
    f.response_status,
    f.next_followup_date,
    f.action_taken
FROM followups f
JOIN members m ON m.member_id = f.member_id
LEFT JOIN coaches c ON c.coach_id = f.coach_id
WHERE f.response_status IN ('no_response', 'escalated')
   OR (f.next_followup_date IS NOT NULL AND f.next_followup_date <= NOW());

CREATE OR REPLACE VIEW vw_daily_coach_queue AS
SELECT
    t.task_id,
    t.member_id,
    m.full_name AS member_name,
    t.coach_id,
    c.full_name AS coach_name,
    t.task_type,
    t.priority,
    t.status,
    t.due_at,
    t.notes,
    COALESCE(r.severity, 'none') AS flag_severity,
    COALESCE(r.flag_type, 'none') AS flag_type
FROM tasks t
LEFT JOIN members m ON m.member_id = t.member_id
LEFT JOIN coaches c ON c.coach_id = t.coach_id
LEFT JOIN retention_flags r ON r.flag_id = t.flag_id
WHERE t.status IN ('open', 'doing')
ORDER BY FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.due_at ASC;

CREATE OR REPLACE VIEW vw_member_operational_status AS
SELECT
    m.member_id,
    m.full_name AS member_name,
    m.phone,
    m.status AS member_status,
    m.coach_id,
    c.full_name AS coach_name,
    ms.membership_id,
    ms.end_date AS membership_end_date,
    ms.payment_status,
    ms.renewal_status,
    a.assessment_date AS last_assessment_date,
    a.risk_score,
    a.classification,
    att.attendance_date AS last_attendance_date,
    pr.record_date AS last_progress_date,
    pay.payment_date AS last_payment_date,
    pay.status AS last_payment_status,
    CASE
        WHEN COALESCE(a.risk_score, 0) >= 80 THEN 'critical'
        WHEN COALESCE(a.risk_score, 0) >= 60 THEN 'high'
        WHEN ms.end_date < CURDATE() THEN 'expired'
        WHEN ms.payment_status IN ('unpaid', 'failed') THEN 'payment_issue'
        WHEN att.attendance_date IS NULL OR att.attendance_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 'low_attendance'
        ELSE 'normal'
    END AS operational_state
FROM members m
LEFT JOIN coaches c ON c.coach_id = m.coach_id
LEFT JOIN memberships ms ON ms.member_id = m.member_id
    AND ms.end_date = (SELECT MAX(ms2.end_date) FROM memberships ms2 WHERE ms2.member_id = m.member_id)
LEFT JOIN assessments a ON a.assessment_id = (
    SELECT a2.assessment_id
    FROM assessments a2
    WHERE a2.member_id = m.member_id
    ORDER BY a2.assessment_date DESC
    LIMIT 1
)
LEFT JOIN daily_attendance att ON att.attendance_id = (
    SELECT d2.attendance_id
    FROM daily_attendance d2
    WHERE d2.member_id = m.member_id AND d2.attended = 1
    ORDER BY d2.attendance_date DESC
    LIMIT 1
)
LEFT JOIN progress_tracking pr ON pr.progress_id = (
    SELECT p2.progress_id
    FROM progress_tracking p2
    WHERE p2.member_id = m.member_id
    ORDER BY p2.record_date DESC
    LIMIT 1
)
LEFT JOIN payments pay ON pay.payment_id = (
    SELECT pay2.payment_id
    FROM payments pay2
    WHERE pay2.member_id = m.member_id
    ORDER BY pay2.payment_date DESC
    LIMIT 1
);

CREATE OR REPLACE VIEW vw_dashboard_kpis AS
SELECT
    (SELECT COUNT(*) FROM members) AS total_members,
    (SELECT COUNT(*) FROM members WHERE status IN ('active','onboarding')) AS active_members,
    (SELECT COUNT(*) FROM members WHERE status = 'at_risk') AS at_risk_members,
    (SELECT COUNT(*) FROM members WHERE status = 'paused') AS paused_members,
    (SELECT COUNT(*) FROM memberships WHERE end_date < CURDATE() AND renewal_status <> 'renewed') AS expired_memberships,
    (SELECT COUNT(*) FROM tasks WHERE status IN ('open','doing')) AS open_tasks,
    (SELECT COUNT(*) FROM retention_flags WHERE status = 'open') AS open_flags,
    (SELECT COUNT(*) FROM payments WHERE status = 'failed') AS failed_payments,
    (SELECT COUNT(*) FROM daily_attendance WHERE attendance_date = CURDATE() AND attended = 1) AS today_attended;

CREATE OR REPLACE VIEW vw_dashboard_coach_workload AS
SELECT
    c.coach_id,
    c.full_name AS coach_name,
    COUNT(DISTINCT m.member_id) AS assigned_members,
    SUM(CASE WHEN m.status = 'at_risk' THEN 1 ELSE 0 END) AS risk_members,
    SUM(CASE WHEN t.status IN ('open','doing') THEN 1 ELSE 0 END) AS open_tasks,
    SUM(CASE WHEN f.status = 'open' THEN 1 ELSE 0 END) AS open_flags
FROM coaches c
LEFT JOIN members m ON m.coach_id = c.coach_id
LEFT JOIN tasks t ON t.coach_id = c.coach_id
LEFT JOIN retention_flags f ON f.owner_coach_id = c.coach_id
GROUP BY c.coach_id, c.full_name;

CREATE OR REPLACE VIEW vw_dashboard_today_actions AS
SELECT
    t.task_id,
    t.priority,
    t.task_type,
    m.full_name AS member_name,
    c.full_name AS coach_name,
    t.due_at,
    t.notes
FROM tasks t
LEFT JOIN members m ON m.member_id = t.member_id
LEFT JOIN coaches c ON c.coach_id = t.coach_id
WHERE t.status IN ('open','doing')
ORDER BY FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.due_at ASC;

CREATE OR REPLACE VIEW vw_dashboard_risk_pipeline AS
SELECT
    m.member_id,
    m.full_name AS member_name,
    m.status,
    a.risk_score,
    a.classification,
    f.flag_type,
    f.severity,
    f.status AS flag_status,
    t.task_type,
    t.priority
FROM members m
LEFT JOIN assessments a ON a.assessment_id = (
    SELECT a2.assessment_id
    FROM assessments a2
    WHERE a2.member_id = m.member_id
    ORDER BY a2.assessment_date DESC
    LIMIT 1
)
LEFT JOIN retention_flags f ON f.flag_id = (
    SELECT f2.flag_id
    FROM retention_flags f2
    WHERE f2.member_id = m.member_id
    ORDER BY f2.detected_at DESC
    LIMIT 1
)
LEFT JOIN tasks t ON t.task_id = (
    SELECT t2.task_id
    FROM tasks t2
    WHERE t2.member_id = m.member_id
    ORDER BY t2.created_at DESC
    LIMIT 1
);

CREATE OR REPLACE VIEW vw_dashboard_renewals AS
SELECT
    m.member_id,
    m.full_name AS member_name,
    ms.membership_id,
    ms.end_date,
    DATEDIFF(ms.end_date, CURDATE()) AS days_left,
    ms.payment_status,
    ms.renewal_status
FROM members m
JOIN memberships ms ON ms.member_id = m.member_id
WHERE ms.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
   OR ms.payment_status IN ('unpaid','failed','partial');
