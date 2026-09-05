-- =============================================================================
-- check_reparenting.sql — READ-ONLY temporal anomaly detection
-- =============================================================================
-- Detects rows where a child record's created_at is OLDER than its parent's
-- created_at. This is impossible under normal operation and indicates that
-- parent tables were dropped and recreated (reparenting attack / accidental
-- destructive deploy) while child tables survived via IF NOT EXISTS.
--
-- Safe to run at any time. SELECT-only, no modifications.
-- Exit: any rows returned = reparenting detected.
-- =============================================================================

SELECT '=== REPARENTING CHECK ===' AS status;

-- ── 01_tables.sql FK relationships ──────────────────────────────────────────

SELECT 'coaches → users' AS relationship,
       c.coach_id, c.created_at AS child_created, u.created_at AS parent_created
FROM coaches c JOIN users u ON u.user_id = c.user_id
WHERE c.created_at < u.created_at;

SELECT 'members → coaches' AS relationship,
       m.member_id, m.created_at AS child_created, c.created_at AS parent_created
FROM members m JOIN coaches c ON c.coach_id = m.coach_id
WHERE m.created_at < c.created_at;

SELECT 'memberships → members' AS relationship,
       ms.membership_id, ms.created_at AS child_created, m.created_at AS parent_created
FROM memberships ms JOIN members m ON m.member_id = ms.member_id
WHERE ms.created_at < m.created_at;

SELECT 'memberships → plans' AS relationship,
       ms.membership_id, ms.created_at AS child_created, p.created_at AS parent_created
FROM memberships ms JOIN plans p ON p.plan_id = ms.plan_id
WHERE ms.created_at < p.created_at;

SELECT 'payments → members' AS relationship,
       py.payment_id, py.created_at AS child_created, m.created_at AS parent_created
FROM payments py JOIN members m ON m.member_id = py.member_id
WHERE py.created_at < m.created_at;

SELECT 'payments → memberships' AS relationship,
       py.payment_id, py.created_at AS child_created, ms.created_at AS parent_created
FROM payments py JOIN memberships ms ON ms.membership_id = py.membership_id
WHERE py.created_at < ms.created_at;

SELECT 'assessments → members' AS relationship,
       a.assessment_id, a.created_at AS child_created, m.created_at AS parent_created
FROM assessments a JOIN members m ON m.member_id = a.member_id
WHERE a.created_at < m.created_at;

SELECT 'assessments → coaches' AS relationship,
       a.assessment_id, a.created_at AS child_created, c.created_at AS parent_created
FROM assessments a JOIN coaches c ON c.coach_id = a.coach_id
WHERE a.created_at < c.created_at;

SELECT 'daily_attendance → members' AS relationship,
       da.attendance_id, da.created_at AS child_created, m.created_at AS parent_created
FROM daily_attendance da JOIN members m ON m.member_id = da.member_id
WHERE da.created_at < m.created_at;

SELECT 'daily_attendance → coaches' AS relationship,
       da.attendance_id, da.created_at AS child_created, c.created_at AS parent_created
FROM daily_attendance da JOIN coaches c ON c.coach_id = da.check_in_by
WHERE da.created_at < c.created_at;

SELECT 'followups → members' AS relationship,
       f.followup_id, f.created_at AS child_created, m.created_at AS parent_created
FROM followups f JOIN members m ON m.member_id = f.member_id
WHERE f.created_at < m.created_at;

SELECT 'workout_plans → members' AS relationship,
       wp.workout_plan_id, wp.created_at AS child_created, m.created_at AS parent_created
FROM workout_plans wp JOIN members m ON m.member_id = wp.member_id
WHERE wp.created_at < m.created_at;

SELECT 'workout_plans → coaches' AS relationship,
       wp.workout_plan_id, wp.created_at AS child_created, c.created_at AS parent_created
FROM workout_plans wp JOIN coaches c ON c.coach_id = wp.coach_id
WHERE wp.created_at < c.created_at;

SELECT 'nutrition_plans → members' AS relationship,
       np.nutrition_plan_id, np.created_at AS child_created, m.created_at AS parent_created
FROM nutrition_plans np JOIN members m ON m.member_id = np.member_id
WHERE np.created_at < m.created_at;

SELECT 'nutrition_plans → coaches' AS relationship,
       np.nutrition_plan_id, np.created_at AS child_created, c.created_at AS parent_created
FROM nutrition_plans np JOIN coaches c ON c.coach_id = np.coach_id
WHERE np.created_at < c.created_at;

SELECT 'retention_flags → members' AS relationship,
       rf.flag_id, rf.created_at AS child_created, m.created_at AS parent_created
FROM retention_flags rf JOIN members m ON m.member_id = rf.member_id
WHERE rf.created_at < m.created_at;

SELECT 'tasks → members' AS relationship,
       t.task_id, t.created_at AS child_created, m.created_at AS parent_created
FROM tasks t JOIN members m ON m.member_id = t.member_id
WHERE t.created_at < m.created_at;

-- ── 08→19 migration FK relationships (child tables that survive DROPs) ──────

SELECT 'member_auth → members (10)' AS relationship,
       ma.member_id, ma.created_at AS child_created, m.created_at AS parent_created
FROM member_auth ma JOIN members m ON m.member_id = ma.member_id
WHERE ma.created_at < m.created_at;

SELECT 'meal_logs → members (09)' AS relationship,
       ml.log_id, ml.created_at AS child_created, m.created_at AS parent_created
FROM meal_logs ml JOIN members m ON m.member_id = ml.member_id
WHERE ml.created_at < m.created_at;

SELECT 'pos_sales → members (11)' AS relationship,
       ps.sale_id, ps.created_at AS child_created, m.created_at AS parent_created
FROM pos_sales ps JOIN members m ON m.member_id = ps.member_id
WHERE ps.member_id IS NOT NULL AND ps.created_at < m.created_at;

SELECT 'qr_tokens → members (12)' AS relationship,
       qt.token_id, qt.created_at AS child_created, m.created_at AS parent_created
FROM qr_tokens qt JOIN members m ON m.member_id = qt.member_id
WHERE qt.created_at < m.created_at;

SELECT 'coach_contracts → coaches (13)' AS relationship,
       cc.contract_id, cc.created_at AS child_created, c.created_at AS parent_created
FROM coach_contracts cc JOIN coaches c ON c.coach_id = cc.coach_id
WHERE cc.created_at < c.created_at;

SELECT 'coach_shifts → coaches (13)' AS relationship,
       cs.shift_id, cs.created_at AS child_created, c.created_at AS parent_created
FROM coach_shifts cs JOIN coaches c ON c.coach_id = cs.coach_id
WHERE cs.created_at < c.created_at;

SELECT 'coach_payroll → coaches (13)' AS relationship,
       cp.payroll_id, cp.created_at AS child_created, c.created_at AS parent_created
FROM coach_payroll cp JOIN coaches c ON c.coach_id = cp.coach_id
WHERE cp.created_at < c.created_at;

SELECT 'pt_sessions → members (14)' AS relationship,
       pt.session_id, pt.created_at AS child_created, m.created_at AS parent_created
FROM pt_sessions pt JOIN members m ON m.member_id = pt.member_id
WHERE pt.created_at < m.created_at;

SELECT 'pt_sessions → coaches (14)' AS relationship,
       pt.session_id, pt.created_at AS child_created, c.created_at AS parent_created
FROM pt_sessions pt JOIN coaches c ON c.coach_id = pt.coach_id
WHERE pt.created_at < c.created_at;

SELECT 'discount_codes → members (15)' AS relationship,
       dc.code_id, dc.created_at AS child_created, m.created_at AS parent_created
FROM discount_codes dc JOIN members m ON m.member_id = dc.owner_member_id
WHERE dc.owner_member_id IS NOT NULL AND dc.created_at < m.created_at;

SELECT 'member_assessments → members (16)' AS relationship,
       ma.assessment_id, ma.created_at AS child_created, m.created_at AS parent_created
FROM member_assessments ma JOIN members m ON m.member_id = ma.member_id
WHERE ma.created_at < m.created_at;

SELECT 'program_templates → coaches (08)' AS relationship,
       pt.template_id, pt.created_at AS child_created, c.created_at AS parent_created
FROM program_templates pt JOIN coaches c ON c.coach_id = pt.created_by
WHERE pt.created_at < c.created_at;

SELECT 'training_max → members (19)' AS relationship,
       tm.max_id, tm.created_at AS child_created, m.created_at AS parent_created
FROM training_max tm JOIN members m ON m.member_id = tm.member_id
WHERE tm.created_at < m.created_at;

SELECT '=== CHECK COMPLETE ===' AS status;
