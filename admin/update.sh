#!/usr/bin/env bash
set -Eeuo pipefail

CURRENT_STEP="initializing"
BACKUP_DIR=""
PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"
RUN_BACKUP=1
BACKUP_ONLY=0

usage() {
  cat <<'USAGE'
Usage:
  ./admin/update.sh
  ./admin/update.sh --backup-only
  ./admin/update.sh --no-backup
USAGE
}

fail() {
  printf 'Error: %s\n' "$1" >&2
  if [ -n "$BACKUP_DIR" ]; then
    printf 'Backup: %s/\n' "$BACKUP_DIR" >&2
  fi
  exit 1
}

on_error() {
  local exit_code=$?
  printf 'Update failed during: %s\n' "$CURRENT_STEP" >&2
  if [ -n "$BACKUP_DIR" ]; then
    printf 'Backup: %s/\n' "$BACKUP_DIR" >&2
  fi
  exit "$exit_code"
}
trap on_error ERR

for arg in "$@"; do
  case "$arg" in
    --backup-only)
      BACKUP_ONLY=1
      ;;
    --no-backup)
      RUN_BACKUP=0
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      usage >&2
      fail "Unknown option: $arg"
      ;;
  esac
done

if [ "$BACKUP_ONLY" -eq 1 ] && [ "$RUN_BACKUP" -eq 0 ]; then
  fail "Use either --backup-only or --no-backup, not both."
fi

require_project_root() {
  [ -d ".git" ] || fail "Run this script from the project root."
  [ -f "admin/includes/blog-storage.php" ] || fail "Run this script from the project root."
  [ -d "admin" ] || fail "Run this script from the project root."
}

env_config_value() {
  local key="$1"
  [ -f "admin/.env" ] || return 0
  awk -F= -v key="$key" '
    $0 !~ /^[[:space:]]*#/ && $1 ~ "^[[:space:]]*" key "[[:space:]]*$" {
      value=$0
      sub(/^[^=]*=/, "", value)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
      gsub(/^["'\'']|["'\'']$/, "", value)
      print value
      exit
    }
  ' admin/.env
}

storage_dir() {
  local configured
  configured="$(env_config_value "APP_STORAGE_DIR" || true)"
  if [ -z "$configured" ] || [ "$configured" = "/absolute/path/outside/public/storage" ]; then
    printf '%s\n' "$PROJECT_ROOT/admin/storage"
    return
  fi
  case "$configured" in
    /*) printf '%s\n' "$configured" ;;
    *) printf '%s\n' "$PROJECT_ROOT/$configured" ;;
  esac
}

database_path() {
  printf '%s/blogs.sqlite\n' "$(storage_dir)"
}

relative_path() {
  local path="$1"
  case "$path" in
    "$PROJECT_ROOT"/*) printf '%s\n' "${path#"$PROJECT_ROOT"/}" ;;
    *) printf '%s\n' "$path" ;;
  esac
}

sqlite_quote() {
  printf "%s" "$1" | sed "s/'/''/g"
}

copy_tree() {
  local source="$1"
  local dest="$2"
  shift 2
  [ -e "$source" ] || return 0
  mkdir -p "$dest"
  if command -v rsync >/dev/null 2>&1; then
    rsync -a "$@" "$source"/ "$dest"/
  else
    (cd "$source" && tar cf - "$@" .) | (cd "$dest" && tar xf -)
  fi
}

backup_database() {
  local db_path="$1"
  local backup_sqlite_dir="$BACKUP_DIR/sqlite"
  local backup_db="$backup_sqlite_dir/blogs.sqlite"
  [ -f "$db_path" ] || return 0
  mkdir -p "$backup_sqlite_dir"

  if command -v sqlite3 >/dev/null 2>&1; then
    local quoted_backup_db
    quoted_backup_db="$(sqlite_quote "$backup_db")"
    sqlite3 "$db_path" <<SQL
.timeout 5000
.backup '$quoted_backup_db'
SQL
  else
    cp -p "$db_path" "$backup_db"
    [ -f "$db_path-wal" ] && cp -p "$db_path-wal" "$backup_sqlite_dir/blogs.sqlite-wal"
    [ -f "$db_path-shm" ] && cp -p "$db_path-shm" "$backup_sqlite_dir/blogs.sqlite-shm"
  fi

  if [ -f "$db_path-wal" ] || [ -f "$db_path-shm" ]; then
    mkdir -p "$backup_sqlite_dir/wal-files"
    [ -f "$db_path-wal" ] && cp -p "$db_path-wal" "$backup_sqlite_dir/wal-files/blogs.sqlite-wal"
    [ -f "$db_path-shm" ] && cp -p "$db_path-shm" "$backup_sqlite_dir/wal-files/blogs.sqlite-shm"
  fi
}

create_backup() {
  local timestamp db_path
  timestamp="$(date '+%Y-%m-%d_%H%M%S')"
  BACKUP_DIR="$PROJECT_ROOT/admin/storage/backups/$timestamp"
  umask 077
  mkdir -p "$BACKUP_DIR"

  db_path="$(database_path)"
  backup_database "$db_path"

  copy_tree "$PROJECT_ROOT/admin/uploads" "$BACKUP_DIR/uploads" \
    --exclude '.DS_Store'

  copy_tree "$(storage_dir)" "$BACKUP_DIR/storage-files" \
    --exclude 'backups' \
    --exclude 'api-cache' \
    --exclude '*.log' \
    --exclude '*.sqlite' \
    --exclude '*.sqlite-*' \
    --exclude '*.bak*' \
    --exclude '*.corrupt-*' \
    --exclude '.DS_Store'

  mkdir -p "$BACKUP_DIR/config"
  [ -f "$PROJECT_ROOT/admin/.env" ] && cp -p "$PROJECT_ROOT/admin/.env" "$BACKUP_DIR/config/.env"
  [ -f "$PROJECT_ROOT/.htaccess" ] && cp -p "$PROJECT_ROOT/.htaccess" "$BACKUP_DIR/config/.htaccess"
  [ -f "$PROJECT_ROOT/robots.txt" ] && cp -p "$PROJECT_ROOT/robots.txt" "$BACKUP_DIR/config/robots.txt"
  find "$BACKUP_DIR/config" -type f -exec chmod 600 {} \;
}

restore_runtime_data() {
  local db_path backup_db db_dir
  [ -n "$BACKUP_DIR" ] || return 0

  db_path="$(database_path)"
  backup_db="$BACKUP_DIR/sqlite/blogs.sqlite"
  db_dir="$(dirname "$db_path")"
  mkdir -p "$db_dir"
  if [ -f "$backup_db" ]; then
    cp -p "$backup_db" "$db_path"
  fi

  if [ -d "$BACKUP_DIR/uploads" ]; then
    mkdir -p "$PROJECT_ROOT/admin/uploads"
    copy_tree "$BACKUP_DIR/uploads" "$PROJECT_ROOT/admin/uploads"
  fi

  if [ -d "$BACKUP_DIR/storage-files" ]; then
    mkdir -p "$(storage_dir)"
    copy_tree "$BACKUP_DIR/storage-files" "$(storage_dir)"
  fi
}

tracked_runtime_path() {
  local db_rel
  db_rel="$(relative_path "$(database_path)")"
  git ls-files --error-unmatch "$db_rel" >/dev/null 2>&1 && return 0
  git ls-files --error-unmatch "admin/storage" >/dev/null 2>&1 && return 0
  git ls-files --error-unmatch "uploads" >/dev/null 2>&1 && return 0
  return 1
}

ensure_clean_code_changes() {
  local changed
  changed="$(git status --porcelain --untracked-files=no -- . ':!admin/storage/**' ':!admin/uploads/**' || true)"
  if [ -n "$changed" ]; then
    printf 'Local tracked code changes would make this update unsafe:\n%s\n' "$changed" >&2
    fail "Commit or move those changes before running update."
  fi

  if [ "$RUN_BACKUP" -eq 0 ] && tracked_runtime_path; then
    fail "--no-backup is not safe because runtime database/storage/uploads paths are tracked by Git."
  fi
}

current_branch() {
  local branch
  branch="$(git branch --show-current)"
  [ -n "$branch" ] || fail "Could not detect the current Git branch."
  printf '%s\n' "$branch"
}

update_files() {
  local branch upstream remote remote_branch
  branch="$(current_branch)"
  upstream="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"
  if [ -n "$upstream" ]; then
    remote="${upstream%%/*}"
    remote_branch="${upstream#*/}"
  else
    remote="origin"
    remote_branch="$branch"
  fi

  git fetch "$remote"
  git pull --ff-only "$remote" "$remote_branch"
}

run_optional_tasks() {
  if [ -f "admin/scripts/safe-db-update.php" ]; then
    php admin/scripts/safe-db-update.php --apply
  fi

  if [ -x "admin/scripts/build-static-routes.sh" ] && [ -f "admin/index.html" ]; then
    admin/scripts/build-static-routes.sh
  fi
}

check_database() {
  local db_path result
  db_path="$(database_path)"
  [ -f "$db_path" ] || return 0
  command -v sqlite3 >/dev/null 2>&1 || {
    printf 'Warning: sqlite3 not found; skipped integrity check.\n' >&2
    return 0
  }
  result="$(sqlite3 "$db_path" 'PRAGMA integrity_check;')"
  if [ "$result" != "ok" ]; then
    printf 'SQLite integrity check failed:\n%s\n' "$result" >&2
    return 1
  fi
}

stat_owner_group() {
  local path="$1"
  if stat -c '%U:%G' "$path" >/dev/null 2>&1; then
    stat -c '%U:%G' "$path"
  else
    stat -f '%Su:%Sg' "$path"
  fi
}

fix_permissions() {
  local db_path db_dir runtime_owner
  db_path="$(database_path)"
  db_dir="$(dirname "$db_path")"

  mkdir -p "$PROJECT_ROOT/admin/uploads" "$db_dir"
  chmod u+rwX,g+rwX "$PROJECT_ROOT/admin/uploads" "$db_dir"
  [ -d "$(storage_dir)" ] && chmod u+rwX,g+rwX "$(storage_dir)"

  if [ -f "$db_path" ]; then
    chmod u+rw,g+rw "$db_path"
    [ -f "$db_path-wal" ] && chmod u+rw,g+rw "$db_path-wal"
    [ -f "$db_path-shm" ] && chmod u+rw,g+rw "$db_path-shm"
  fi

  runtime_owner="$(stat_owner_group "$db_dir" 2>/dev/null || true)"
  if [ "$(id -u)" -eq 0 ] && [ -n "$runtime_owner" ]; then
    chown "$runtime_owner" "$PROJECT_ROOT/admin/uploads" "$db_dir"
    [ -f "$db_path" ] && chown "$runtime_owner" "$db_path"
    [ -f "$db_path-wal" ] && chown "$runtime_owner" "$db_path-wal"
    [ -f "$db_path-shm" ] && chown "$runtime_owner" "$db_path-shm"
  fi
}

main() {
  local branch
  require_project_root

  if [ "$RUN_BACKUP" -eq 1 ]; then
    CURRENT_STEP="[1/6] Creating backup"
    printf '[1/6] Creating backup...\n'
    create_backup
  else
    printf 'Warning: backup skipped because --no-backup was provided.\n' >&2
  fi

  if [ "$BACKUP_ONLY" -eq 1 ]; then
    printf 'Backup complete.\n\nBackup: %s/\n' "$BACKUP_DIR"
    exit 0
  fi

  CURRENT_STEP="[2/6] Checking repository"
  printf '[2/6] Checking repository...\n'
  ensure_clean_code_changes

  CURRENT_STEP="[3/6] Updating files"
  printf '[3/6] Updating files...\n'
  branch="$(current_branch)"
  update_files

  CURRENT_STEP="[4/6] Checking database"
  printf '[4/6] Checking database...\n'
  restore_runtime_data
  run_optional_tasks
  check_database

  CURRENT_STEP="[5/6] Fixing required permissions"
  printf '[5/6] Fixing required permissions...\n'
  fix_permissions

  CURRENT_STEP="[6/6] Update complete"
  printf '[6/6] Update complete.\n\n'
  printf 'Branch: %s\n' "$branch"
  if [ -n "$BACKUP_DIR" ]; then
    printf 'Backup: %s/\n' "$BACKUP_DIR"
  else
    printf 'Backup: skipped\n'
  fi
}

main "$@"
