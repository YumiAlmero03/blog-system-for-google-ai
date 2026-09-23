#!/usr/bin/env python3
"""Cron entrypoint: one batch of up to 20 unfinished games, without overlapping runs."""
import fcntl
import os
import json
from pathlib import Path
import subprocess
import sys
from datetime import datetime


def main():
    script = Path(__file__).resolve().with_name('enrich-game-descriptions.py')
    # Cron often has a minimal PATH; include Homebrew for local PHP/Ollama setups.
    os.environ['PATH'] = os.environ.get('PATH', '/usr/bin:/bin') + ':/opt/homebrew/bin:/usr/local/bin'
    runtime = json.loads(subprocess.check_output(['php', str(script.with_name('runtime.php'))], text=True))
    storage = Path(runtime['games']).parent
    storage.mkdir(parents=True, exist_ok=True)
    with (storage / 'enrich-games-daily.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print('Daily enrichment already running; skipping.')
            return 0
        with (storage / 'enrich-games-daily.log').open('a', buffering=1) as log:
            print(f'\nDaily enrichment started {datetime.now().astimezone().isoformat()}', file=log)
            if not runtime['enabled']:
                print('Games module disabled; skipping daily job.', file=log)
                return 0
            result = subprocess.run(
                [sys.executable, '-u', str(script), '--only-unprocessed', '--overwrite',
                 '--limit=20', '--once'],
                cwd=script.parents[3], stdout=log, stderr=subprocess.STDOUT,
            )
            print(f'Daily enrichment finished with exit code {result.returncode}', file=log)
            return result.returncode


if __name__ == '__main__':
    raise SystemExit(main())
