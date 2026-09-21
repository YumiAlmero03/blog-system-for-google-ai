<?php
declare(strict_types=1);

function writer_public_name(array $user, string $fallback = 'Editorial Team'): string
{
    return trim($user['written_name'] ?? '') ?: (trim($user['display_name'] ?? '') ?: ($fallback ?: 'Editorial Team'));
}

function writer_socials(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT platform,url,label FROM writer_social_links WHERE user_id=? ORDER BY sort_order,id');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

function writer_profile_save(array $input): int
{
    if (!in_array($_SESSION['user']['role'] ?? '',['admin','super_user'],true)) throw new DomainException('Access denied.');
    foreach (['role','username','password','active','auth_version','auth_source'] as $field) {
        if (array_key_exists($field,$input)) throw new DomainException('Account changes require the account management action.');
    }
    $id = filter_var($input['id'] ?? null,FILTER_VALIDATE_INT);
    $existing = $id ? admin_user_find($id) : null;
    if (!$id || !$existing) throw new InvalidArgumentException('Writer not found.');
    foreach (['written_name'=>120,'bio'=>3000] as $key=>$max) {
        if (!is_string($input[$key] ?? null) || strlen($input[$key])>$max) throw new InvalidArgumentException('Invalid writer ' . $key . '.');
    }
    $roleName = $input['role_name'] ?? $existing['role_name'];
    if (!is_string($roleName) || strlen($roleName)>120 || preg_match('/[\x00-\x1f\x7f]/',$roleName)) throw new InvalidArgumentException('Role Name must be plain text up to 120 bytes.');
    $roleName = trim(strip_tags($roleName));
    $image = array_key_exists('profile_image',$input) ? writer_validate_image($input['profile_image']) : $existing['profile_image'];
    $socials = $input['socials'] ?? [];
    if (!is_array($socials) || count($socials)>20) throw new InvalidArgumentException('Use up to 20 social links.');
    $clean = [];
    foreach ($socials as $social) {
        if (!is_array($social)) throw new InvalidArgumentException('Invalid social link.');
        foreach (['platform'=>80,'label'=>120,'url'=>2048] as $key=>$max) {
            if (!is_string($social[$key] ?? '') || strlen($social[$key] ?? '')>$max) throw new InvalidArgumentException('Invalid social ' . $key . '.');
        }
        $url = trim($social['url'] ?? '');
        $platform = trim(strip_tags($social['platform'] ?? ''));
        $label = trim(strip_tags($social['label'] ?? ''));
        if ($url === '' && $platform === '' && $label === '') continue;
        if ($platform === '' || !filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url,PHP_URL_SCHEME) ?? ''),['http','https'],true)
            || parse_url($url,PHP_URL_USER) !== null || parse_url($url,PHP_URL_PASS) !== null) throw new InvalidArgumentException('Each social link needs a platform and an HTTP(S) URL without credentials.');
        $clean[] = [$platform,$url,$label];
    }
    $pdo = admin_users_pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE admin_users SET written_name=?,bio=?,role_name=?,profile_image=?,updated_at=? WHERE id=?');
        $stmt->execute([trim(strip_tags($input['written_name'])),trim(strip_tags($input['bio'])),$roleName,$image,time(),$id]);
        $pdo->prepare('DELETE FROM writer_social_links WHERE user_id=?')->execute([$id]);
        $stmt = $pdo->prepare('INSERT INTO writer_social_links(user_id,platform,url,label,sort_order) VALUES(?,?,?,?,?)');
        foreach ($clean as $order=>$social) $stmt->execute([$id,...$social,$order]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    blog_clear_api_cache();
    return $id;
}

function writer_default_id(): ?int
{
    if (($_SESSION['user']['id'] ?? '') === 'admin:env') return (int)admin_environment_user()['id'];
    $id = $_SESSION['user']['account_id'] ?? null;
    if (!$id && str_starts_with($_SESSION['user']['id'] ?? '', 'editor:')) $id = (int)substr($_SESSION['user']['id'],7);
    return $id ? (int)$id : null;
}

function writer_validate_id(mixed $value, ?int $existing = null): ?int
{
    if ($value === '' || $value === null) return null;
    $id = filter_var($value,FILTER_VALIDATE_INT);
    if (!$id || $id < 1) throw new InvalidArgumentException('Invalid writer.');
    $row = admin_user_find($id);
    if (!$row || !in_array($row['role'],['editor','admin','super_user'],true) || (!$row['active'] && $id !== $existing)) throw new InvalidArgumentException('Select an active writer.');
    return $id;
}

function writer_options(): array
{
    $rows = blogs_pdo()->query("SELECT id,written_name,display_name FROM admin_users WHERE active=1 AND role IN ('editor','admin','super_user') ORDER BY display_name,id")->fetchAll();
    return array_map(fn($row)=>['id'=>(int)$row['id'],'name'=>writer_public_name($row)],$rows);
}

function blogs_add_writers(PDO $pdo, array $posts, bool $detail = true): array
{
    $ids = array_values(array_unique(array_filter(array_column($posts,'writerId'))));
    $profiles = []; $socials = [];
    if ($ids) {
        $placeholders = implode(',',array_fill(0,count($ids),'?'));
        $fields = $detail ? ",bio" : "";
        $stmt = $pdo->prepare("SELECT id,written_name,display_name,role_name,profile_image$fields FROM admin_users WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) $profiles[(int)$row['id']] = $row;
        if ($detail) {
            $stmt = $pdo->prepare("SELECT user_id,platform,url,label FROM writer_social_links WHERE user_id IN ($placeholders) ORDER BY sort_order,id");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $id = (int)$row['user_id']; unset($row['user_id']); $socials[$id][] = $row;
            }
        }
    }
    foreach ($posts as &$post) {
        $id = $post['writerId'] ?? 0; $profile = $profiles[$id] ?? [];
        $post['writer'] = ['name'=>writer_public_name($profile,$post['author'] ?? 'Editorial Team'),'role_name'=>$profile['role_name'] ?? '', 'profile_image'=>writer_image_url($profile['profile_image'] ?? '')];
        if ($detail) $post['writer'] += ['bio'=>$profile['bio'] ?? '', 'socials'=>$socials[$id] ?? []];
    }
    unset($post);
    return $posts;
}

function writer_image_url(string $path): string
{
    return preg_match('~^/uploads/blogs/[a-f0-9]{32}\.(?:jpg|png|webp|avif)$~D',$path) ? public_url($path) : '';
}

function writer_validate_image(mixed $path): string
{
    if (!is_string($path)) throw new InvalidArgumentException('Invalid profile image.');
    $path = trim($path);
    if ($path === '') return '';
    if (!preg_match('~^/uploads/blogs/[a-f0-9]{32}\.(?:jpg|png|webp|avif)$~D',$path)) throw new InvalidArgumentException('Select an uploaded profile image.');
    $root = realpath(__DIR__ . '/../uploads/blogs');
    $file = realpath(dirname(__DIR__) . $path);
    if (!$root || !$file || dirname($file) !== $root || !is_file($file) || filesize($file)>3*1024*1024) throw new InvalidArgumentException('Profile image is unavailable.');
    require_once __DIR__ . '/image-validation.php';
    $validation = validate_blog_image_file($file,pathinfo($file,PATHINFO_EXTENSION));
    if (!$validation['ok']) throw new InvalidArgumentException('Invalid profile image content.');
    return $path;
}
