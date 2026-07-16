-- =============================================================================
-- xcamp-gym-sql : 03_triggers.sql
-- -----------------------------------------------------------------------------
-- AFTER INSERT triggers that fan domain events into the sp_handle_*_event /
-- onboarding orchestrators in 02_procedures.sql.
-- Each is dropped-then-created for re-runnability.
-- =============================================================================

USE xcamp_gym;

DROP TRIGGER IF EXISTS trg_members_after_insert;
DROP TRIGGER IF EXISTS trg_memberships_after_insert;
DROP TRIGGER IF EXISTS trg_payments_after_insert;
DROP TRIGGER IF EXISTS trg_attendance_after_insert;
DROP TRIGGER IF EXISTS trg_assessments_after_insert;
DROP TRIGGER IF EXISTS trg_injury_history_after_insert;
DROP TRIGGER IF EXISTS trg_progress_tracking_after_insert;
DROP TRIGGER IF EXISTS trg_followups_after_insert;

DELIMITER $$

CREATE TRIGGER trg_members_after_insert
AFTER INSERT ON members
FOR EACH ROW
BEGIN
    CALL sp_create_task(NEW.member_id, NEW.coach_id, NULL, 'manager_review', 'medium', DATE_ADD(NOW(), INTERVAL 1 DAY), 'New member created. Review profile and onboarding flow.');
    CALL sp_log_message(NEW.member_id, NEW.coach_id, 'whatsapp', 'welcome', 'Welcome to Xcamp. Your onboarding has started.', 'sent');
END $$

CREATE TRIGGER trg_memberships_after_insert
AFTER INSERT ON memberships
FOR EACH ROW
BEGIN
    UPDATE members SET status = 'onboarding' WHERE member_id = NEW.member_id AND status = 'new';
    CALL sp_open_onboarding_workflow(NEW.member_id, (SELECT coach_id FROM members WHERE member_id = NEW.member_id), NEW.end_date);
END $$

CREATE TRIGGER trg_payments_after_insert
AFTER INSERT ON payments
FOR EACH ROW
BEGIN
    CALL sp_handle_payment_event(NEW.member_id, NEW.membership_id, NEW.status, (SELECT coach_id FROM members WHERE member_id = NEW.member_id));
END $$

CREATE TRIGGER trg_attendance_after_insert
AFTER INSERT ON daily_attendance
FOR EACH ROW
BEGIN
    CALL sp_handle_attendance_event(NEW.member_id, NEW.attended, NEW.coach_id);
END $$

CREATE TRIGGER trg_assessments_after_insert
AFTER INSERT ON assessments
FOR EACH ROW
BEGIN
    CALL sp_handle_assessment_event(NEW.member_id, NEW.assessment_id, NEW.risk_score, NEW.coach_id);
END $$

CREATE TRIGGER trg_injury_history_after_insert
AFTER INSERT ON injury_history
FOR EACH ROW
BEGIN
    CALL sp_handle_injury_event(NEW.member_id, NEW.severity, (SELECT coach_id FROM members WHERE member_id = NEW.member_id));
END $$

CREATE TRIGGER trg_progress_tracking_after_insert
AFTER INSERT ON progress_tracking
FOR EACH ROW
BEGIN
    CALL sp_handle_progress_event(NEW.member_id, NEW.record_date, NEW.weight, NEW.body_fat, (SELECT coach_id FROM members WHERE member_id = NEW.member_id));
END $$

CREATE TRIGGER trg_followups_after_insert
AFTER INSERT ON followups
FOR EACH ROW
BEGIN
    CALL sp_handle_followup_event(NEW.member_id, NEW.coach_id, NEW.response_status);
END $$

DELIMITER ;
