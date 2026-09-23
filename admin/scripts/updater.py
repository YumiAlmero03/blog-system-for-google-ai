#!/usr/bin/env python3
"""Build or install a complete, two-file site update. Python 3.10+ and PHP CLI."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import zipfile

EXPECTED_ZIP_SHA256 = ''
EXCLUDED = {'storage', 'uploads', 'chats', 'data', 'update-packages', 'node_modules',
            '.git', '.codex', '.agents', '__pycache__', '.vscode'}


def allowed(name):
    path = PurePosixPath(name)
    if not name or '\\' in name or '\n' in name or '\r' in name or path.is_absolute():
        return False
    if str(path) != name or any(p in EXCLUDED or p in ('.', '..') for p in path.parts):
        return False
    base = path.name.lower()
    return not (base.startswith(('.env', '.google-private', 'service-account'))
                or base in ('.ds_store', 'google-config.json', 'updater.py', 'site-update.zip', 'bingsiteauth.xml')
                or (base.startswith('google') and base.endswith('.html'))
                or any(s in base for s in ('.sqlite', '.bak'))
                or path.suffix.lower() in ('.db', '.zip', '.pem', '.key', '.log', '.sql', '.pyc', '.lock'))


def digest(data):
    return hashlib.sha256(data).hexdigest()


def git(root, *args):
    return subprocess.check_output(['git', '-C', str(root), *args])


def build(root):
    root = root.resolve()
    names = set(git(root, 'ls-files', '-z').decode().split('\0'))
    names.update(git(root, 'ls-files', '--others', '--exclude-standard', '-z').decode().split('\0'))
    files = {}
    for name in sorted(names):
        if not allowed(name):
            continue
        path = root / name
        if path.is_symlink():
            raise RuntimeError(f'Cannot package symlink: {name}')
        if path.is_file():
            files[name] = path.read_bytes()
    # Include all historical removed paths, not just changes since the last release.
    historical = set(git(root, 'log', '--no-renames', '--format=', '--name-only', '--diff-filter=D', '-z').decode().split('\0'))
    historical.update(names)
    deleted = sorted(n for n in historical if allowed(n) and not (root / n).exists())
    for required in ('admin/includes/blog-storage.php', 'admin/scripts/generate-game-sitemaps.php', 'api/slot-list.php'):
        if required not in files:
            raise RuntimeError(f'Missing required application file: {required}')
    output = root / 'admin/update-packages/full'
    output.mkdir(parents=True, exist_ok=True)
    archive = output / 'site-update.zip'
    manifest = {'format': 1, 'files': {n: digest(b) for n, b in files.items()}, 'delete': deleted}
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as bundle:
        for name, data in files.items():
            bundle.writestr(name, data)
        bundle.writestr('_update_manifest.json', json.dumps(manifest, indent=2))
    source = Path(__file__).read_text()
    source = source.replace("EXPECTED_ZIP_SHA256 = ''", f"EXPECTED_ZIP_SHA256 = '{digest(archive.read_bytes())}'", 1)
    (output / 'updater.py').write_text(source)
    print(f'Created {len(files)} application files; {len(deleted)} obsolete paths.\nUpload these two files:\n{output / "updater.py"}\n{archive}')


def safe_target(root, name):
    if not allowed(name):
        raise RuntimeError(f'Protected or invalid package path: {name}')
    target = root / name
    for part in [target, *target.parents]:
        if part == root:
            break
        if part.is_symlink():
            raise RuntimeError(f'Symlink in destination: {name}')
    if target.exists() and not target.is_file():
        raise RuntimeError(f'Destination is not a file: {name}')
    return target


def php_run(php, root, code):
    return subprocess.check_output([php, '-r', code], cwd=root, text=True).strip()


def inspect_bundle(archive):
    if not EXPECTED_ZIP_SHA256 or digest(archive.read_bytes()) != EXPECTED_ZIP_SHA256:
        raise RuntimeError('ZIP does not match this updater. Upload the matching pair from the build.')
    with zipfile.ZipFile(archive) as bundle:
        names = bundle.namelist()
        if len(names) != len(set(names)):
            raise RuntimeError('Duplicate ZIP entries.')
        manifest = json.loads(bundle.read('_update_manifest.json'))
        if manifest.get('format') != 1 or not isinstance(manifest.get('files'), dict) or not isinstance(manifest.get('delete'), list):
            raise RuntimeError('Invalid update manifest.')
        if set(names) != set(manifest['files']) | {'_update_manifest.json'}:
            raise RuntimeError('Unexpected ZIP entries.')
        for name, checksum in manifest['files'].items():
            if not allowed(name) or digest(bundle.read(name)) != checksum:
                raise RuntimeError(f'Invalid file: {name}')
        if any(not allowed(n) or n in manifest['files'] for n in manifest['delete']):
            raise RuntimeError('Invalid deletion entry.')
    return manifest


def install(args):
    root = Path(args.root).resolve()
    archive = Path(__file__).resolve().with_name('site-update.zip')
    manifest = inspect_bundle(archive)
    if not (root / 'admin/includes/blog-storage.php').is_file():
        raise RuntimeError('Site root must contain admin/includes/blog-storage.php. Migrate older directory layouts first.')
    touched = set(manifest['files']) | set(manifest['delete'])
    for name in touched:
        safe_target(root, name)
    php = shutil.which(args.php)
    if not php:
        raise RuntimeError('PHP CLI not found. Pass --php /path/to/php.')
    php_run(php, root, "if (PHP_VERSION_ID < 80100 || !extension_loaded('pdo_sqlite')) { fwrite(STDERR, 'PHP 8.1+ with pdo_sqlite required.'); exit(1); }")
    # Resolve the current server's storage configuration without initializing/migrating it.
    db = Path(php_run(php, root, 'require "admin/includes/blog-storage.php"; echo blogs_db_path();'))
    if not db.is_absolute():
        db = root / db
    db = db.resolve()
    game_db = db.with_name('games.sqlite')
    if not db.is_file():
        raise RuntimeError(f'Existing database not found: {db}. Check server storage configuration.')
    print(f'Validated full update: {len(manifest["files"])} files. Database: {db}')
    if args.check:
        print('Check complete; no files or database records changed.')
        return
    backup_parent = Path(args.backup_dir).resolve() if args.backup_dir else root.parent / '.site-update-backups'
    if backup_parent == root or root in backup_parent.parents:
        raise RuntimeError('Backup directory must be outside the website root.')
    backup_parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (backup_parent / (digest(str(root).encode()) + '.lock')).open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        backup = Path(tempfile.mkdtemp(prefix=root.name + '-', dir=backup_parent))
        print(f'Backup: {backup}', flush=True)
        # A consistent SQLite backup includes committed WAL data.
        with sqlite3.connect(db.as_uri() + '?mode=ro', uri=True) as source, sqlite3.connect(backup / 'blogs.sqlite') as dest:
            source.backup(dest)
            if dest.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
                raise RuntimeError('Database integrity check failed; no code was changed.')
        if game_db.is_file():
            with sqlite3.connect(game_db.as_uri() + '?mode=ro', uri=True) as source, sqlite3.connect(backup / 'games.sqlite') as dest:
                source.backup(dest)
                if dest.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
                    raise RuntimeError('Game database integrity check failed; no code was changed.')
        absent = []
        for name in sorted(touched):
            path = safe_target(root, name)
            if path.exists():
                dest = backup / 'files' / name
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(path, dest)
            else:
                absent.append(name)
        (backup / 'restore-info.json').write_text(json.dumps({'root': str(root), 'database': str(db), 'game_database': str(game_db), 'game_database_previously_absent': not game_db.is_file(), 'previously_absent': absent}, indent=2))
        for name in ('.env', 'admin/.env'):
            if (root / name).is_file():
                dest = backup / 'config' / name
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(root / name, dest)
        try:
            with tempfile.TemporaryDirectory(prefix='site-update-') as temp, zipfile.ZipFile(archive) as bundle:
                stage = Path(temp)
                for name in manifest['files']:
                    target = stage / name
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_bytes(bundle.read(name))
                    if target.suffix == '.php':
                        subprocess.run([php, '-l', str(target)], check=True, stdout=subprocess.DEVNULL)
                for name in manifest['files']:
                    target = safe_target(root, name)
                    target.parent.mkdir(parents=True, exist_ok=True)
                    previous = target.stat() if target.exists() else target.parent.stat()
                    fd, tmp = tempfile.mkstemp(prefix='.update-', dir=target.parent)
                    try:
                        with os.fdopen(fd, 'wb') as output:
                            output.write((stage / name).read_bytes())
                        os.chmod(tmp, (previous.st_mode & 0o777) if target.exists() else (0o755 if target.suffix == '.sh' else 0o644))
                        if os.geteuid() == 0:
                            os.chown(tmp, previous.st_uid, previous.st_gid)
                        os.replace(tmp, target)
                    finally:
                        if os.path.exists(tmp):
                            os.unlink(tmp)
                for name in manifest['delete']:
                    safe_target(root, name).unlink(missing_ok=True)
            php_run(php, root, 'require "admin/includes/blog-storage.php"; blogs_pdo(); blog_clear_api_cache();')
            subprocess.run([php, 'admin/scripts/generate-game-sitemaps.php'], cwd=root, check=True)
        except Exception:
            print(f'Update incomplete. Keep maintenance enabled. Code/database backup and restore-info.json: {backup}', file=sys.stderr)
            raise
        print(f'Update complete. Verify the site, resume jobs, and remove uploaded updater.py and site-update.zip. Backup: {backup}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--build', metavar='SOURCE_ROOT', help='Build the two upload files locally from all current Git-listed application files.')
    parser.add_argument('--root', default=str(Path(__file__).resolve().parent), help='Server site root; defaults to the folder containing this updater.')
    parser.add_argument('--php', default='php', help='PHP CLI executable.')
    parser.add_argument('--backup-dir', help='Private backup directory outside the site root.')
    parser.add_argument('--check', action='store_true', help='Validate package and server without installing.')
    args = parser.parse_args()
    if args.build:
        build(Path(args.build))
    else:
        install(args)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'Update failed: {error}', file=sys.stderr)
        sys.exit(1)
