-- =============================================================================
-- xcamp-gym-sql : 08_workout_v2.sql  (ترقية إضافية — لا تمسّ المخطط الأصلي)
-- -----------------------------------------------------------------------------
-- المرحلة 1: هيكلة التمارين
--   exercises            مكتبة التمارين (اسم/مجموعة عضلية/أداة/فيديو)
--   session_exercises    تمارين كل جلسة بشكل مُهيكل (مجموعات/تكرارات/حمل/راحة/RPE)
-- المرحلة 2: قوالب البرامج
--   program_templates    قالب برنامج (هدف/مرحلة/أسابيع)
--   template_sessions    جلسات القالب (إزاحة اليوم داخل الأسبوع، تتكرر أسبوعيًا)
--   template_session_exercises  تمارين كل جلسة قالب
--
-- الملف قابل لإعادة التشغيل: CREATE IF NOT EXISTS + INSERT IGNORE بمعرّفات ثابتة.
-- =============================================================================

USE xcamp_gym;

-- يضمن قراءة النصوص العربية في هذا الملف بشكل صحيح أيًّا كان ترميز العميل
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS exercises (
  exercise_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  muscle_group VARCHAR(80) NOT NULL,
  equipment VARCHAR(120) NULL,
  video_url VARCHAR(255) NULL,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS session_exercises (
  session_exercise_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  exercise_id BIGINT UNSIGNED NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  sets TINYINT UNSIGNED NULL,
  reps VARCHAR(20) NULL,
  load_kg DECIMAL(6,2) NULL,
  rest_sec SMALLINT UNSIGNED NULL,
  rpe TINYINT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sessex_session FOREIGN KEY (session_id) REFERENCES workout_sessions(session_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_sessex_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(exercise_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX idx_sessex_session (session_id),
  INDEX idx_sessex_exercise (exercise_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS program_templates (
  template_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL UNIQUE,
  goal_type ENUM('fat_loss','muscle_gain','strength','rehab','performance','general_fitness') NOT NULL DEFAULT 'general_fitness',
  phase ENUM('corrective','stabilization','hypertrophy','strength','power','maintenance') NOT NULL DEFAULT 'stabilization',
  duration_weeks TINYINT UNSIGNED NOT NULL DEFAULT 4,
  description TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tpl_coach FOREIGN KEY (created_by) REFERENCES coaches(coach_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS template_sessions (
  template_session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id BIGINT UNSIGNED NOT NULL,
  day_offset TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0..6 داخل الأسبوع، تتكرر كل أسبوع
  title VARCHAR(120) NULL,
  muscle_group VARCHAR(80) NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tps_template FOREIGN KEY (template_id) REFERENCES program_templates(template_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_tps_template (template_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS template_session_exercises (
  tse_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_session_id BIGINT UNSIGNED NOT NULL,
  exercise_id BIGINT UNSIGNED NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  sets TINYINT UNSIGNED NULL,
  reps VARCHAR(20) NULL,
  load_note VARCHAR(40) NULL,     -- "خفيف" / "60% 1RM" — الحمل الفعلي يُسجَّل وقت التنفيذ
  rest_sec SMALLINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tse_tsession FOREIGN KEY (template_session_id) REFERENCES template_sessions(template_session_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_tse_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(exercise_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX idx_tse_tsession (template_session_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- مكتبة تمارين أساسية
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO exercises (exercise_id, name, muscle_group, equipment) VALUES
(1,  'Back Squat',        'legs',      'barbell'),
(2,  'Deadlift',          'back',      'barbell'),
(3,  'Bench Press',       'chest',     'barbell'),
(4,  'Overhead Press',    'shoulders', 'barbell'),
(5,  'Barbell Row',       'back',      'barbell'),
(6,  'Pull-up',           'back',      'bodyweight'),
(7,  'Dumbbell Lunge',    'legs',      'dumbbell'),
(8,  'Leg Press',         'legs',      'machine'),
(9,  'Lat Pulldown',      'back',      'machine'),
(10, 'Dumbbell Curl',     'arms',      'dumbbell'),
(11, 'Triceps Pushdown',  'arms',      'cable'),
(12, 'Plank',             'core',      'bodyweight'),
(13, 'Goblet Squat',      'legs',      'dumbbell'),
(14, 'Hip Thrust',        'glutes',    'barbell'),
(15, 'Seated Cable Row',  'back',      'cable');

-- -----------------------------------------------------------------------------
-- قالب جاهز: Full Body مبتدئ — 3 أيام × 4 أسابيع
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO program_templates (template_id, title, goal_type, phase, duration_weeks, description) VALUES
(1, 'Full Body مبتدئ — 3 أيام', 'general_fitness', 'stabilization', 4, 'برنامج تأسيسي لكامل الجسم، ثلاث جلسات أسبوعيًا.');

INSERT IGNORE INTO template_sessions (template_session_id, template_id, day_offset, title, muscle_group) VALUES
(1, 1, 0, 'Full Body A', 'full body'),
(2, 1, 2, 'Full Body B', 'full body'),
(3, 1, 4, 'Full Body C', 'full body');

INSERT IGNORE INTO template_session_exercises (tse_id, template_session_id, exercise_id, sort_order, sets, reps, load_note, rest_sec) VALUES
(1,  1, 13, 1, 3, '10-12', 'خفيف',  90),
(2,  1, 3,  2, 3, '8-10',  'متوسط', 120),
(3,  1, 15, 3, 3, '10-12', 'متوسط', 90),
(4,  1, 12, 4, 3, '30s',   NULL,    60),
(5,  2, 1,  1, 3, '8-10',  'متوسط', 120),
(6,  2, 4,  2, 3, '8-10',  'متوسط', 120),
(7,  2, 9,  3, 3, '10-12', 'متوسط', 90),
(8,  2, 10, 4, 2, '12',    'خفيف',  60),
(9,  3, 2,  1, 3, '5-6',   'متوسط', 180),
(10, 3, 7,  2, 3, '10',    'خفيف',  90),
(11, 3, 6,  3, 3, 'AMRAP', NULL,    120),
(12, 3, 11, 4, 2, '12',    'خفيف',  60);

-- -----------------------------------------------------------------------------
-- بيانات مُهيكلة لجلسات الـ seed (لعرض رسم تقدّم الأحمال مباشرة)
-- الجلسات 1و2 للعضو 1 (سكوات 60 ثم 65 كجم)، والجلسة 4 للعضو 5
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO session_exercises (session_exercise_id, session_id, exercise_id, sort_order, sets, reps, load_kg, rest_sec, rpe) VALUES
(1, 1, 13, 1, 3, '12', 20.00,  90, 6),
(2, 1, 3,  2, 3, '10', 40.00, 120, 7),
(3, 1, 1,  3, 3, '10', 60.00, 120, 7),
(4, 2, 1,  1, 3, '8',  65.00, 150, 8),
(5, 2, 7,  2, 3, '10', 12.00,  90, 7),
(6, 4, 2,  1, 5, '5', 120.00, 180, 8),
(7, 4, 5,  2, 4, '8',  60.00, 120, 7);
