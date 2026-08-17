# Easy Update Upload

Use this when you edit files locally and want a small upload package for aaPanel or cPanel File Manager.

```sh
sh scripts/package-update.sh
```

The script creates:

- `update-packages/site-update-YYYYMMDD-HHMMSS.zip`
- `update-packages/site-update-YYYYMMDD-HHMMSS-manifest.txt`
- `update-packages/site-update-YYYYMMDD-HHMMSS-deletions.txt`

Upload the zip to the site root and extract it there. If `deletions.txt` has entries, delete those files on the server too.

## Database Updates

Do not upload your local `storage/blogs.sqlite` to production unless you intentionally want to replace production data.

For database changes, upload the code first, then run a safe schema check:

```sh
php scripts/safe-db-update.php
```

That command is a dry run. It only shows missing columns.

To add the missing columns without changing existing rows:

```sh
php scripts/safe-db-update.php --apply
```

This script does not import games, replace data, delete rows, or overwrite the production database file.

To compare against a specific commit or branch:

```sh
sh scripts/package-update.sh HEAD
sh scripts/package-update.sh main
```
