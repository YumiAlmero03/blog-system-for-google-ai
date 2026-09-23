#!/usr/bin/env python3
"""Compatibility entrypoint; implementation lives in admin/games/scripts."""
from pathlib import Path
import runpy
if __name__ == "__main__":
    runpy.run_path(str(Path(__file__).resolve().parents[1] / "games/scripts/import-slotslaunch-games.py"), run_name="__main__")
