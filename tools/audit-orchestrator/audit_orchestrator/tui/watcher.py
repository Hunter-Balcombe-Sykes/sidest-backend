"""File-system watchers used by the TUI to refresh on external state changes."""
from __future__ import annotations
from fnmatch import fnmatch
from pathlib import Path
from typing import Callable
from watchdog.events import FileSystemEventHandler, FileSystemEvent
from watchdog.observers import Observer


class _Handler(FileSystemEventHandler):
    def __init__(self, target: Path, callback: Callable[[], None]) -> None:
        super().__init__()
        self.target = target.resolve()
        self.callback = callback

    def on_modified(self, event: FileSystemEvent) -> None:
        if Path(event.src_path).resolve() == self.target:
            self.callback()


class _GlobHandler(FileSystemEventHandler):
    """Fire callback when any file matching one of the glob patterns changes."""

    def __init__(self, directory: Path, patterns: list[str], callback: Callable[[], None]) -> None:
        super().__init__()
        self.directory = directory.resolve()
        self.patterns = patterns
        self.callback = callback

    def _matches(self, event_path: str) -> bool:
        path = Path(event_path)
        try:
            if path.parent.resolve() != self.directory:
                return False
        except OSError:
            return False
        return any(fnmatch(path.name, p) for p in self.patterns)

    def on_created(self, event: FileSystemEvent) -> None:
        if not event.is_directory and self._matches(event.src_path):
            self.callback()

    def on_modified(self, event: FileSystemEvent) -> None:
        if not event.is_directory and self._matches(event.src_path):
            self.callback()

    def on_deleted(self, event: FileSystemEvent) -> None:
        if not event.is_directory and self._matches(event.src_path):
            self.callback()

    def on_moved(self, event: FileSystemEvent) -> None:
        if event.is_directory:
            return
        dest = getattr(event, "dest_path", "") or ""
        if self._matches(event.src_path) or self._matches(dest):
            self.callback()


def watch(path: Path, callback: Callable[[], None]) -> Observer:
    """Watch a single file for modifications; fire callback on each change."""
    observer = Observer()
    parent = path.parent
    parent.mkdir(parents=True, exist_ok=True)
    observer.schedule(_Handler(path, callback), str(parent), recursive=False)
    observer.daemon = True
    observer.start()
    return observer


def watch_glob(directory: Path, patterns: list[str], callback: Callable[[], None]) -> Observer:
    """Watch a directory; fire callback when any file matching one of the
    glob patterns is created/modified/deleted/moved.

    Useful for picking up new audit files dropped into repo root, or for
    detecting when a user ticks a checkbox in an existing audit markdown.
    Patterns use fnmatch syntax (e.g. 'pilot-*.md', 'audit-*.md').
    """
    observer = Observer()
    directory.mkdir(parents=True, exist_ok=True)
    observer.schedule(_GlobHandler(directory, patterns, callback), str(directory), recursive=False)
    observer.daemon = True
    observer.start()
    return observer
