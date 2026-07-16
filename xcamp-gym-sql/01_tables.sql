-- =============================================================================
-- xcamp-gym-sql : 01_tables.sql
-- -----------------------------------------------------------------------------
-- Schema for the xcamp_gym gym / coaching operations database.
-- All tables are InnoDB / utf8mb4. Drops run child-first so the file is
-- re-runnable; creates run parent-first.
-- =============================================================================

USE xcamp_gym;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS milestones;
DROP TABLE IF EXISTS messages_log;
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS retention_flags;
DROP TABLE IF EXISTS supplements;
DROP TABLE IF EXISTS nutrition_plans;
DROP TABLE IF EXISTS workout_sessions;
DROP TABLE IF EXISTS workout_plans;
DROP TABLE IF EXISTS progress_tracking;
DROP TABLE IF EXISTS followups;
DROP TABLE IF EXISTS daily_attendance;
DROP TABLE IF EXISTS injury_history;
DROP TABLE IF EXISTS assessments;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS memberships;
DROP TABLE IF EXISTS plans;
DROP TABLE IF EXISTS members;
DROP TABLE IF EXISTS coaches;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(191) UNIQUE,
  phone VARCHAR(30) UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','manager','coach','reception') NOT NULL DEFAULT 'coach',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE coaches (
  coach_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30),
  specialty VARCHAR(150),
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_coaches_user FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE members (
  member_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  gender ENUM('male','female','other') NULL,
  birth_date DATE NULL,
  phone VARCHAR(30) UNIQUE,
  email VARCHAR(191) UNIQUE,
  address VARCHAR(255) NULL,
  job_title VARCHAR(120) NULL,
  join_date DATE NOT NULL,
  status ENUM('new','onboarding','active','corrective','at_risk','paused','expired','reactivated','upgraded')
    NOT NULL DEFAULT 'new',
  preferred_time VARCHAR(50) NULL,
  goal_summary VARCHAR(255) NULL,
  coach_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_members_coach FOREIGN KEY (coach_id) REFERENCES coaches(coach_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE plans (
  plan_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_name VARCHAR(120) NOT NULL,
  duration_days INT NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  access_level ENUM('basic','standard','pro','elite') NOT NULL DEFAULT 'basic',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE memberships (
  membership_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  renewal_status ENUM('pending','renewed','expired','cancelled') NOT NULL DEFAULT 'pending',
  payment_status ENUM('unpaid','partial','paid','failed','refunded') NOT NULL DEFAULT 'unpaid',
  auto_renew TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_memberships_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_memberships_plan FOREIGN KEY (plan_id) REFERENCES plans(plan_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payments (
  payment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NULL,
  payment_date DATETIME NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('cash','card','bank_transfer','wallet','other') NOT NULL DEFAULT 'cash',
  status ENUM('pending','paid','failed','refunded','partial') NOT NULL DEFAULT 'pending',
  receipt_no VARCHAR(80) NULL,
  reference_no VARCHAR(120) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_payments_membership FOREIGN KEY (membership_id) REFERENCES memberships(membership_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE assessments (
  assessment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  coach_id BIGINT UNSIGNED NULL,
  assessment_date DATETIME NOT NULL,
  parq_risk_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  overhead_squat_score DECIMAL(5,2) NULL,
  posture_score DECIMAL(5,2) NULL,
  movement_score DECIMAL(5,2) NULL,
  risk_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  classification ENUM('excellent','good','moderate','high_risk','critical') NOT NULL DEFAULT 'moderate',
  recommendation TEXT NULL,
  next_review_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_assessments_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_assessments_coach FOREIGN KEY (coach_id) REFERENCES coaches(coach_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE injury_history (
  injury_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  injury_date DATE NOT NULL,
  body_area VARCHAR(120) NOT NULL,
  injury_type VARCHAR(120) NULL,
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  current_status ENUM('active','recovering','resolved','unknown') NOT NULL DEFAULT 'unknown',
  doctor_clearance TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_injury_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE daily_attendance (
  attendance_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  coach_id BIGINT UNSIGNED NULL,
  attendance_date DATE NOT NULL,
  check_in_time TIME NULL,
  check_out_time TIME NULL,
  attended TINYINT(1) NOT NULL DEFAULT 0,
  session_type VARCHAR(80) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_attendance_coach FOREIGN KEY (coach_id) REFERENCES coaches(coach_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE followups (
  followup_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  coach_id BIGINT UNSIGNED NULL,
  followup_date DATETIME NOT NULL,
  reason ENUM('no_show','low_attendance','payment_issue','injury','motivation','progress_review','other') NOT NULL DEFAULT 'other',
  contact_channel ENUM('call','whatsapp','sms','email','in_person','other') NOT NULL DEFAULT 'whatsapp',
  response_status ENUM('no_response','replied','booked','converted','escalated') NOT NULL DEFAULT 'no_response',
  action_taken TEXT NULL,
  next_followup_date DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_followups_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_followups_coach FOREIGN KEY (coach_id) REFERENCES coaches(coach_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE progress_tracking (
  progress_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  record_date DATE NOT NULL,
  weight DECIMAL(6,2) NULL,
  body_fat DECIMAL(5,2) NULL,
  muscle_mass DECIMAL(6,2) NULL,
  waist DECIMAL(6,2) NULL,
  chest DECIMAL(6,2) NULL,
  hips DECIMAL(6,2) NULL,
  performance_note TEXT NULL,
  photo_ref VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_progress_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE workout_plans (
  workout_plan_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  coach_id BIGINT UNSIGNED NULL,
  goal_type ENUM('fat_loss','muscle_gain','strength','rehab','performance','general_fitness') NOT NULL DEFAULT 'general_fitness',
  phase ENUM('corrective','stabilization','hypertrophy','strength','power','maintenance') NOT NULL DEFAULT 'stabilization',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  status ENUM('active','paused','completed','cancelled') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_workout_plans_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_workout_plans_coach FOREIGN KEY (coach_id) REFERENCES coaches(coach_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE workout_sessions (
  session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workout_plan_id BIGINT UNSIGNED NOT NULL,
  session_date DATE NOT NULL,
  muscle_group VARCHAR(80) NULL,
  exercises TEXT NULL,
  sets_info TEXT NULL,
  reps_info TEXT NULL,
  load_info TEXT NULL,
  intensity_info VARCHAR(80) NULL,
  completion_status ENUM('planned','partial','completed','missed') NOT NULL DEFAULT 'planned',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_workout_sessions_plan FOREIGN KEY (workout_plan_id) REFERENCES workout_plans(workout_plan_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE nutrition_plans (
  nutrition_plan_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  coach_id BIGINT UNSIGNED NULL,
  calories INT NULL,
  protein_g DECIMAL(6,2) NULL,
  fat_g DECIMAL(6,2) NULL,
  carbs_g DECIMAL(6,2) NULL,
  hydration_target_l DECIMAL(5,2) NULL,
  meal_timing TEXT NULL,
  refeed_protocol TEXT NULL,
  diet_break_protocol TEXT NULL,
  status ENUM('active','paused','completed','cancelled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_nutrition_plans_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_nutrition_plans_coach FOREIGN KEY (coach_id) REFERENCES coaches(coach_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE supplements (
  supplement_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  supplement_name VARCHAR(120) NOT NULL,
  dose VARCHAR(80) NULL,
  timing VARCHAR(120) NULL,
  purpose VARCHAR(200) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_supplements_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE retention_flags (
  flag_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  assessment_id BIGINT UNSIGNED NULL,
  flag_type ENUM('low_attendance','no_show','payment_failed','injury','low_motivation','no_progress','low_response','high_risk') NOT NULL,
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status ENUM('open','in_progress','resolved','dismissed') NOT NULL DEFAULT 'open',
  detected_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  action_required TEXT NULL,
  owner_coach_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_flags_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_flags_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(assessment_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_flags_owner FOREIGN KEY (owner_coach_id) REFERENCES coaches(coach_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE tasks (
  task_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NULL,
  coach_id BIGINT UNSIGNED NULL,
  flag_id BIGINT UNSIGNED NULL,
  task_type ENUM('call','whatsapp','reassess','program_update','payment_followup','medical_referral','manager_review','renewal') NOT NULL,
  priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  status ENUM('open','doing','done','cancelled') NOT NULL DEFAULT 'open',
  due_at DATETIME NULL,
  completed_at DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tasks_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_tasks_coach FOREIGN KEY (coach_id) REFERENCES coaches(coach_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_tasks_flag FOREIGN KEY (flag_id) REFERENCES retention_flags(flag_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE messages_log (
  message_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  coach_id BIGINT UNSIGNED NULL,
  channel ENUM('whatsapp','sms','email','call','in_person','other') NOT NULL DEFAULT 'whatsapp',
  message_type ENUM('welcome','followup','reminder','winback','renewal','progress','warning','other') NOT NULL DEFAULT 'other',
  content TEXT NOT NULL,
  sent_at DATETIME NOT NULL,
  status ENUM('sent','delivered','failed','replied') NOT NULL DEFAULT 'sent',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_messages_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_messages_coach FOREIGN KEY (coach_id) REFERENCES coaches(coach_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE milestones (
  milestone_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  milestone_date DATE NOT NULL,
  milestone_type ENUM('first_week','first_month','weight_loss','strength_gain','attendance_streak','program_completion','renewal','upgrade') NOT NULL,
  description VARCHAR(255) NOT NULL,
  reward_status ENUM('none','badge','gift','promotion','discount') NOT NULL DEFAULT 'none',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_milestones_member FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  entity_name VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  action_type ENUM('insert','update','delete','login','logout','export') NOT NULL,
  old_data JSON NULL,
  new_data JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;
