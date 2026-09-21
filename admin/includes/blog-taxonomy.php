<?php
declare(strict_types=1);

// Extend the existing category table; IDs remain stable when names/slugs change.
function blog_taxonomy_schema(PDO $pdo): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(blog_categories)')->fetchAll(), 'name');
    if (!in_array('parent_id', $columns, true)) {
        $pdo->beginTransaction();
        try {
            blog_categories_seed($pdo);
            $pdo->exec('ALTER TABLE blog_categories ADD COLUMN slug TEXT');
            $pdo->exec('ALTER TABLE blog_categories ADD COLUMN parent_id TEXT REFERENCES blog_categories(id) ON DELETE RESTRICT');
            $pdo->exec('UPDATE blog_categories SET slug = id');
            $pdo->exec('ALTER TABLE blog_posts ADD COLUMN category_id TEXT REFERENCES blog_categories(id) ON DELETE RESTRICT');
            $pdo->exec('UPDATE blog_posts SET category_id = (SELECT id FROM blog_categories WHERE name = blog_posts.category)');
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS blog_category_slug ON blog_categories(slug)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS blog_category_parent ON blog_categories(parent_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS blog_post_category ON blog_posts(category_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tags (
        id TEXT PRIMARY KEY, name TEXT NOT NULL COLLATE NOCASE UNIQUE, slug TEXT NOT NULL UNIQUE,
        created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS blog_post_tags (
        post_id TEXT NOT NULL REFERENCES blog_posts(id) ON DELETE CASCADE,
        tag_id TEXT NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
        PRIMARY KEY(post_id, tag_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS blog_post_tags_tag ON blog_post_tags(tag_id)');
}

function blog_taxonomy_categories(PDO $pdo, bool $counts = false): array
{
    $rows = $pdo->query($counts
        ? 'SELECT c.*, COUNT(p.id) AS post_count FROM blog_categories c LEFT JOIN blog_posts p ON p.category_id=c.id GROUP BY c.id ORDER BY c.sort_order DESC,c.name'
        : 'SELECT *,0 AS post_count FROM blog_categories ORDER BY sort_order DESC,name')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[$row['id']] = [
            'id'=>$row['id'], 'name'=>$row['name'], 'slug'=>$row['slug'], 'parent_id'=>$row['parent_id'],
            'description'=>$row['description'], 'sortOrder'=>(int)$row['sort_order'],
            'postCount'=>(int)$row['post_count'], 'createdAt'=>(int)$row['created_at'], 'updatedAt'=>(int)$row['updated_at'],
        ];
    }
    $result = [];
    $walk = function (?string $parent, int $depth, string $prefix) use (&$walk, &$result, $map): void {
        foreach ($map as $item) {
            if ($item['parent_id'] !== $parent) continue;
            $item['parent_name'] = $map[$parent ?? '']['name'] ?? null;
            $item['depth'] = $depth;
            $item['label'] = $prefix . $item['name'];
            $result[] = $item;
            $walk($item['id'], $depth + 1, $item['label'] . ' › ');
        }
    };
    $walk(null, 0, '');
    return $result;
}

function blog_tags_all(): array
{
    return blogs_pdo()->query('SELECT t.*, COUNT(pt.post_id) AS postCount, t.updated_at AS updatedAt FROM tags t LEFT JOIN blog_post_tags pt ON pt.tag_id=t.id GROUP BY t.id ORDER BY t.name')->fetchAll();
}

function blog_taxonomy_save(string $kind, array $input): array
{
    $category = $kind === 'category';
    $table = $category ? 'blog_categories' : 'tags';
    $name = normalize_blog_category_name($input['name'] ?? null);
    $rawSlug = $input['slug'] ?? '';
    if (!$name || !is_string($rawSlug) || strlen(trim($rawSlug)) > 96) return ['ok'=>false,'errors'=>['Use a name of 1–50 characters and a slug of at most 96 characters.']];
    $slug = normalize_slug(trim($rawSlug) !== '' ? $rawSlug : $name);
    if ($slug === '') return ['ok'=>false,'errors'=>['Slug must contain letters or numbers.']];
    $id = is_string($input['id'] ?? null) ? trim($input['id']) : '';
    $pdo = blogs_pdo();
    $pdo->beginTransaction();
    try {
        $existing = null;
        if ($id !== '') {
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE id=?"); $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) throw new InvalidArgumentException('Taxonomy entry no longer exists.');
        }
        $stmt = $pdo->prepare("SELECT id FROM $table WHERE (slug=? OR lower(name)=lower(?)) AND id<>?");
        $stmt->execute([$slug,$name,$id]);
        if ($stmt->fetchColumn()) throw new InvalidArgumentException('Name or slug already exists.');
        $id = $id !== '' ? $id : $slug;
        // A historical stable ID may already belong to an entry with a renamed slug.
        if (!$existing) {
            $stmt = $pdo->prepare("SELECT id FROM $table WHERE id=?"); $stmt->execute([$id]);
            if ($stmt->fetchColumn()) $id = $slug . '-' . bin2hex(random_bytes(4));
        }
        $parent = array_key_exists('parent_id', $input) ? $input['parent_id'] : ($existing['parent_id'] ?? null);
        $parent = $parent === '' ? null : $parent;
        if ($category && $parent !== null) {
            if (!is_string($parent)) throw new InvalidArgumentException('Invalid parent category.');
            $parents = $pdo->query('SELECT id,parent_id FROM blog_categories')->fetchAll(PDO::FETCH_KEY_PAIR);
            $cursor = $parent; $visited = [$id=>true];
            while ($cursor !== null) {
                if (isset($visited[$cursor])) throw new InvalidArgumentException('Category hierarchy cannot contain a cycle or its own parent.');
                if (!array_key_exists($cursor,$parents)) throw new InvalidArgumentException('Parent category no longer exists.');
                $visited[$cursor] = true; $cursor = $parents[$cursor];
            }
        }
        $now = time();
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE $table SET name=?,slug=?,updated_at=? WHERE id=?");
            $stmt->execute([$name,$slug,$now,$id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO $table(id,name,slug,created_at,updated_at) VALUES(?,?,?,?,?)");
            $stmt->execute([$id,$name,$slug,$now,$now]);
        }
        if ($category) {
            $stmt = $pdo->prepare('UPDATE blog_categories SET parent_id=?,description=?,sort_order=? WHERE id=?');
            $stmt->execute([$parent,substr(trim((string)($input['description'] ?? $existing['description'] ?? '')),0,180),max(0,min(100000,(int)($input['sort_order'] ?? $existing['sort_order'] ?? 0))),$id]);
            $stmt = $pdo->prepare('UPDATE blog_posts SET category=? WHERE category_id=?');
            $stmt->execute([$name,$id]);
        }
        $pdo->commit();
    } catch (InvalidArgumentException $error) {
        $pdo->rollBack(); return ['ok'=>false,'errors'=>[$error->getMessage()]];
    } catch (Throwable $error) {
        $pdo->rollBack(); throw $error;
    }
    unset($GLOBALS['seo_settings_cache']);
    blog_clear_api_cache();
    return ['ok'=>true,$kind=>$category ? blog_category_find($id) : ['id'=>$id,'name'=>$name,'slug'=>$slug]];
}

function blog_taxonomy_delete(string $kind, string $id): array
{
    $pdo = blogs_pdo(); $category = $kind === 'category';
    $pdo->beginTransaction();
    try {
        if ($category) {
            $stmt = $pdo->prepare('SELECT 1 FROM blog_categories WHERE parent_id=? UNION ALL SELECT 1 FROM blog_posts WHERE category_id=? LIMIT 1');
            $stmt->execute([$id,$id]);
            if ($stmt->fetchColumn()) throw new InvalidArgumentException('Reassign children and posts before deleting this category.');
            if ((int)$pdo->query('SELECT COUNT(*) FROM blog_categories')->fetchColumn() <= 1) throw new InvalidArgumentException('At least one category is required.');
        } else {
            $stmt = $pdo->prepare('DELETE FROM blog_post_tags WHERE tag_id=?'); $stmt->execute([$id]);
        }
        $stmt = $pdo->prepare('DELETE FROM ' . ($category ? 'blog_categories' : 'tags') . ' WHERE id=?'); $stmt->execute([$id]);
        $deleted = $stmt->rowCount() > 0;
        $pdo->commit();
    } catch (InvalidArgumentException $error) {
        $pdo->rollBack(); return ['ok'=>false,'error'=>$error->getMessage()];
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
    unset($GLOBALS['seo_settings_cache']); blog_clear_api_cache();
    return ['ok'=>true,'deleted'=>$deleted];
}

function blog_taxonomy_validate_assignment(PDO $pdo, array $blog): array
{
    $byId = array_key_exists('categoryId',$blog);
    $value = $byId ? $blog['categoryId'] : ($blog['category'] ?? '');
    if (!is_string($value)) throw new InvalidArgumentException('Invalid category.');
    $stmt = $pdo->prepare('SELECT id,name FROM blog_categories WHERE ' . ($byId ? 'id' : 'name') . '=?');
    $stmt->execute([$value]); $category = $stmt->fetch();
    if (!$category) throw new InvalidArgumentException('Category no longer exists.');
    $blog['categoryId'] = $category['id']; $blog['category'] = $category['name'];
    if (array_key_exists('tagIds',$blog)) {
        if (!is_array($blog['tagIds']) || count($blog['tagIds']) > 100) throw new InvalidArgumentException('Invalid tags (maximum 100).');
        foreach ($blog['tagIds'] as $id) if (!is_string($id) || strlen($id)>110) throw new InvalidArgumentException('Invalid tag ID.');
        $blog['tagIds'] = array_values(array_unique($blog['tagIds']));
        if ($blog['tagIds']) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM tags WHERE id IN (' . implode(',',array_fill(0,count($blog['tagIds']),'?')) . ')');
            $stmt->execute($blog['tagIds']);
            if ((int)$stmt->fetchColumn() !== count($blog['tagIds'])) throw new InvalidArgumentException('An assigned tag no longer exists.');
        }
    }
    return $blog;
}

function blog_taxonomy_assign_tags(PDO $pdo, array $blog): void
{
    if (!array_key_exists('tagIds',$blog)) return;
    $stmt = $pdo->prepare('DELETE FROM blog_post_tags WHERE post_id=?'); $stmt->execute([$blog['id']]);
    $stmt = $pdo->prepare('INSERT INTO blog_post_tags(post_id,tag_id) VALUES(?,?)');
    foreach ($blog['tagIds'] as $id) $stmt->execute([$blog['id'],$id]);
}

// Two grouped lookups per page, independent of the number of posts.
function blogs_add_taxonomy(PDO $pdo, array $posts): array
{
    if (!$posts) return [];
    $categories = array_column(blog_taxonomy_categories($pdo),null,'id');
    $stmt = $pdo->prepare('SELECT pt.post_id,t.id,t.name,t.slug FROM blog_post_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.post_id IN (' . implode(',',array_fill(0,count($posts),'?')) . ') ORDER BY t.name');
    $stmt->execute(array_column($posts,'id')); $tags = [];
    foreach ($stmt->fetchAll() as $tag) $tags[$tag['post_id']][] = ['id'=>$tag['id'],'name'=>$tag['name'],'slug'=>$tag['slug']];
    foreach ($posts as &$post) {
        $category = $categories[$post['categoryId'] ?? ''] ?? null;
        $parent = $category ? ($categories[$category['parent_id'] ?? ''] ?? null) : null;
        $post['categoryHierarchy'] = $category ? ['name'=>$category['name'],'slug'=>$category['slug'],'parent'=>$parent ? ['name'=>$parent['name'],'slug'=>$parent['slug']] : null] : null;
        $post['categoryLabel'] = $category['label'] ?? $post['category'];
        $post['tags'] = $tags[$post['id']] ?? [];
        $post['tagIds'] = array_column($post['tags'],'id');
    }
    return $posts;
}

function blog_public_tags(array $post): array
{
    return array_map(static fn(array $tag): array => ['name'=>$tag['name'],'slug'=>$tag['slug']],$post['tags'] ?? []);
}

// Legacy JSON/ZIP imports are trusted admin flows, and may contain older category names.
function blog_taxonomy_import_category(PDO $pdo, string $name): void
{
    $stmt = $pdo->prepare('SELECT id FROM blog_categories WHERE name=?'); $stmt->execute([$name]);
    if ($stmt->fetchColumn()) return;
    $slug = normalize_slug($name) ?: 'category';
    $stmt = $pdo->prepare('SELECT id FROM blog_categories WHERE id=? OR slug=?'); $stmt->execute([$slug,$slug]);
    if ($stmt->fetchColumn()) $slug .= '-' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare('INSERT INTO blog_categories(id,name,slug,created_at,updated_at) VALUES(?,?,?,?,?)');
    $stmt->execute([$slug,$name,$slug,time(),time()]);
}

// Resolve legacy names, slugs or stable IDs once, then walk relationships in SQLite.
// Selecting a child directly stays exact; selecting a root includes its whole subtree.
function blog_category_filter_ids(PDO $pdo, string $value): array
{
    $stmt = $pdo->prepare('WITH RECURSIVE selected AS (
        SELECT id,parent_id FROM blog_categories
        WHERE id=:value OR slug=:value OR name=:value COLLATE NOCASE
        ORDER BY CASE WHEN id=:value THEN 0 WHEN slug=:value THEN 1 ELSE 2 END LIMIT 1
    ), descendants(id) AS (
        SELECT id FROM selected
        UNION
        SELECT c.id FROM blog_categories c JOIN descendants d ON c.parent_id=d.id
        WHERE EXISTS (SELECT 1 FROM selected WHERE parent_id IS NULL)
    ) SELECT id FROM descendants');
    $stmt->execute([':value'=>trim($value)]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function blog_category_filter_sql(array $ids, array &$params, string $prefix): string
{
    if (!$ids) return '0 = 1';
    $keys = [];
    foreach ($ids as $index=>$id) {
        $key = ':' . $prefix . $index;
        $keys[] = $key; $params[$key] = $id;
    }
    return 'category_id IN (' . implode(',', $keys) . ')';
}
