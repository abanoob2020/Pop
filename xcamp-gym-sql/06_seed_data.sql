-- =============================================================================
-- xcamp-gym-sql : 06_seed_data.sql
-- -----------------------------------------------------------------------------
-- Deterministic sample data with explicit primary keys. Loaded by run_all.sql
-- BEFORE the triggers are created, so the explicit tasks/messages/flags/
-- milestones rows below are not duplicated by trigger side effects.
--
-- Password hashes are placeholders for demo only.
-- =============================================================================

USE xcamp_gym;

INSERT INTO users (user_id, full_name, email, phone, password_hash, role, is_active, last_login_at) VALUES
(1, 'Admin One', 'admin@xcamp.com', '01000000001', 'hash_admin', 'admin', 1, NOW()),
(2, 'Manager One', 'manager@xcamp.com', '01000000002', 'hash_manager', 'manager', 1, NOW()),
(3, 'Coach Ahmed', 'coach1@xcamp.com', '01000000003', 'hash_coach1', 'coach', 1, NOW()),
(4, 'Coach Sara', 'coach2@xcamp.com', '01000000004', 'hash_coach2', 'coach', 1, NOW());

INSERT INTO coaches (coach_id, user_id, full_name, phone, specialty, active) VALUES
(1, 3, 'Coach Ahmed', '01000000003', 'strength & fat loss', 1),
(2, 4, 'Coach Sara', '01000000004', 'mobility & rehab', 1);

INSERT INTO plans (plan_id, plan_name, duration_days, price, access_level, active) VALUES
(1, 'Monthly Basic', 30, 1200.00, 'basic', 1),
(2, 'Quarterly Pro', 90, 3000.00, 'pro', 1),
(3, 'Yearly Elite', 365, 9000.00, 'elite', 1);

INSERT INTO members
(member_id, full_name, gender, birth_date, phone, email, address, job_title, join_date, status, preferred_time, goal_summary, coach_id, notes)
VALUES
(1, 'Omar Khaled', 'male', '1996-04-12', '01111111101', 'omar@x.com', 'Giza', 'Engineer', '2026-07-01', 'new', 'evening', 'fat loss', 1, 'new trial member'),
(2, 'Mona Ali', 'female', '1999-09-20', '01111111102', 'mona@x.com', 'Cairo', 'Designer', '2026-06-20', 'active', 'morning', 'muscle gain', 1, 'consistent member'),
(3, 'Youssef Tarek', 'male', '1992-01-08', '01111111103', 'youssef@x.com', 'Nasr City', 'Sales', '2026-05-15', 'at_risk', 'evening', 'general fitness', 2, 'low attendance recently'),
(4, 'Dina Mahmoud', 'female', '1994-11-02', '01111111104', 'dina@x.com', 'Heliopolis', 'Teacher', '2026-04-10', 'paused', 'morning', 'rehab', 2, 'knee issue'),
(5, 'Hassan Nabil', 'male', '1988-07-22', '01111111105', 'hassan@x.com', '6th October', 'Doctor', '2026-03-05', 'active', 'night', 'strength', 1, 'high performer');

INSERT INTO memberships
(membership_id, member_id, plan_id, start_date, end_date, renewal_status, payment_status, auto_renew)
VALUES
(1, 1, 1, '2026-07-01', '2026-07-31', 'pending', 'unpaid', 0),
(2, 2, 2, '2026-06-20', '2026-09-18', 'pending', 'paid', 1),
(3, 3, 1, '2026-05-15', '2026-06-14', 'expired', 'failed', 0),
(4, 4, 2, '2026-04-10', '2026-07-09', 'pending', 'partial', 0),
(5, 5, 3, '2026-03-05', '2027-03-04', 'renewed', 'paid', 1);

INSERT INTO payments
(payment_id, member_id, membership_id, payment_date, amount, method, status, receipt_no, reference_no, notes)
VALUES
(1, 2, 2, '2026-06-20 10:00:00', 3000.00, 'card', 'paid', 'R-1001', 'TX-2001', 'quarterly payment'),
(2, 3, 3, '2026-05-15 09:15:00', 1200.00, 'cash', 'failed', 'R-1002', 'TX-2002', 'payment failed'),
(3, 4, 4, '2026-04-10 08:45:00', 1500.00, 'bank_transfer', 'partial', 'R-1003', 'TX-2003', 'partial payment'),
(4, 5, 5, '2026-03-05 07:30:00', 9000.00, 'card', 'paid', 'R-1004', 'TX-2004', 'yearly elite payment');

INSERT INTO assessments
(assessment_id, member_id, coach_id, assessment_date, parq_risk_count, overhead_squat_score, posture_score, movement_score, risk_score, classification, recommendation, next_review_date)
VALUES
(1, 1, 1, '2026-07-01 18:00:00', 0, 2.5, 2.0, 2.4, 45.0, 'good', 'general fat loss plan', '2026-07-15'),
(2, 2, 1, '2026-06-20 09:00:00', 0, 2.8, 2.7, 2.9, 20.0, 'excellent', 'hypertrophy plan', '2026-07-20'),
(3, 3, 2, '2026-06-01 17:00:00', 2, 1.4, 1.6, 1.8, 68.0, 'high_risk', 'mobility + corrective work', '2026-06-15'),
(4, 4, 2, '2026-04-10 10:00:00', 3, 1.2, 1.3, 1.1, 82.0, 'critical', 'paused, medical clearance required', '2026-04-24'),
(5, 5, 1, '2026-06-25 19:00:00', 0, 2.9, 2.8, 2.7, 18.0, 'excellent', 'strength progression plan', '2026-07-25');

INSERT INTO injury_history
(injury_id, member_id, injury_date, body_area, injury_type, severity, current_status, doctor_clearance, notes)
VALUES
(1, 4, '2026-04-09', 'knee', 'tendon irritation', 'high', 'recovering', 0, 'avoid deep knee flexion'),
(2, 3, '2026-06-10', 'lower back', 'strain', 'medium', 'recovering', 0, 'reduce axial loading');

INSERT INTO daily_attendance
(attendance_id, member_id, coach_id, attendance_date, check_in_time, check_out_time, attended, session_type, notes)
VALUES
(1, 1, 1, '2026-07-01', '18:05:00', '19:10:00', 1, 'training', 'first day'),
(2, 1, 1, '2026-07-02', NULL, NULL, 0, 'training', 'no show'),
(3, 1, 1, '2026-07-03', NULL, NULL, 0, 'training', 'no show'),
(4, 1, 1, '2026-07-04', NULL, NULL, 0, 'training', 'no show'),
(5, 2, 1, '2026-07-01', '08:10:00', '09:15:00', 1, 'training', 'good session'),
(6, 3, 2, '2026-06-28', '17:20:00', '18:00:00', 1, 'training', 'light session'),
(7, 3, 2, '2026-07-01', NULL, NULL, 0, 'training', 'no show'),
(8, 3, 2, '2026-07-03', NULL, NULL, 0, 'training', 'no show'),
(9, 5, 1, '2026-07-02', '21:00:00', '22:15:00', 1, 'training', 'strong session');

INSERT INTO followups
(followup_id, member_id, coach_id, followup_date, reason, contact_channel, response_status, action_taken, next_followup_date)
VALUES
(1, 1, 1, '2026-07-05 12:00:00', 'no_show', 'whatsapp', 'no_response', 'Sent reminder', '2026-07-06 12:00:00'),
(2, 3, 2, '2026-07-04 11:00:00', 'low_attendance', 'call', 'replied', 'Member promised return', '2026-07-07 11:00:00'),
(3, 4, 2, '2026-04-11 10:00:00', 'injury', 'whatsapp', 'escalated', 'Requested medical clearance', '2026-04-15 10:00:00'),
(4, 5, 1, '2026-07-03 13:00:00', 'progress_review', 'in_person', 'converted', 'Discussed upgrade', NULL);

INSERT INTO progress_tracking
(progress_id, member_id, record_date, weight, body_fat, muscle_mass, waist, chest, hips, performance_note, photo_ref)
VALUES
(1, 1, '2026-07-01', 92.5, 28.0, 32.0, 96.0, 104.0, 102.0, 'baseline', NULL),
(2, 1, '2026-07-08', 90.1, 27.2, 32.5, 94.5, 104.5, 101.0, 'good drop', NULL),
(3, 2, '2026-06-20', 61.0, 24.0, 25.0, 71.0, 88.0, 92.0, 'baseline', NULL),
(4, 2, '2026-07-10', 61.5, 23.5, 25.6, 70.5, 88.5, 92.0, 'slight improvement', NULL),
(5, 5, '2026-06-25', 84.0, 16.0, 38.5, 84.0, 112.0, 100.0, 'excellent base', NULL),
(6, 5, '2026-07-10', 83.0, 15.2, 39.1, 83.0, 113.0, 99.5, 'progressing', NULL);

INSERT INTO workout_plans
(workout_plan_id, member_id, coach_id, goal_type, phase, start_date, end_date, status, notes)
VALUES
(1, 1, 1, 'fat_loss', 'corrective', '2026-07-01', '2026-07-31', 'active', 'entry plan'),
(2, 2, 1, 'muscle_gain', 'hypertrophy', '2026-06-20', '2026-09-18', 'active', 'split hypertrophy'),
(3, 3, 2, 'rehab', 'stabilization', '2026-06-01', '2026-06-30', 'paused', 'back pain protocol'),
(4, 4, 2, 'rehab', 'corrective', '2026-04-10', '2026-07-09', 'paused', 'knee rehab'),
(5, 5, 1, 'strength', 'strength', '2026-03-05', '2027-03-04', 'active', 'advanced program');

INSERT INTO workout_sessions
(session_id, workout_plan_id, session_date, muscle_group, exercises, sets_info, reps_info, load_info, intensity_info, completion_status, notes)
VALUES
(1, 1, '2026-07-01', 'full body', 'squat, row, press', '3', '12', 'light', 'moderate', 'completed', 'intro day'),
(2, 1, '2026-07-03', 'lower', 'goblet squat, lunge', '3', '10', 'light', 'moderate', 'partial', 'missed finisher'),
(3, 2, '2026-07-01', 'chest', 'bench, fly', '4', '10', 'moderate', 'high', 'completed', 'solid session'),
(4, 5, '2026-07-02', 'back', 'deadlift, row', '5', '5', 'heavy', 'high', 'completed', 'strong lifts');

INSERT INTO nutrition_plans
(nutrition_plan_id, member_id, coach_id, calories, protein_g, fat_g, carbs_g, hydration_target_l, meal_timing, refeed_protocol, diet_break_protocol, status)
VALUES
(1, 1, 1, 2200, 180.0, 70.0, 210.0, 3.00, '3 meals + 1 snack', 'every 10 days', 'every 8 weeks', 'active'),
(2, 2, 1, 2400, 170.0, 75.0, 250.0, 2.80, '4 meals', 'none', 'none', 'active'),
(3, 5, 1, 3200, 210.0, 90.0, 360.0, 4.00, 'pre/post training split', 'none', 'none', 'active');

INSERT INTO supplements
(supplement_id, member_id, supplement_name, dose, timing, purpose, active)
VALUES
(1, 1, 'Whey Protein', '1 scoop', 'post workout', 'protein support', 1),
(2, 1, 'Creatine', '5 g', 'daily', 'strength support', 1),
(3, 2, 'Creatine', '5 g', 'daily', 'performance', 1),
(4, 5, 'Omega 3', '2 caps', 'with meals', 'recovery', 1);

INSERT INTO retention_flags
(flag_id, member_id, assessment_id, flag_type, severity, status, detected_at, resolved_at, action_required, owner_coach_id)
VALUES
(1, 1, 1, 'low_attendance', 'high', 'open', '2026-07-04 20:00:00', NULL, 'Follow up after 3 no-shows', 1),
(2, 3, 3, 'no_progress', 'high', 'open', '2026-07-03 19:00:00', NULL, 'Corrective plan needed', 2),
(3, 4, 4, 'injury', 'critical', 'open', '2026-04-10 11:00:00', NULL, 'Medical clearance required', 2);

INSERT INTO tasks
(task_id, member_id, coach_id, flag_id, task_type, priority, status, due_at, completed_at, notes)
VALUES
(1, 1, 1, 1, 'call', 'urgent', 'open', '2026-07-05 12:00:00', NULL, 'Call member now'),
(2, 3, 2, 2, 'program_update', 'high', 'open', '2026-07-04 18:00:00', NULL, 'Update plan'),
(3, 4, 2, 3, 'medical_referral', 'urgent', 'open', '2026-04-10 12:00:00', NULL, 'Medical clearance'),
(4, 1, 1, NULL, 'reassess', 'medium', 'open', '2026-07-15 10:00:00', NULL, 'Recheck movement quality');

INSERT INTO messages_log
(message_id, member_id, coach_id, channel, message_type, content, sent_at, status)
VALUES
(1, 1, 1, 'whatsapp', 'welcome', 'Welcome to Xcamp. Your onboarding has started.', '2026-07-01 09:00:00', 'sent'),
(2, 1, 1, 'whatsapp', 'warning', 'We noticed missed visits. Please confirm your next session.', '2026-07-05 12:05:00', 'sent'),
(3, 3, 2, 'call', 'followup', 'Discussed attendance and pain management.', '2026-07-04 11:10:00', 'sent'),
(4, 5, 1, 'in_person', 'progress', 'Great improvement. Consider upgrade.', '2026-07-03 13:10:00', 'sent');

INSERT INTO milestones
(milestone_id, member_id, milestone_date, milestone_type, description, reward_status)
VALUES
(1, 2, '2026-07-10', 'attendance_streak', '7-day consistency achieved.', 'badge'),
(2, 5, '2026-07-10', 'strength_gain', 'New PR on deadlift.', 'gift');

INSERT INTO audit_logs
(audit_id, user_id, entity_name, entity_id, action_type, old_data, new_data)
VALUES
(1, 1, 'members', 1, 'insert', NULL, JSON_OBJECT('full_name','Omar Khaled','status','new')),
(2, 2, 'payments', 1, 'insert', NULL, JSON_OBJECT('status','paid','amount',3000.00));
