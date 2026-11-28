"""Isolated update install: preserve frontend/runtime data and web permissions."""
import importlib.util
import json
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import unittest
import zipfile


SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/updater.py'
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('site_updater', SCRIPT)
updater = importlib.util.module_from_spec(spec)
spec.loader.exec_module(updater)


class UpdaterTests(unittest.TestCase):
    def test_frontend_deletions_are_rejected(self):
        for name in ('index.html', 'blog/view.php', 'assets/app.js', 'games/a/index.html',
                     'src/App.tsx', 'admin/storage/private.php', '../index.html'):
            self.assertFalse(updater.deletion_allowed(name), name)
        for name in ('admin/storage/blogs.sqlite', 'admin/storage/customer-tickets.json',
                     'admin/uploads/image.webp', 'admin/chats/session.json',
                     'admin/data/export.json', 'uploads/blogs/image.webp'):
            self.assertFalse(updater.allowed(name), name)
            self.assertFalse(updater.deletion_allowed(name), name)
        self.assertTrue(updater.deletion_allowed('api/obsolete.php'))
        with tempfile.TemporaryDirectory() as directory:
            archive = Path(directory) / 'bad.zip'
            with zipfile.ZipFile(archive, 'w') as bundle:
                bundle.writestr('_update_manifest.json', json.dumps({'format': 1, 'files': {}, 'delete': ['index.html']}))
            old_hash = updater.EXPECTED_ZIP_SHA256
            try:
                updater.EXPECTED_ZIP_SHA256 = updater.digest(archive.read_bytes())
                with self.assertRaisesRegex(RuntimeError, 'frontend deletion'):
                    updater.inspect_bundle(archive)
            finally:
                updater.EXPECTED_ZIP_SHA256 = old_hash

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI required')
    def test_build_and_install_preserve_frontend(self):
        with tempfile.TemporaryDirectory() as directory:
            workspace = Path(directory)
            source = workspace / 'source'
            site = workspace / 'site'
            source.mkdir()
            site.mkdir()

            def write(root, name, value):
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(value)

            def git(*args):
                subprocess.run(['git', '-C', str(source), *args], check=True, stdout=subprocess.DEVNULL)

            git('init', '-q')
            historical = {'index.html': '<main>Website</main>', 'assets/app.js': 'frontend',
                          'blog/view.php': '<?php echo "Article";', 'api/obsolete.php': '<?php // old'}
            for name, data in historical.items():
                write(source, name, data)
                write(site, name, data)
            git('add', '.')
            git('-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'Old combined site')
            for name in historical:
                (source / name).unlink()
            # Minimal application stubs exercise the real installer without touching app databases.
            storage = '<?php function blogs_db_path(){return __DIR__."/../storage/blogs.sqlite";} function blogs_pdo(){} function blog_clear_api_cache(){}'
            files = {'admin/includes/blog-storage.php': storage,
                     'admin/scripts/generate-game-sitemaps.php': '<?php echo "Sitemaps checked\\n";',
                     'game-api/slot-list.php': '<?php echo "updated";',
                     'admin/new/deep/page.php': '<?php echo "new";'}
            for name, data in files.items():
                write(source, name, data)
            excluded_source_files = {
                'admin/storage/blogs.sqlite': 'source database',
                'admin/storage/customer-tickets.json': 'source tickets',
                'admin/chats/session.json': 'source chat',
                'admin/data/export.json': 'source export',
                'admin/uploads/image.webp': 'source admin upload',
                'uploads/blogs/image.webp': 'source upload',
            }
            for name, data in excluded_source_files.items():
                write(source, name, data)
            write(site, 'admin/includes/blog-storage.php', storage)
            (site / 'admin/includes/blog-storage.php').chmod(0o600)
            write(site, 'admin/.env', 'KEEP_PRIVATE=1')
            (site / 'admin/.env').chmod(0o600)
            write(site, 'uploads/blogs/keep.webp', 'existing upload')
            write(site, 'admin/storage/customer-tickets.json', '{"ticket":"keep"}')
            write(site, 'admin/chats/session.json', '{"chat":"keep"}')
            write(site, 'admin/data/export.json', '{"data":"keep"}')
            write(site, 'admin/uploads/image.webp', 'existing admin upload')
            with sqlite3.connect(site / 'admin/storage/blogs.sqlite') as db:
                db.execute('CREATE TABLE marker(value TEXT)')
                db.execute("INSERT INTO marker VALUES ('keep')")
            with sqlite3.connect(site / 'admin/storage/games.sqlite') as db:
                db.execute('CREATE TABLE marker(value TEXT)')
                db.execute("INSERT INTO marker VALUES ('keep-game')")
            git('add', '-A')
            git('-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'Backend split')
            updater.build(source)
            output = source / 'admin/update-packages/full'
            with zipfile.ZipFile(output / 'site-update.zip') as bundle:
                manifest = json.loads(bundle.read('_update_manifest.json'))
                bundled_files = set(bundle.namelist())
            self.assertEqual(manifest['delete'], ['api/obsolete.php'])
            for name in excluded_source_files:
                self.assertNotIn(name, manifest['files'])
                self.assertNotIn(name, bundled_files)
            for name in ('updater.py', 'site-update.zip'):
                shutil.copyfile(output / name, site / name)
            command = ['python3', str(site / 'updater.py')]
            subprocess.run(command + ['--check'], check=True, capture_output=True, text=True)
            self.assertTrue((site / 'api/obsolete.php').exists())
            self.assertEqual((site / 'admin/includes/blog-storage.php').stat().st_mode & 0o777, 0o600)
            result = subprocess.run(command, check=True, capture_output=True, text=True, umask=0o077)
            self.assertIn('Update complete.', result.stdout)
            for name, data in historical.items():
                if name != 'api/obsolete.php':
                    self.assertEqual((site / name).read_text(), data)
            self.assertFalse((site / 'api/obsolete.php').exists())
            self.assertEqual((site / 'admin/includes/blog-storage.php').stat().st_mode & 0o777, 0o644)
            self.assertEqual((site / 'admin/new/deep/page.php').stat().st_mode & 0o777, 0o644)
            self.assertEqual((site / 'admin/new/deep').stat().st_mode & 0o777, 0o755)
            self.assertEqual((site / 'admin/.env').stat().st_mode & 0o777, 0o600)
            self.assertEqual((site / 'uploads/blogs/keep.webp').read_text(), 'existing upload')
            self.assertEqual((site / 'admin/storage/customer-tickets.json').read_text(), '{"ticket":"keep"}')
            self.assertEqual((site / 'admin/chats/session.json').read_text(), '{"chat":"keep"}')
            self.assertEqual((site / 'admin/data/export.json').read_text(), '{"data":"keep"}')
            self.assertEqual((site / 'admin/uploads/image.webp').read_text(), 'existing admin upload')
            with sqlite3.connect(site / 'admin/storage/blogs.sqlite') as db:
                self.assertEqual(db.execute('SELECT value FROM marker').fetchone(), ('keep',))
            with sqlite3.connect(site / 'admin/storage/games.sqlite') as db:
                self.assertEqual(db.execute('SELECT value FROM marker').fetchone(), ('keep-game',))
            backup = next((workspace / '.site-update-backups').glob('site-*'))
            self.assertTrue((backup / 'files/api/obsolete.php').exists())
            with sqlite3.connect(backup / 'blogs.sqlite') as db:
                self.assertEqual(db.execute('SELECT value FROM marker').fetchone(), ('keep',))
            with sqlite3.connect(backup / 'games.sqlite') as db:
                self.assertEqual(db.execute('SELECT value FROM marker').fetchone(), ('keep-game',))


if __name__ == '__main__':
    unittest.main()
