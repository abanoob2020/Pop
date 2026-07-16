-- =============================================================================
-- xcamp-gym-sql : 02_procedures.sql
-- -----------------------------------------------------------------------------
-- Stored procedures encapsulating the core business workflows.
-- Each is dropped-then-created so the file is re-runnable.
-- =============================================================================

USE xcamp_gym;

DROP PROCEDURE IF EXISTS sp_register_member;
DROP PROCEDURE IF EXISTS sp_renew_membership;
DROP PROCEDURE IF EXISTS sp_record_payment;
DROP PROCEDURE IF EXISTS sp_check_in;
DROP PROCEDURE IF EXISTS sp_check_out;
DROP PROCEDURE IF EXISTS sp_freeze_membership;
DROP PROCEDURE IF EXISTS sp_flag_member;
DROP PROCEDURE IF EXISTS sp_complete_followup;

DELIMITER $$

-- -----------------------------------------------------------------------------
-- Register a brand-new member together with their first membership + payment.
-- end_date is derived from the plan's duration_days. Runs as a transaction.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE sp_register_member(
  IN  p_full_name  VARCHAR(150),
  IN  p_phone      VARCHAR(30),
  IN  p_email      VARCHAR(191),
  IN  p_join_date  DATE,
  IN  p_coach_id   BIGINT UNSIGNED,
  IN  p_plan_id    BIGINT UNSIGNED,
  IN  p_price      DECIMAL(10,2),
  IN  p_method     ENUM('cash','card','transfer','online','wallet'),
  OUT p_member_id  BIGINT UNSIGNED
)
BEGIN
  DECLARE v_duration INT UNSIGNED;
  DECLARE v_start DATE;
  DECLARE v_end DATE;
  DECLARE v_membership_id BIGINT UNSIGNED;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF NOT EXISTS (SELECT 1 FROM plans WHERE plan_id = p_plan_id AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_register_member: plan not found or inactive';
  END IF;

  START TRANSACTION;

  SET v_start = COALESCE(p_join_date, CURRENT_DATE);
  SELECT duration_days INTO v_duration FROM plans WHERE plan_id = p_plan_id;
  SET v_end = DATE_ADD(v_start, INTERVAL v_duration DAY);

  INSERT INTO members (full_name, phone, email, join_date, status, coach_id)
  VALUES (p_full_name, p_phone, p_email, v_start, 'active', p_coach_id);
  SET p_member_id = LAST_INSERT_ID();

  INSERT INTO memberships (member_id, plan_id, start_date, end_date, status, price_paid)
  VALUES (p_member_id, p_plan_id, v_start, v_end, 'active', p_price);
  SET v_membership_id = LAST_INSERT_ID();

  INSERT INTO payments (member_id, membership_id, amount, method, status, paid_at)
  VALUES (p_member_id, v_membership_id, p_price, COALESCE(p_method,'cash'), 'paid', NOW());

  COMMIT;
END$$

-- -----------------------------------------------------------------------------
-- Renew a member: new membership starting the later of today / current end_date,
-- plus a matching payment. Marks the member 'active'.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE sp_renew_membership(
  IN p_member_id BIGINT UNSIGNED,
  IN p_plan_id   BIGINT UNSIGNED,
  IN p_price     DECIMAL(10,2),
  IN p_method    ENUM('cash','card','transfer','online','wallet')
)
BEGIN
  DECLARE v_duration INT UNSIGNED;
  DECLARE v_start DATE;
  DECLARE v_end DATE;
  DECLARE v_membership_id BIGINT UNSIGNED;
  DECLARE v_last_end DATE;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF NOT EXISTS (SELECT 1 FROM members WHERE member_id = p_member_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_renew_membership: member not found';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM plans WHERE plan_id = p_plan_id AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_renew_membership: plan not found or inactive';
  END IF;

  START TRANSACTION;

  SELECT MAX(end_date) INTO v_last_end
  FROM memberships
  WHERE member_id = p_member_id AND status IN ('active','frozen');

  SET v_start = GREATEST(CURRENT_DATE, COALESCE(v_last_end, CURRENT_DATE));
  SELECT duration_days INTO v_duration FROM plans WHERE plan_id = p_plan_id;
  SET v_end = DATE_ADD(v_start, INTERVAL v_duration DAY);

  INSERT INTO memberships (member_id, plan_id, start_date, end_date, status, price_paid)
  VALUES (p_member_id, p_plan_id, v_start, v_end, 'active', p_price);
  SET v_membership_id = LAST_INSERT_ID();

  INSERT INTO payments (member_id, membership_id, amount, method, status, paid_at)
  VALUES (p_member_id, v_membership_id, p_price, COALESCE(p_method,'cash'), 'paid', NOW());

  UPDATE members
  SET status = CASE WHEN status IN ('expired','paused') THEN 'reactivated' ELSE 'active' END
  WHERE member_id = p_member_id;

  COMMIT;
END$$

-- -----------------------------------------------------------------------------
-- Record a standalone payment.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE sp_record_payment(
  IN p_member_id     BIGINT UNSIGNED,
  IN p_membership_id BIGINT UNSIGNED,
  IN p_amount        DECIMAL(10,2),
  IN p_method        ENUM('cash','card','transfer','online','wallet'),
  IN p_reference     VARCHAR(100)
)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM members WHERE member_id = p_member_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_record_payment: member not found';
  END IF;

  INSERT INTO payments (member_id, membership_id, amount, method, status, paid_at, reference)
  VALUES (p_member_id, p_membership_id, p_amount, COALESCE(p_method,'cash'), 'paid', NOW(), p_reference);
END$$

-- -----------------------------------------------------------------------------
-- Check a member in for today. Idempotent per (member, day) thanks to the
-- unique key; a second check-in on the same day is ignored.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE sp_check_in(
  IN p_member_id BIGINT UNSIGNED,
  IN p_source    ENUM('kiosk','manual','app')
)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM members WHERE member_id = p_member_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_check_in: member not found';
  END IF;

  INSERT INTO daily_attendance (member_id, attend_date, check_in_at, source)
  VALUES (p_member_id, CURRENT_DATE, NOW(), COALESCE(p_source,'manual'))
  ON DUPLICATE KEY UPDATE check_in_at = LEAST(check_in_at, VALUES(check_in_at));
END$$

-- -----------------------------------------------------------------------------
-- Close today's open attendance row for a member.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE sp_check_out(
  IN p_member_id BIGINT UNSIGNED
)
BEGIN
  UPDATE daily_attendance
  SET check_out_at = NOW()
  WHERE member_id = p_member_id
    AND attend_date = CURRENT_DATE
    AND check_out_at IS NULL;
END$$

-- -----------------------------------------------------------------------------
-- Freeze all active memberships for a member and mark them paused.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE sp_freeze_membership(
  IN p_member_id BIGINT UNSIGNED
)
BEGIN
  UPDATE memberships
  SET status = 'frozen'
  WHERE member_id = p_member_id AND status = 'active';

  UPDATE members SET status = 'paused' WHERE member_id = p_member_id;
END$$

-- -----------------------------------------------------------------------------
-- Raise a retention flag and reflect it on the member's status.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE sp_flag_member(
  IN p_member_id BIGINT UNSIGNED,
  IN p_flag_type ENUM('low_attendance','no_show','payment_failed','injury','low_motivation','no_progress','low_response'),
  IN p_severity  ENUM('low','medium','high','critical'),
  IN p_action    VARCHAR(255)
)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM members WHERE member_id = p_member_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_flag_member: member not found';
  END IF;

  INSERT INTO retention_flags (member_id, flag_type, severity, status, detected_at, action_required)
  VALUES (p_member_id, p_flag_type, COALESCE(p_severity,'medium'), 'open', NOW(), p_action);

  IF p_flag_type IN ('low_attendance','no_show','no_progress','low_motivation','low_response') THEN
    UPDATE members
    SET status = 'at_risk'
    WHERE member_id = p_member_id AND status NOT IN ('expired','paused');
  END IF;
END$$

-- -----------------------------------------------------------------------------
-- Complete a follow-up with an outcome note.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE sp_complete_followup(
  IN p_followup_id BIGINT UNSIGNED,
  IN p_outcome     VARCHAR(255)
)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM followups WHERE followup_id = p_followup_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_complete_followup: follow-up not found';
  END IF;

  UPDATE followups
  SET status = 'done', outcome = p_outcome
  WHERE followup_id = p_followup_id;
END$$

DELIMITER ;
