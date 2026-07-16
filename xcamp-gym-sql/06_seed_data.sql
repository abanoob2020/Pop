-- =============================================================================
-- xcamp-gym-sql : 06_seed_data.sql
-- -----------------------------------------------------------------------------
-- Deterministic sample data. Explicit primary keys keep foreign-key references
-- stable. Dates are expressed relative to CURRENT_DATE so the demo views
-- (expiring memberships, recent attendance, ...) return meaningful rows.
--
-- Passwords below are placeholder bcrypt-style hashes for demo only.
-- =============================================================================

USE xcamp_gym;

-- Clear existing data (child-first) so seeding is repeatable.
DELETE FROM audit_logs;
DELETE FROM milestones;
DELETE FROM messages_log;
DELETE FROM tasks;
DELETE FROM retention_flags;
DELETE FROM supplements;
DELETE FROM nutrition_plans;
DELETE FROM workout_sessions;
DELETE FROM workout_plans;
DELETE FROM progress_tracking;
DELETE FROM followups;
DELETE FROM daily_attendance;
DELETE FROM injury_history;
DELETE FROM assessments;
DELETE FROM payments;
DELETE FROM memberships;
DELETE FROM plans;
DELETE FROM members;
DELETE FROM coaches;
DELETE FROM users;

-- -----------------------------------------------------------------------------
-- Users (staff)
-- -----------------------------------------------------------------------------
INSERT INTO users (user_id, full_name, email, phone, password_hash, role) VALUES
  (1, 'Sara Admin',      'admin@xcamp.gym',    '01000000001', '$2y$10$demoDEMOdemoDEMOdemoDE01', 'admin'),
  (2, 'Omar Manager',    'manager@xcamp.gym',  '01000000002', '$2y$10$demoDEMOdemoDEMOdemoDE02', 'manager'),
  (3, 'Karim Coach',     'karim@xcamp.gym',    '01000000003', '$2y$10$demoDEMOdemoDEMOdemoDE03', 'coach'),
  (4, 'Mona Coach',      'mona@xcamp.gym',     '01000000004', '$2y$10$demoDEMOdemoDEMOdemoDE04', 'coach'),
  (5, 'Reception Desk',  'front@xcamp.gym',    '01000000005', '$2y$10$demoDEMOdemoDEMOdemoDE05', 'reception');

-- -----------------------------------------------------------------------------
-- Coaches
-- -----------------------------------------------------------------------------
INSERT INTO coaches (coach_id, user_id, full_name, phone, specialty, active) VALUES
  (1, 3, 'Karim Coach', '01000000003', 'Strength & Conditioning', 1),
  (2, 4, 'Mona Coach',  '01000000004', 'Weight Loss & Nutrition', 1),
  (3, NULL, 'Youssef Trainer', '01000000013', 'Bodybuilding', 1),
  (4, NULL, 'Lina Trainer',    '01000000014', 'Mobility & Rehab', 1),
  (5, NULL, 'Hassan Trainer',  '01000000015', 'Functional Training', 0);

-- -----------------------------------------------------------------------------
-- Plans
-- -----------------------------------------------------------------------------
INSERT INTO plans (plan_id, name, description, price, duration_days, sessions_per_week, plan_type, is_active) VALUES
  (1, 'Monthly Basic',    'Gym access, 30 days',              500.00,  30, NULL, 'monthly',   1),
  (2, 'Quarterly',        'Gym access, 90 days',              1350.00, 90, NULL, 'quarterly', 1),
  (3, 'Half Year',        'Gym access, 180 days',             2400.00, 180, NULL, 'half_year', 1),
  (4, 'Annual',           'Gym access, 365 days',             4200.00, 365, NULL, 'annual',    1),
  (5, 'PT 12 Sessions',   'Personal training, 12 sessions',   1800.00, 45,  3,    'pt',        1),
  (6, 'Online Coaching',  'Remote programming, 30 days',      700.00,  30,  NULL, 'online',    1);

-- -----------------------------------------------------------------------------
-- Members (mix of statuses; coach assignments)
-- -----------------------------------------------------------------------------
INSERT INTO members (member_id, full_name, gender, birth_date, phone, email, join_date, status, preferred_time, goal_summary, coach_id) VALUES
  (1,  'Ahmed Hassan',   'male',   '1992-04-11', '01100000001', 'ahmed@example.com',   DATE_SUB(CURRENT_DATE, INTERVAL 400 DAY), 'active',      'evening', 'Build muscle',        1),
  (2,  'Nour Ali',       'female', '1995-09-23', '01100000002', 'nour@example.com',    DATE_SUB(CURRENT_DATE, INTERVAL 200 DAY), 'active',      'morning', 'Lose 8kg',            2),
  (3,  'Mohamed Adel',   'male',   '1988-01-05', '01100000003', 'mohamed@example.com', DATE_SUB(CURRENT_DATE, INTERVAL 120 DAY), 'active',      'evening', 'Strength',            1),
  (4,  'Salma Tarek',    'female', '2000-12-30', '01100000004', 'salma@example.com',   DATE_SUB(CURRENT_DATE, INTERVAL 90 DAY),  'corrective',  'noon',    'Posture / back pain', 4),
  (5,  'Youssef Sami',   'male',   '1990-07-19', '01100000005', 'youssefs@example.com',DATE_SUB(CURRENT_DATE, INTERVAL 60 DAY),  'active',      'evening', 'Recomp',              3),
  (6,  'Habiba Ezz',     'female', '1997-03-14', '01100000006', 'habiba@example.com',  DATE_SUB(CURRENT_DATE, INTERVAL 45 DAY),  'onboarding',  'morning', 'General fitness',     2),
  (7,  'Khaled Fathy',   'male',   '1985-11-02', '01100000007', 'khaled@example.com',  DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY),  'active',      'evening', 'Fat loss',            1),
  (8,  'Reem Adham',     'female', '1999-06-08', '01100000008', 'reem@example.com',    DATE_SUB(CURRENT_DATE, INTERVAL 25 DAY),  'at_risk',     'noon',    'Toning',              2),
  (9,  'Tarek Nabil',    'male',   '1993-02-27', '01100000009', 'tarek@example.com',   DATE_SUB(CURRENT_DATE, INTERVAL 20 DAY),  'active',      'evening', 'Bulk',                3),
  (10, 'Farida Gamal',   'female', '2001-08-17', '01100000010', 'farida@example.com',  DATE_SUB(CURRENT_DATE, INTERVAL 15 DAY),  'active',      'morning', 'Endurance',           4),
  (11, 'Amr Sayed',      'male',   '1987-05-21', '01100000011', 'amr@example.com',     DATE_SUB(CURRENT_DATE, INTERVAL 220 DAY), 'expired',     'evening', 'Return to training',  1),
  (12, 'Dina Wael',      'female', '1994-10-10', '01100000012', 'dina@example.com',    DATE_SUB(CURRENT_DATE, INTERVAL 300 DAY), 'paused',      'morning', 'Postpartum',          2),
  (13, 'Sherif Magdy',   'male',   '1991-12-01', '01100000016', 'sherif@example.com',  DATE_SUB(CURRENT_DATE, INTERVAL 10 DAY),  'new',         'evening', 'Start lifting',       NULL),
  (14, 'Yara Fouad',     'female', '1996-04-04', '01100000017', 'yara@example.com',    DATE_SUB(CURRENT_DATE, INTERVAL 500 DAY), 'reactivated', 'noon',    'Get back in shape',   3),
  (15, 'Marwan Hesham',  'male',   '1989-09-09', '01100000018', 'marwan@example.com',  DATE_SUB(CURRENT_DATE, INTERVAL 80 DAY),  'upgraded',    'evening', 'Powerlifting',        1);

-- -----------------------------------------------------------------------------
-- Memberships (end_date chosen to exercise the "expiring soon" / expired views)
-- -----------------------------------------------------------------------------
INSERT INTO memberships (membership_id, member_id, plan_id, start_date, end_date, status, price_paid, sessions_total, sessions_used, auto_renew) VALUES
  (1,  1,  4, DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY),  DATE_ADD(CURRENT_DATE, INTERVAL 335 DAY), 'active',   4200.00, NULL, 0, 1),
  (2,  2,  2, DATE_SUB(CURRENT_DATE, INTERVAL 80 DAY),  DATE_ADD(CURRENT_DATE, INTERVAL 10 DAY),  'active',   1350.00, NULL, 0, 0),
  (3,  3,  1, DATE_SUB(CURRENT_DATE, INTERVAL 25 DAY),  DATE_ADD(CURRENT_DATE, INTERVAL 5 DAY),   'active',   500.00,  NULL, 0, 0),
  (4,  4,  5, DATE_SUB(CURRENT_DATE, INTERVAL 20 DAY),  DATE_ADD(CURRENT_DATE, INTERVAL 25 DAY),  'active',   1800.00, 12,   4, 0),
  (5,  5,  2, DATE_SUB(CURRENT_DATE, INTERVAL 60 DAY),  DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY),  'active',   1350.00, NULL, 0, 0),
  (6,  6,  1, DATE_SUB(CURRENT_DATE, INTERVAL 12 DAY),  DATE_ADD(CURRENT_DATE, INTERVAL 18 DAY),  'active',   500.00,  NULL, 0, 0),
  (7,  7,  1, DATE_SUB(CURRENT_DATE, INTERVAL 8 DAY),   DATE_ADD(CURRENT_DATE, INTERVAL 22 DAY),  'active',   500.00,  NULL, 0, 0),
  (8,  8,  1, DATE_SUB(CURRENT_DATE, INTERVAL 25 DAY),  DATE_ADD(CURRENT_DATE, INTERVAL 5 DAY),   'active',   500.00,  NULL, 0, 0),
  (9,  9,  3, DATE_SUB(CURRENT_DATE, INTERVAL 20 DAY),  DATE_ADD(CURRENT_DATE, INTERVAL 160 DAY), 'active',   2400.00, NULL, 0, 1),
  (10, 10, 6, DATE_SUB(CURRENT_DATE, INTERVAL 15 DAY),  DATE_ADD(CURRENT_DATE, INTERVAL 15 DAY),  'active',   700.00,  NULL, 0, 0),
  (11, 11, 1, DATE_SUB(CURRENT_DATE, INTERVAL 220 DAY), DATE_SUB(CURRENT_DATE, INTERVAL 190 DAY), 'expired',  500.00,  NULL, 0, 0),
  (12, 12, 2, DATE_SUB(CURRENT_DATE, INTERVAL 300 DAY), DATE_SUB(CURRENT_DATE, INTERVAL 210 DAY), 'frozen',   1350.00, NULL, 0, 0),
  (14, 14, 4, DATE_SUB(CURRENT_DATE, INTERVAL 5 DAY),   DATE_ADD(CURRENT_DATE, INTERVAL 360 DAY), 'active',   4200.00, NULL, 0, 1),
  (15, 15, 3, DATE_SUB(CURRENT_DATE, INTERVAL 40 DAY),  DATE_ADD(CURRENT_DATE, INTERVAL 140 DAY), 'active',   2400.00, NULL, 0, 0);

-- -----------------------------------------------------------------------------
-- Payments
-- -----------------------------------------------------------------------------
INSERT INTO payments (member_id, membership_id, amount, method, status, paid_at, reference) VALUES
  (1,  1,  4200.00, 'card',     'paid', DATE_SUB(NOW(), INTERVAL 30 DAY), 'INV-1001'),
  (2,  2,  1350.00, 'cash',     'paid', DATE_SUB(NOW(), INTERVAL 80 DAY), 'INV-1002'),
  (3,  3,  500.00,  'cash',     'paid', DATE_SUB(NOW(), INTERVAL 25 DAY), 'INV-1003'),
  (4,  4,  1800.00, 'transfer', 'paid', DATE_SUB(NOW(), INTERVAL 20 DAY), 'INV-1004'),
  (5,  5,  1350.00, 'card',     'paid', DATE_SUB(NOW(), INTERVAL 60 DAY), 'INV-1005'),
  (6,  6,  500.00,  'online',   'paid', DATE_SUB(NOW(), INTERVAL 12 DAY), 'INV-1006'),
  (7,  7,  500.00,  'cash',     'paid', DATE_SUB(NOW(), INTERVAL 8 DAY),  'INV-1007'),
  (8,  8,  500.00,  'cash',     'paid', DATE_SUB(NOW(), INTERVAL 25 DAY), 'INV-1008'),
  (9,  9,  2400.00, 'card',     'paid', DATE_SUB(NOW(), INTERVAL 20 DAY), 'INV-1009'),
  (10, 10, 700.00,  'online',   'paid', DATE_SUB(NOW(), INTERVAL 15 DAY), 'INV-1010'),
  (11, 11, 500.00,  'cash',     'paid', DATE_SUB(NOW(), INTERVAL 220 DAY),'INV-1011'),
  (14, 14, 4200.00, 'card',     'paid', DATE_SUB(NOW(), INTERVAL 5 DAY),  'INV-1014'),
  (15, 15, 2400.00, 'transfer', 'paid', DATE_SUB(NOW(), INTERVAL 40 DAY), 'INV-1015'),
  (4,  NULL, 200.00, 'cash',    'pending', DATE_SUB(NOW(), INTERVAL 2 DAY),'INV-1099');

-- -----------------------------------------------------------------------------
-- Assessments
-- -----------------------------------------------------------------------------
INSERT INTO assessments (member_id, coach_id, assessed_on, weight_kg, height_cm, body_fat_pct, muscle_mass_kg, bmi, resting_hr, notes) VALUES
  (1, 1, DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY), 82.50, 178.0, 18.20, 35.10, 26.04, 62, 'Baseline'),
  (2, 2, DATE_SUB(CURRENT_DATE, INTERVAL 25 DAY), 70.00, 165.0, 30.10, 24.30, 25.71, 70, 'Weight-loss start'),
  (4, 4, DATE_SUB(CURRENT_DATE, INTERVAL 18 DAY), 58.00, 160.0, 24.00, 22.00, 22.66, 68, 'Back pain, mobility limited'),
  (9, 3, DATE_SUB(CURRENT_DATE, INTERVAL 10 DAY), 75.00, 180.0, 15.00, 34.00, 23.15, 58, 'Lean bulk baseline');

-- -----------------------------------------------------------------------------
-- Injury history
-- -----------------------------------------------------------------------------
INSERT INTO injury_history (member_id, injury_type, body_part, severity, occurred_on, resolved_on, notes) VALUES
  (4, 'Lower back strain', 'Lumbar', 'moderate', DATE_SUB(CURRENT_DATE, INTERVAL 100 DAY), NULL, 'Avoid heavy deadlifts'),
  (1, 'Shoulder impingement', 'Right shoulder', 'mild', DATE_SUB(CURRENT_DATE, INTERVAL 200 DAY), DATE_SUB(CURRENT_DATE, INTERVAL 150 DAY), 'Resolved with rehab');

-- -----------------------------------------------------------------------------
-- Daily attendance (recent, to feed the 30-day view)
-- -----------------------------------------------------------------------------
INSERT INTO daily_attendance (member_id, attend_date, check_in_at, check_out_at, source) VALUES
  (1, DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY),  DATE_SUB(NOW(), INTERVAL 1 DAY),  NULL, 'kiosk'),
  (1, DATE_SUB(CURRENT_DATE, INTERVAL 3 DAY),  DATE_SUB(NOW(), INTERVAL 3 DAY),  DATE_SUB(NOW(), INTERVAL 3 DAY) + INTERVAL 90 MINUTE, 'kiosk'),
  (1, DATE_SUB(CURRENT_DATE, INTERVAL 5 DAY),  DATE_SUB(NOW(), INTERVAL 5 DAY),  NULL, 'app'),
  (2, DATE_SUB(CURRENT_DATE, INTERVAL 2 DAY),  DATE_SUB(NOW(), INTERVAL 2 DAY),  NULL, 'kiosk'),
  (2, DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY),  DATE_SUB(NOW(), INTERVAL 6 DAY),  NULL, 'kiosk'),
  (3, DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY),  DATE_SUB(NOW(), INTERVAL 1 DAY),  NULL, 'manual'),
  (5, DATE_SUB(CURRENT_DATE, INTERVAL 4 DAY),  DATE_SUB(NOW(), INTERVAL 4 DAY),  NULL, 'kiosk'),
  (7, DATE_SUB(CURRENT_DATE, INTERVAL 2 DAY),  DATE_SUB(NOW(), INTERVAL 2 DAY),  NULL, 'kiosk'),
  (9, DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY),  DATE_SUB(NOW(), INTERVAL 1 DAY),  NULL, 'app'),
  (10, DATE_SUB(CURRENT_DATE, INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY),  NULL, 'kiosk');

-- -----------------------------------------------------------------------------
-- Follow-ups
-- -----------------------------------------------------------------------------
INSERT INTO followups (member_id, coach_id, due_date, channel, status, outcome, notes) VALUES
  (8,  2, DATE_SUB(CURRENT_DATE, INTERVAL 2 DAY), 'call',     'pending', NULL, 'Missed last 2 weeks'),
  (6,  2, DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY), 'whatsapp', 'pending', NULL, 'Onboarding check-in'),
  (11, 1, DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY), 'call',     'pending', NULL, 'Win-back offer'),
  (2,  2, DATE_SUB(CURRENT_DATE, INTERVAL 5 DAY), 'email',    'done',    'Renewed for another quarter', 'Positive');

-- -----------------------------------------------------------------------------
-- Progress tracking
-- -----------------------------------------------------------------------------
INSERT INTO progress_tracking (member_id, log_date, weight_kg, waist_cm, chest_cm, arm_cm, notes) VALUES
  (2, DATE_SUB(CURRENT_DATE, INTERVAL 25 DAY), 70.00, 82.0, 95.0, 28.0, 'Start'),
  (2, DATE_SUB(CURRENT_DATE, INTERVAL 5 DAY),  67.50, 79.0, 94.0, 28.5, 'Down 2.5kg'),
  (1, DATE_SUB(CURRENT_DATE, INTERVAL 20 DAY), 82.50, 85.0, 104.0, 38.0, 'Baseline'),
  (1, DATE_SUB(CURRENT_DATE, INTERVAL 2 DAY),  84.00, 85.0, 106.0, 39.0, 'Gaining');

-- -----------------------------------------------------------------------------
-- Workout plans + sessions
-- -----------------------------------------------------------------------------
INSERT INTO workout_plans (workout_plan_id, member_id, coach_id, goal_type, phase, start_date, end_date, status) VALUES
  (1, 1, 1, 'muscle_gain', 'hypertrophy',   DATE_SUB(CURRENT_DATE, INTERVAL 28 DAY), DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY), 'active'),
  (2, 2, 2, 'fat_loss',    'stabilization', DATE_SUB(CURRENT_DATE, INTERVAL 24 DAY), DATE_ADD(CURRENT_DATE, INTERVAL 36 DAY), 'active'),
  (3, 4, 4, 'rehab',       'corrective',    DATE_SUB(CURRENT_DATE, INTERVAL 18 DAY), DATE_ADD(CURRENT_DATE, INTERVAL 42 DAY), 'active');

INSERT INTO workout_sessions (workout_plan_id, session_date, muscle_group, exercises, sets_info, reps_info, load_info, intensity_info, completion_status) VALUES
  (1, DATE_SUB(CURRENT_DATE, INTERVAL 3 DAY), 'Chest/Shoulders/Triceps', 'Bench press; Overhead press; Dips', '4;3;3', '8-10;8-10;12', '80kg;45kg;BW', 'RPE 8',  'completed'),
  (1, DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY), 'Back/Biceps',             'Deadlift; Barbell row; Curls',     '3;4;3', '5;10;12',     '120kg;60kg;15kg', 'RPE 7', 'completed'),
  (1, DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY), 'Legs',                    'Squat; RDL; Leg press',            '4;3;3', '8;10;12',     'TBD', 'RPE 8',        'planned'),
  (2, DATE_SUB(CURRENT_DATE, INTERVAL 2 DAY), 'Full body circuit',       'Circuit A x3 rounds',              '3',     '15',          'light', 'high',       'completed'),
  (3, DATE_SUB(CURRENT_DATE, INTERVAL 4 DAY), 'Core/Mobility',           'McGill big 3; Cat-camel',          '3',     '30s hold',    'BW', 'low',           'missed');

-- -----------------------------------------------------------------------------
-- Nutrition plans + supplements
-- -----------------------------------------------------------------------------
INSERT INTO nutrition_plans (nutrition_plan_id, member_id, coach_id, calories, protein_g, fat_g, carbs_g, hydration_target_l, meal_timing, refeed_protocol, diet_break_protocol, status) VALUES
  (1, 2, 2, 1600, 130.00, 45.00, 150.00, 2.50, '4 meals, protein at each',        'Weekly high-carb refeed on training day', 'Diet break at week 8', 'active'),
  (2, 1, 1, 2900, 180.00, 80.00, 320.00, 3.50, '5 meals, carbs around training',  NULL,                                       NULL,                   'active');

INSERT INTO supplements (member_id, supplement_name, dose, timing, purpose, active) VALUES
  (2, 'Whey Protein',        '30g', 'Post-workout',              'Hit daily protein target', 1),
  (1, 'Creatine Monohydrate','5g',  'Daily, any time',           'Strength & size',          1),
  (1, 'Whey Protein',        '40g', 'Post-workout / breakfast',  'Hit daily protein target', 1);

-- -----------------------------------------------------------------------------
-- Retention flags
-- -----------------------------------------------------------------------------
INSERT INTO retention_flags (flag_id, member_id, flag_type, severity, status, detected_at, resolved_at, action_required, owner_coach_id) VALUES
  (1, 8,  'low_attendance', 'high',   'open',        DATE_SUB(NOW(), INTERVAL 3 DAY),  NULL,                            'No attendance in 2 weeks after joining; call to re-engage', 2),
  (2, 11, 'low_response',   'medium', 'open',        DATE_SUB(NOW(), INTERVAL 10 DAY), NULL,                            'Win-back: membership expired ~6 months ago',              1),
  (3, 12, 'payment_failed', 'low',    'in_progress', DATE_SUB(NOW(), INTERVAL 7 DAY),  NULL,                            'Frozen membership, balance pending',                      2),
  (4, 2,  'low_attendance', 'low',    'resolved',    DATE_SUB(NOW(), INTERVAL 40 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY), 'Old flag, since resolved',                                2);

-- -----------------------------------------------------------------------------
-- Tasks
-- -----------------------------------------------------------------------------
INSERT INTO tasks (member_id, coach_id, flag_id, task_type, priority, status, due_at, notes) VALUES
  (8,    2,    1,    'call',             'high',   'open',  DATE_ADD(NOW(), INTERVAL 1 DAY),  'Reactivation call for Reem (low attendance)'),
  (NULL, NULL, NULL, 'manager_review',   'medium', 'doing', DATE_ADD(NOW(), INTERVAL 5 DAY),  'Prepare quarterly revenue report'),
  (12,   2,    3,    'payment_followup', 'medium', 'open',  DATE_ADD(NOW(), INTERVAL 2 DAY),  'Chase pending balance on frozen membership'),
  (11,   1,    2,    'whatsapp',         'medium', 'open',  DATE_ADD(NOW(), INTERVAL 3 DAY),  'Win-back message to Amr');

-- -----------------------------------------------------------------------------
-- Messages log
-- -----------------------------------------------------------------------------
INSERT INTO messages_log (member_id, user_id, channel, direction, subject, body, status, sent_at) VALUES
  (8,  4, 'whatsapp', 'outbound', 'We miss you!', 'Hi Reem, we noticed you have not been in. Everything ok?', 'delivered', DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (2,  2, 'email',    'outbound', 'Renewal receipt', 'Thanks for renewing your quarterly membership.', 'sent', DATE_SUB(NOW(), INTERVAL 5 DAY)),
  (11, 3, 'sms',      'outbound', 'Win-back offer', 'Come back with 20% off this month.', 'sent', DATE_SUB(NOW(), INTERVAL 10 DAY)),
  (6,  NULL, 'app',   'inbound',  NULL, 'What time do classes start tomorrow?', 'read', DATE_SUB(NOW(), INTERVAL 1 DAY));

-- -----------------------------------------------------------------------------
-- Milestones
-- -----------------------------------------------------------------------------
INSERT INTO milestones (member_id, milestone_type, title, achieved_on, value, notes) VALUES
  (2, 'weight_goal', 'Lost first 2.5kg', DATE_SUB(CURRENT_DATE, INTERVAL 5 DAY), '-2.5kg', NULL),
  (1, 'attendance',  '20 sessions completed', DATE_SUB(CURRENT_DATE, INTERVAL 3 DAY), '20', NULL),
  (1, 'anniversary', '1 year with xcamp', DATE_SUB(CURRENT_DATE, INTERVAL 35 DAY), '1yr', 'Loyal member');

-- -----------------------------------------------------------------------------
-- Demonstrate a stored procedure end-to-end: register a 16th member with their
-- first membership + payment in one atomic call.
-- -----------------------------------------------------------------------------
SET @new_member := NULL;
CALL sp_register_member(
  'Laila Mostafa', '01100000019', 'laila@example.com', CURRENT_DATE,
  2, 1, 500.00, 'card', @new_member
);
SELECT @new_member AS registered_member_id;
