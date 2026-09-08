"""Import all models so that ``Base.metadata`` is fully populated.

Importing this package (``from app.db import models``) registers every table on
the shared metadata, which Alembic's env.py and the test-suite rely on.
"""

from __future__ import annotations

from app.db.base import Base  # noqa: F401
from app.db.models.identity import (  # noqa: F401
    Branch,
    Coach,
    CoachMemberAssignment,
    ExternalReference,
    Member,
)
from app.db.models.mos_mirror import AttendanceEvent, MembershipSnapshot  # noqa: F401
from app.db.models.fitness_profile import MemberFitnessProfile, MemberGoal  # noqa: F401
from app.db.models.assessment import (  # noqa: F401
    Assessment,
    AssessmentFlag,
    AssessmentMetric,
)
from app.db.models.exercise_library import (  # noqa: F401
    Exercise,
    ExerciseEquipment,
    ExerciseInstruction,
    ExerciseMedia,
    ExerciseMuscle,
)
from app.db.models.programming import (  # noqa: F401
    ProgramPhase,
    ProgramSession,
    ProgramSessionExercise,
    ProgramTemplate,
    ProgramVersion,
    ProgramWeek,
    SetPrescription,
)
from app.db.models.assignments import AssignmentSessionState, ProgramAssignment  # noqa: F401
from app.db.models.execution import (  # noqa: F401
    WorkoutExercise,
    WorkoutSession,
    WorkoutSet,
)
from app.db.models.progression import (  # noqa: F401
    AssignmentProgressionRule,
    ProgressionEvent,
    ProgressionPolicy,
    ProgressionState,
)
from app.db.models.progress import BodyMeasurement  # noqa: F401
from app.db.models.coaching import CoachingIntervention, CoachNote  # noqa: F401
from app.db.models.audit import AuditEvent, DomainEvent  # noqa: F401

__all__ = [
    "Base",
    "Branch",
    "Coach",
    "CoachMemberAssignment",
    "ExternalReference",
    "Member",
    "AttendanceEvent",
    "MembershipSnapshot",
    "MemberFitnessProfile",
    "MemberGoal",
    "Assessment",
    "AssessmentFlag",
    "AssessmentMetric",
    "Exercise",
    "ExerciseEquipment",
    "ExerciseInstruction",
    "ExerciseMedia",
    "ExerciseMuscle",
    "ProgramPhase",
    "ProgramSession",
    "ProgramSessionExercise",
    "ProgramTemplate",
    "ProgramVersion",
    "ProgramWeek",
    "SetPrescription",
    "AssignmentSessionState",
    "ProgramAssignment",
    "WorkoutExercise",
    "WorkoutSession",
    "WorkoutSet",
    "AssignmentProgressionRule",
    "ProgressionEvent",
    "ProgressionPolicy",
    "ProgressionState",
    "BodyMeasurement",
    "CoachingIntervention",
    "CoachNote",
    "AuditEvent",
    "DomainEvent",
]
