"""Core data classes for audit items, bundles, and run results."""
from __future__ import annotations
from dataclasses import dataclass, field
from datetime import datetime
from enum import Enum
from typing import Literal


class Tier(str, Enum):
    P0 = "P0"
    P1 = "P1"
    P2 = "P2"
    P3 = "P3"
    MIXED = "mixed"  # used for bundles spanning multiple tiers


class Effort(str, Enum):
    S = "S"
    M = "M"
    L = "L"
    XL = "XL"
    UNKNOWN = "unknown"


class Classification(str, Enum):
    RECOMMENDED = "recommended"
    CAUTION = "caution"
    SKIP = "skip"


class ItemStatus(str, Enum):
    PENDING = "pending"
    QUEUED = "queued"
    RUNNING = "running"
    AWAITING_ANSWER = "awaiting_answer"
    DONE = "done"
    BLOCKED = "blocked"
    INTERRUPTED = "interrupted"  # paused by user OR hit usage limit; re-runnable


@dataclass
class Item:
    """A single audit finding."""
    id: str                                # e.g. "#V5-068"
    tier: Tier
    effort: Effort
    title: str
    source: str                            # source markdown filename
    body_markdown: str                     # full item body for prompt rendering
    bundle: str | None = None              # parent bundle id, if any
    classification: Classification = Classification.RECOMMENDED
    status: ItemStatus = ItemStatus.PENDING
    branch: str | None = None              # not used in current push-to-dev-v2 mode
    session_id: str | None = None          # claude session id for resume
    completed_at: str | None = None        # ISO 8601
    blocked_reason: str | None = None
    question_file: str | None = None       # path to current question file if awaiting

    @property
    def is_bundle(self) -> bool:
        return False


@dataclass
class Bundle:
    """A grouped set of audit findings to fix in one Claude session."""
    id: str                                # e.g. "B5"
    title: str
    members: list[str]                     # member item IDs
    source: str
    body_markdown: str                     # the bundle's own paragraph + member bodies
    effort_estimate_hours: tuple[float, float]  # (low, high)
    classification: Classification = Classification.RECOMMENDED
    status: ItemStatus = ItemStatus.PENDING
    session_id: str | None = None
    completed_at: str | None = None
    blocked_reason: str | None = None
    question_file: str | None = None

    @property
    def is_bundle(self) -> bool:
        return True


@dataclass
class RunResult:
    """Outcome of one runner iteration."""
    item_id: str
    started_at: str
    ended_at: str
    outcome: Literal["pushed", "blocked", "awaiting_answer", "skipped"]
    commit_sha: str | None = None
    questions_asked: int = 0
    test_result: Literal["pass", "fail", "not_run"] = "not_run"
    blocked_reason: str | None = None


@dataclass
class QuestionFile:
    """Parsed view of a .audit-work/questions/<id>.md file."""
    item_id: str
    session_id: str | None
    written_at: str
    body: str
    answer: str | None = None              # populated after Josh answers
    answered_at: str | None = None
