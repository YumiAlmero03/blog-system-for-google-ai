<?php
declare(strict_types=1);
require_once __DIR__ . '/blog-storage.php';
require_once __DIR__ . '/writers.php';

function admin_users_schema(PDO $pdo): void
{
    $sql = "CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        display_name TEXT NOT NULL, password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'editor' CHECK(role IN ('editor','admin','super_user')),
        active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)), auth_version INTEGER NOT NULL DEFAULT 1,
        created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, last_login_at INTEGER,
        written_name TEXT NOT NULL DEFAULT '', bio TEXT NOT NULL DEFAULT '',
        auth_source TEXT NOT NULL DEFAULT 'local'
    )";
    $old = $pdo->query("SELECT sql FROM sqlite_master WHERE name='admin_users'")->fetchColumn();
    // SQLite cannot alter a CHECK constraint. Copy every existing identity and credential transactionally.
    if ($old && !str_contains($old, 'super_user')) {
        $pdo->beginTransaction();
        try {
            $currentSql = $pdo->query("SELECT sql FROM sqlite_master WHERE name='admin_users'")->fetchColumn();
            if (!str_contains($currentSql,'super_user')) {
                $sequence = (int)$pdo->query("SELECT seq FROM sqlite_sequence WHERE name='admin_users'")->fetchColumn();
                $pdo->exec(str_replace('admin_users (', 'admin_users_migration (', $sql));
                $fields = 'id,username,display_name,password_hash,role,active,auth_version,created_at,updated_at,last_login_at';
                $pdo->exec("INSERT INTO admin_users_migration ($fields) SELECT $fields FROM admin_users");
                $pdo->exec('DROP TABLE admin_users');
                $pdo->exec('ALTER TABLE admin_users_migration RENAME TO admin_users');
                $pdo->prepare("UPDATE sqlite_sequence SET seq=MAX(seq,?) WHERE name='admin_users'")->execute([$sequence]);
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    } else $pdo->exec($sql);
    $columns = array_column($pdo->query('PRAGMA table_info(admin_users)')->fetchAll(),'name');
    foreach (['profile_image','role_name'] as $column) {
        if (!in_array($column,$columns,true)) $pdo->exec("ALTER TABLE admin_users ADD COLUMN $column TEXT NOT NULL DEFAULT ''");
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS admin_environment_identity ON admin_users(auth_source) WHERE auth_source='environment'");
    $pdo->exec('CREATE TABLE IF NOT EXISTS writer_social_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
        platform TEXT NOT NULL, url TEXT NOT NULL, label TEXT NOT NULL DEFAULT "", sort_order INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS writer_social_user ON writer_social_links(user_id,sort_order)');
}

function admin_users_pdo(): PDO { return blogs_pdo(); }

function admin_environment_user(): array
{
    $pdo = admin_users_pdo();
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO admin_users(username,display_name,password_hash,role,created_at,updated_at,auth_source)
        VALUES (?,'Editorial Team','','super_user',?,?,'environment')");
    $stmt->execute([env_value('ADMIN_USERNAME'),time(),time()]);
    $row = $pdo->query("SELECT * FROM admin_users WHERE auth_source='environment'")->fetch();
    if (!$row) throw new RuntimeException('Environment administrator identity is unavailable.');
    return $row;
}

function admin_user_find(int $id): ?array
{
    $stmt = admin_users_pdo()->prepare('SELECT * FROM admin_users WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function admin_user_save(array $input): int
{
    $id = filter_var($input['id'] ?? 0,FILTER_VALIDATE_INT);
    if ($id === false || $id < 0) throw new InvalidArgumentException('Invalid editor.');
    $actor = $_SESSION['user']['role'] ?? '';
    if (!in_array($actor,['admin','super_user'],true)) throw new DomainException('Access denied.');
    $existing = $id ? admin_user_find($id) : null;
    $role = $input['role'] ?? ($existing['role'] ?? 'editor');
    if ($actor !== 'super_user' && ($role !== 'editor' || ($existing && $existing['role'] !== 'editor'))) throw new DomainException('Only super users can manage privileged accounts.');
    if (!in_array($role,['editor','admin','super_user'],true)) throw new InvalidArgumentException('Invalid role.');
    if (($existing['auth_source'] ?? '') === 'environment') throw new DomainException('Environment account credentials are managed through configuration. Use writer settings below.');
    $username = is_string($input['username'] ?? null) ? strtolower(trim($input['username'])) : '';
    $display = is_string($input['display_name'] ?? null) ? trim($input['display_name']) : '';
    $password = $input['password'] ?? '';
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/D',$username)) throw new InvalidArgumentException('Use a username of 3–80 letters, numbers, dots, underscores or hyphens.');
    if (strcasecmp($username,env_value('ADMIN_USERNAME') ?? '') === 0) throw new InvalidArgumentException('This username is unavailable.');
    if ($display === '' || strlen($display)>120) throw new InvalidArgumentException('Display name is required (up to 120 bytes).');
    if (!is_string($password) || ($password !== '' && (strlen($password)<12 || strlen($password)>72))) throw new InvalidArgumentException('Use a password of 12–72 bytes.');
    $active = $input['active'] ?? '0';
    if (!in_array($active,['0','1',0,1],true)) throw new InvalidArgumentException('Invalid account status.');
    $pdo = admin_users_pdo();
    $existing = $id ? admin_user_find($id) : null;
    if ($id && !$existing) throw new InvalidArgumentException('Editor not found.');
    if (!$id && $password === '') throw new InvalidArgumentException('A password is required.');
    $hash = $password !== '' ? password_hash($password,PASSWORD_DEFAULT) : $existing['password_hash'];
    try {
        if ($id) {
            $changed = $password !== '' || $username !== $existing['username'] || (int)$active !== (int)$existing['active'] || $role !== $existing['role'];
            $stmt = $pdo->prepare('UPDATE admin_users SET username=?,display_name=?,password_hash=?,active=?,role=?,auth_version=auth_version+?,updated_at=? WHERE id=?');
            $stmt->execute([$username,$display,$hash,(int)$active,$role,(int)$changed,time(),$id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO admin_users(username,display_name,password_hash,active,role,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$username,$display,$hash,(int)$active,$role,time(),time()]);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new InvalidArgumentException('This username is unavailable.');
        throw $e;
    }
    return $id;
}
