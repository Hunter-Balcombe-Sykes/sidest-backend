"""Shared pytest fixtures."""
import pytest
from pathlib import Path


@pytest.fixture
def fixtures_dir():
    """Path to test fixtures directory."""
    return Path(__file__).parent / "fixtures"
