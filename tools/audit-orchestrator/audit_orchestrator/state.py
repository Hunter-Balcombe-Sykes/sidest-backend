"""State manager for .audit-work/state.json — atomic reads/writes + lock file."""
from __future__ import annotations
import json
import os
import time
from contextlib import contextmanager
from dataclasses import dataclass, field, asdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterator


SCHEMA_VERSION = 1


@dataclass
class State:
    """Mutable snapshot of state.json."""
    schema_version: int = SCHEMA_VERSION
    last_parse: str | None = None
    sources: list[str] = field(default_factory=list)
    items: dict[str, dict] = field(default_factory=dict)
    queue: list[str] = field(default_factory=list)
    current_run: dict | None = None
    history: list[dict] = field(default_factory=list)


class StateManager:
    """Read/write the state file atomically. Use lock_file() for cross-process exclusion."""

    def __init__(self, path: Path):
        self.path = path
        self.path.parent.mkdir(parents=True, exist_ok=True)

    def load(self) -> State:
        """Load state from disk; if missing or corrupted, return empty state.

        Corrupted files are renamed to state.json.broken-<timestamp>.
        """
        if not self.path.exists():
            return State()

        try:
            raw = json.loads(self.path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            ts = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
            backup = self.path.parent / f"{self.path.name}.broken-{ts}"
            self.path.rename(backup)
            return State()

        return State(
            schema_version=raw.get("schema_version", SCHEMA_VERSION),
            last_parse=raw.get("last_parse"),
            sources=raw.get("sources", []),
            items=raw.get("items", {}),
            queue=raw.get("queue", []),
            current_run=raw.get("current_run"),
            history=raw.get("history", []),
        )

    def save(self, state: State) -> None:
        """Atomic write: serialize to a .tmp file, then rename over the target."""
        tmp = self.path.with_suffix(self.path.suffix + ".tmp")
        tmp.write_text(json.dumps(asdict(state), indent=2, sort_keys=True), encoding="utf-8")
        tmp.replace(self.path)

    def update(self, mutator) -> State:
        """Read-modify-write convenience. mutator(state) -> None or new state."""
        state = self.load()
        result = mutator(state)
        state = result if isinstance(result, State) else state
        self.save(state)
        return state


@contextmanager
def lock_file(path: Path, timeout: float = 0.0) -> Iterator[None]:
    """Cross-process exclusive lock via O_EXCL file create.

    Raises RuntimeError if the lock can't be acquired and timeout has elapsed.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    deadline = time.monotonic() + timeout
    while True:
        try:
            fd = os.open(str(path), os.O_CREAT | os.O_EXCL | os.O_WRONLY)
            os.write(fd, str(os.getpid()).encode())
            os.close(fd)
            break
        except FileExistsError:
            if time.monotonic() >= deadline:
                holder = path.read_text(encoding="utf-8") if path.exists() else "?"
                raise RuntimeError(
                    f"audit orchestrator already running (pid {holder.strip()}). "
                    f"If this is wrong, delete {path}."
                )
            time.sleep(0.05)
    try:
        yield
    finally:
        try:
            path.unlink()
        except FileNotFoundError:
            pass
