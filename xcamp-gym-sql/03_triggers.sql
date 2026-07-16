-- =============================================================================
-- xcamp-gym-sql : 03_triggers.sql
-- -----------------------------------------------------------------------------
-- Triggers that enforce defaults and write to audit_logs.
-- Each is dropped-then-created so the file is re-runnable.
-- =============================================================================

USE xcamp_gym;

DROP TRIGGER IF EXISTS trg_memberships_before_insert;
DROP TRIGGER IF EXISTS trg_memberships_after_insert;
DROP TRIGGER IF EXISTS trg_memberships_after_update;
DROP TRIGGER IF EXISTS trg_payments_after_insert;
DROP TRIGGER IF EXISTS trg_attendance_before_insert;
DROP TRIGGER IF EXISTS trg_members_after_update;

DELIMITER $$

-- -----------------------------------------------------------------------------
-- Default a membership's end_date from the plan's duration when not supplied.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_memberships_before_insert
BEFORE INSERT ON memberships
FOR EACH ROW
BEGIN
  DECLARE v_duration INT UNSIGNED;

  IF NEW.end_date IS NULL THEN
    SELECT duration_days INTO v_duration FROM plans WHERE plan_id = NEW.plan_id;
    IF v_duration IS NOT NULL THEN
      SET NEW.end_date = DATE_ADD(NEW.start_date, INTERVAL v_duration DAY);
    END IF;
  END IF;
END$$

-- -----------------------------------------------------------------------------
-- When a new active membership is created, make sure the member reads 'active'.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_memberships_after_insert
AFTER INSERT ON memberships
FOR EACH ROW
BEGIN
  IF NEW.status = 'active' THEN
    UPDATE members
    SET status = CASE WHEN status IN ('new','onboarding') THEN 'active' ELSE status END
    WHERE member_id = NEW.member_id AND status IN ('new','onboarding','at_risk','paused','expired');
  END IF;

  INSERT INTO audit_logs (entity_name, entity_id, action_type, new_data)
  VALUES ('memberships', NEW.membership_id, 'insert',
          JSON_OBJECT('member_id', NEW.member_id, 'plan_id', NEW.plan_id,
                      'status', NEW.status, 'start_date', NEW.start_date,
                      'end_date', NEW.end_date));
END$$

-- -----------------------------------------------------------------------------
-- Log membership status changes.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_memberships_after_update
AFTER UPDATE ON memberships
FOR EACH ROW
BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO audit_logs (entity_name, entity_id, action_type, old_data, new_data)
    VALUES ('memberships', NEW.membership_id, 'update',
            JSON_OBJECT('status', OLD.status),
            JSON_OBJECT('status', NEW.status));
  END IF;
END$$

-- -----------------------------------------------------------------------------
-- Log every payment.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_payments_after_insert
AFTER INSERT ON payments
FOR EACH ROW
BEGIN
  INSERT INTO audit_logs (entity_name, entity_id, action_type, new_data)
  VALUES ('payments', NEW.payment_id, 'insert',
          JSON_OBJECT('member_id', NEW.member_id, 'amount', NEW.amount,
                      'method', NEW.method, 'status', NEW.status));
END$$

-- -----------------------------------------------------------------------------
-- Default attendance date from the check-in timestamp when not supplied.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_attendance_before_insert
BEFORE INSERT ON daily_attendance
FOR EACH ROW
BEGIN
  IF NEW.attend_date IS NULL THEN
    SET NEW.attend_date = DATE(COALESCE(NEW.check_in_at, NOW()));
  END IF;
END$$

-- -----------------------------------------------------------------------------
-- Log member status changes.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_members_after_update
AFTER UPDATE ON members
FOR EACH ROW
BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO audit_logs (entity_name, entity_id, action_type, old_data, new_data)
    VALUES ('members', NEW.member_id, 'update',
            JSON_OBJECT('status', OLD.status),
            JSON_OBJECT('status', NEW.status));
  END IF;
END$$

DELIMITER ;
