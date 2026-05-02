"""File-system watcher that posts Textual messages on state.json changes."""
from __future__ import annotations
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


def watch(path: Path, callback: Callable[[], None]) -> Observer:
    """Watch `path` for changes; call `callback()` on each modification.

    `path` does not need to exist when watch() is called. The watchdog
    observer is started but only fires events when actual modifications
    happen on a real file.
    """
    observer = Observer()
    parent = path.parent
    parent.mkdir(parents=True, exist_ok=True)
    observer.schedule(_Handler(path, callback), str(parent), recursive=False)
    observer.daemon = True
    observer.start()
    return observer
