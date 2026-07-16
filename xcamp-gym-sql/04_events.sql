-- =============================================================================
-- xcamp-gym-sql : 04_events.sql
-- -----------------------------------------------------------------------------
-- Scheduled events (housekeeping / automation).
-- NOTE: the MySQL event scheduler must be enabled for these to fire:
--   SET GLOBAL event_scheduler = ON;   -- requires SUPER / SYSTEM_VARIABLES_ADMIN
-- The events are still created (as DISABLED-on-load if the scheduler is off);
-- enabling the scheduler activates them on their schedule.
-- =============================================================================

USE xcamp_gym;

-- Best-effort: enable the scheduler. Ignored/failing silently is acceptable on
-- managed hosts where the privilege is not granted; see README.
SET GLOBAL event_scheduler = ON;

DROP EVENT IF EXISTS ev_expire_memberships;
DROP EVENT IF EXISTS ev_flag_inactive_members;
DROP EVENT IF EXISTS ev_close_stale_followups;
DROP EVENT IF EXISTS ev_purge_old_messages;

DELIMITER $$

-- -----------------------------------------------------------------------------
-- Daily: expire memberships past their end_date and cascade member status.
-- -----------------------------------------------------------------------------
CREATE EVENT ev_expire_memberships
ON SCHEDULE EVERY 1 DAY
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 1 DAY + INTERVAL 2 HOUR)
COMMENT 'Expire memberships whose end_date has passed'
DO
BEGIN
  UPDATE memberships
  SET status = 'expired'
  WHERE status = 'active' AND end_date IS NOT NULL AND end_date < CURRENT_DATE;

  UPDATE members m
  SET m.status = 'expired'
  WHERE m.status IN ('active','reactivated','at_risk','corrective','upgraded')
    AND NOT EXISTS (
      SELECT 1 FROM memberships ms
      WHERE ms.member_id = m.member_id
        AND ms.status IN ('active','frozen')
        AND (ms.end_date IS NULL OR ms.end_date >= CURRENT_DATE)
    );
END$$

-- -----------------------------------------------------------------------------
-- Daily: flag members with no attendance in the last 14 days as inactive.
-- -----------------------------------------------------------------------------
CREATE EVENT ev_flag_inactive_members
ON SCHEDULE EVERY 1 DAY
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 1 DAY + INTERVAL 3 HOUR)
COMMENT 'Raise inactive retention flags for members absent 14+ days'
DO
BEGIN
  INSERT INTO retention_flags (member_id, flag_type, severity, reason, raised_at)
  SELECT m.member_id, 'inactive', 'medium',
         'No attendance in the last 14 days', NOW()
  FROM members m
  WHERE m.status IN ('active','reactivated','upgraded','corrective')
    AND NOT EXISTS (
      SELECT 1 FROM daily_attendance a
      WHERE a.member_id = m.member_id
        AND a.attend_date >= DATE_SUB(CURRENT_DATE, INTERVAL 14 DAY)
    )
    AND NOT EXISTS (
      SELECT 1 FROM retention_flags rf
      WHERE rf.member_id = m.member_id
        AND rf.flag_type = 'inactive'
        AND rf.is_resolved = 0
    );
END$$

-- -----------------------------------------------------------------------------
-- Daily: mark overdue pending follow-ups as missed.
-- -----------------------------------------------------------------------------
CREATE EVENT ev_close_stale_followups
ON SCHEDULE EVERY 1 DAY
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 1 DAY + INTERVAL 4 HOUR)
COMMENT 'Mark pending follow-ups past their due date as missed'
DO
  UPDATE followups
  SET status = 'missed'
  WHERE status = 'pending' AND due_date < CURRENT_DATE$$

-- -----------------------------------------------------------------------------
-- Monthly: purge message log entries older than 12 months.
-- -----------------------------------------------------------------------------
CREATE EVENT ev_purge_old_messages
ON SCHEDULE EVERY 1 MONTH
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 1 DAY + INTERVAL 5 HOUR)
COMMENT 'Delete messages_log rows older than 12 months'
DO
  DELETE FROM messages_log
  WHERE sent_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)$$

DELIMITER ;
