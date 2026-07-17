-- =============================================================================
-- xcamp-gym-sql : 04_events.sql
-- -----------------------------------------------------------------------------
-- Scheduled housekeeping. NOTE: the MySQL event scheduler must be enabled for
-- events to fire (SET GLOBAL event_scheduler = ON; requires privilege).
-- Dropped-then-created for re-runnability.
-- =============================================================================

USE xcamp_gym;

SET GLOBAL event_scheduler = ON;

DROP EVENT IF EXISTS ev_daily_retention_scan;

DELIMITER $$

CREATE EVENT ev_daily_retention_scan
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_DATE + INTERVAL 1 DAY
DO
BEGIN
    UPDATE members m
    JOIN memberships ms ON ms.member_id = m.member_id
    SET m.status = 'expired'
    WHERE ms.end_date < CURDATE()
      AND ms.renewal_status <> 'renewed'
      AND m.status NOT IN ('paused');

    INSERT INTO retention_flags (member_id, flag_type, severity, status, detected_at, action_required, owner_coach_id)
    SELECT m.member_id, 'low_attendance', 'medium', 'open', NOW(),
           'No recent activity detected in daily scan.', m.coach_id
    FROM members m
    LEFT JOIN daily_attendance da
      ON da.member_id = m.member_id
     AND da.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     AND da.attended = 1
    WHERE da.attendance_id IS NULL
      AND m.status IN ('active', 'onboarding', 'at_risk');
END $$

DELIMITER ;
