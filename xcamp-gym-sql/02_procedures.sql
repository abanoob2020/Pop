-- =============================================================================
-- xcamp-gym-sql : 02_procedures.sql
-- -----------------------------------------------------------------------------
-- Event-driven business logic. Helper procedures (sp_create_task,
-- sp_create_retention_flag, sp_mark_member_status, sp_log_message) plus the
-- sp_handle_*_event / sp_open_onboarding_workflow orchestrators called by the
-- triggers in 03_triggers.sql. Each is dropped-then-created for re-runnability.
-- =============================================================================

USE xcamp_gym;

DROP PROCEDURE IF EXISTS sp_create_task;
DROP PROCEDURE IF EXISTS sp_create_retention_flag;
DROP PROCEDURE IF EXISTS sp_mark_member_status;
DROP PROCEDURE IF EXISTS sp_log_message;
DROP PROCEDURE IF EXISTS sp_open_onboarding_workflow;
DROP PROCEDURE IF EXISTS sp_handle_payment_event;
DROP PROCEDURE IF EXISTS sp_handle_attendance_event;
DROP PROCEDURE IF EXISTS sp_handle_assessment_event;
DROP PROCEDURE IF EXISTS sp_handle_injury_event;
DROP PROCEDURE IF EXISTS sp_handle_progress_event;
DROP PROCEDURE IF EXISTS sp_handle_followup_event;

DELIMITER $$

CREATE PROCEDURE sp_create_task(
    IN p_member_id BIGINT UNSIGNED,
    IN p_coach_id BIGINT UNSIGNED,
    IN p_flag_id BIGINT UNSIGNED,
    IN p_task_type VARCHAR(50),
    IN p_priority VARCHAR(20),
    IN p_due_at DATETIME,
    IN p_notes TEXT
)
BEGIN
    INSERT INTO tasks (member_id, coach_id, flag_id, task_type, priority, status, due_at, notes)
    VALUES (p_member_id, p_coach_id, p_flag_id, p_task_type, p_priority, 'open', p_due_at, p_notes);
END $$

CREATE PROCEDURE sp_create_retention_flag(
    IN p_member_id BIGINT UNSIGNED,
    IN p_assessment_id BIGINT UNSIGNED,
    IN p_flag_type VARCHAR(50),
    IN p_severity VARCHAR(20),
    IN p_action_required TEXT,
    IN p_owner_coach_id BIGINT UNSIGNED
)
BEGIN
    INSERT INTO retention_flags (member_id, assessment_id, flag_type, severity, status, detected_at, action_required, owner_coach_id)
    VALUES (p_member_id, p_assessment_id, p_flag_type, p_severity, 'open', NOW(), p_action_required, p_owner_coach_id);
END $$

CREATE PROCEDURE sp_mark_member_status(
    IN p_member_id BIGINT UNSIGNED,
    IN p_status VARCHAR(30)
)
BEGIN
    UPDATE members SET status = p_status WHERE member_id = p_member_id;
END $$

CREATE PROCEDURE sp_log_message(
    IN p_member_id BIGINT UNSIGNED,
    IN p_coach_id BIGINT UNSIGNED,
    IN p_channel VARCHAR(20),
    IN p_message_type VARCHAR(20),
    IN p_content TEXT,
    IN p_status VARCHAR(20)
)
BEGIN
    INSERT INTO messages_log (member_id, coach_id, channel, message_type, content, sent_at, status)
    VALUES (p_member_id, p_coach_id, p_channel, p_message_type, p_content, NOW(), p_status);
END $$

CREATE PROCEDURE sp_open_onboarding_workflow(
    IN p_member_id BIGINT UNSIGNED,
    IN p_coach_id BIGINT UNSIGNED,
    IN p_membership_end DATE
)
BEGIN
    CALL sp_create_task(p_member_id, p_coach_id, NULL, 'manager_review', 'medium', DATE_ADD(NOW(), INTERVAL 1 DAY), 'New member onboarding review.');
    CALL sp_create_task(p_member_id, p_coach_id, NULL, 'reassess', 'high', DATE_ADD(NOW(), INTERVAL 1 DAY), 'First assessment required.');
    CALL sp_create_task(p_member_id, p_coach_id, NULL, 'renewal', 'medium', DATE_SUB(p_membership_end, INTERVAL 7 DAY), 'Membership expiry reminder.');
END $$

CREATE PROCEDURE sp_handle_payment_event(
    IN p_member_id BIGINT UNSIGNED,
    IN p_membership_id BIGINT UNSIGNED,
    IN p_status VARCHAR(20),
    IN p_coach_id BIGINT UNSIGNED
)
BEGIN
    IF p_status = 'paid' THEN
        UPDATE memberships SET payment_status = 'paid' WHERE membership_id = p_membership_id;
        CALL sp_log_message(p_member_id, p_coach_id, 'whatsapp', 'renewal', 'Payment received successfully. Thank you.', 'sent');
    ELSEIF p_status = 'failed' THEN
        UPDATE memberships SET payment_status = 'failed' WHERE membership_id = p_membership_id;
        CALL sp_create_retention_flag(p_member_id, NULL, 'payment_failed', 'high', 'Follow up with member for payment recovery.', p_coach_id);
        CALL sp_create_task(p_member_id, p_coach_id, NULL, 'payment_followup', 'urgent', NOW(), 'Payment failed. Contact member immediately.');
    ELSEIF p_status = 'partial' THEN
        UPDATE memberships SET payment_status = 'partial' WHERE membership_id = p_membership_id;
        CALL sp_create_task(p_member_id, p_coach_id, NULL, 'payment_followup', 'high', NOW(), 'Partial payment recorded. Review remaining balance.');
    END IF;
END $$

CREATE PROCEDURE sp_handle_attendance_event(
    IN p_member_id BIGINT UNSIGNED,
    IN p_attended TINYINT,
    IN p_coach_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_absent_count INT DEFAULT 0;

    IF p_attended = 1 THEN
        UPDATE members
        SET status = CASE
            WHEN status = 'onboarding' THEN 'active'
            WHEN status = 'at_risk' THEN 'reactivated'
            ELSE status
        END
        WHERE member_id = p_member_id;

        UPDATE retention_flags
        SET status = 'resolved', resolved_at = NOW()
        WHERE member_id = p_member_id
          AND flag_type IN ('low_attendance', 'no_show')
          AND status = 'open';
    ELSE
        SELECT COUNT(*)
        INTO v_absent_count
        FROM daily_attendance
        WHERE member_id = p_member_id
          AND attended = 0
          AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY);

        IF v_absent_count >= 3 THEN
            CALL sp_create_retention_flag(p_member_id, NULL, 'low_attendance', 'high', 'Member missed 3 or more visits in 7 days.', p_coach_id);
            CALL sp_create_task(p_member_id, p_coach_id, NULL, 'call', 'urgent', NOW(), 'Attendance dropped. Call member today.');
            UPDATE members SET status = 'at_risk' WHERE member_id = p_member_id AND status NOT IN ('paused','expired');
        END IF;
    END IF;
END $$

CREATE PROCEDURE sp_handle_assessment_event(
    IN p_member_id BIGINT UNSIGNED,
    IN p_assessment_id BIGINT UNSIGNED,
    IN p_risk_score DECIMAL(5,2),
    IN p_coach_id BIGINT UNSIGNED
)
BEGIN
    IF p_risk_score >= 80 THEN
        CALL sp_mark_member_status(p_member_id, 'corrective');
        CALL sp_create_retention_flag(p_member_id, p_assessment_id, 'high_risk', 'critical', 'Critical risk score. Immediate corrective intervention required.', p_coach_id);
        CALL sp_create_task(p_member_id, p_coach_id, NULL, 'reassess', 'urgent', DATE_ADD(NOW(), INTERVAL 1 DAY), 'High-risk assessment. Reassess urgently.');
    ELSEIF p_risk_score >= 60 THEN
        CALL sp_mark_member_status(p_member_id, 'at_risk');
        CALL sp_create_retention_flag(p_member_id, p_assessment_id, 'no_progress', 'high', 'High risk score. Follow up and adjust program.', p_coach_id);
        CALL sp_create_task(p_member_id, p_coach_id, NULL, 'program_update', 'high', DATE_ADD(NOW(), INTERVAL 2 DAY), 'High risk. Update training program.');
    END IF;
END $$

CREATE PROCEDURE sp_handle_injury_event(
    IN p_member_id BIGINT UNSIGNED,
    IN p_severity VARCHAR(20),
    IN p_coach_id BIGINT UNSIGNED
)
BEGIN
    IF p_severity IN ('high', 'critical') THEN
        UPDATE workout_plans SET status = 'paused' WHERE member_id = p_member_id AND status = 'active';
        UPDATE members SET status = 'paused' WHERE member_id = p_member_id;
        CALL sp_create_retention_flag(p_member_id, NULL, 'injury', p_severity, 'Pause training plan and review medical status.', p_coach_id);
        CALL sp_create_task(p_member_id, p_coach_id, NULL, 'medical_referral', 'urgent', NOW(), 'Serious injury recorded. Refer to medical clearance.');
    END IF;
END $$

CREATE PROCEDURE sp_handle_progress_event(
    IN p_member_id BIGINT UNSIGNED,
    IN p_record_date DATE,
    IN p_weight DECIMAL(6,2),
    IN p_body_fat DECIMAL(5,2),
    IN p_coach_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_prev_weight DECIMAL(6,2);

    SELECT weight INTO v_prev_weight
    FROM progress_tracking
    WHERE member_id = p_member_id
      AND record_date < p_record_date
      AND weight IS NOT NULL
    ORDER BY record_date DESC
    LIMIT 1;

    IF v_prev_weight IS NOT NULL AND p_weight IS NOT NULL AND p_weight < v_prev_weight - 2 THEN
        INSERT INTO milestones (member_id, milestone_date, milestone_type, description, reward_status)
        VALUES (p_member_id, p_record_date, 'weight_loss', 'Significant weight loss milestone achieved.', 'badge');
        CALL sp_log_message(p_member_id, p_coach_id, 'whatsapp', 'progress', 'Great progress! Keep going.', 'sent');
    END IF;

    IF p_body_fat IS NOT NULL AND p_body_fat > 30 THEN
        CALL sp_create_retention_flag(p_member_id, NULL, 'no_progress', 'medium', 'Body fat remains high. Review nutrition and training plan.', p_coach_id);
    END IF;
END $$

CREATE PROCEDURE sp_handle_followup_event(
    IN p_member_id BIGINT UNSIGNED,
    IN p_coach_id BIGINT UNSIGNED,
    IN p_response_status VARCHAR(20)
)
BEGIN
    IF p_response_status = 'no_response' THEN
        CALL sp_create_task(p_member_id, p_coach_id, NULL, 'call', 'high', DATE_ADD(NOW(), INTERVAL 1 DAY), 'No response received. Retry contact.');
    ELSEIF p_response_status IN ('booked', 'converted') THEN
        UPDATE retention_flags
        SET status = 'resolved', resolved_at = NOW()
        WHERE member_id = p_member_id AND status = 'open';
    END IF;
END $$

DELIMITER ;
