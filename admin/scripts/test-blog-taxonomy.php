<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Subprocess handler runner uses temporary identities, sessions and storage only.
if (($argv[1] ?? '') === '--handler') {
    $request = json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    ini_set('session.save_path', getenv('APP_STORAGE_DIR'));
    require_once __DIR__ . '/../includes/auth.php';
    $_SERVER['SCRIPT_NAME'] = $request['path'];
    $_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    if (isset($request['role'])) {
        $user = auth_authenticate_credentials($request['role'], 'Taxonomy test password 123!');
        auth_mark_authenticated($user);
        $_COOKIE[AUTH_ACCESS_COOKIE] = jwt_sign(['aud'=>'admin','scope'=>'admin:access','sub'=>$user['id'],'user_id'=>$user['id'],'role'=>$user['role'],'ver'=>$user['auth_version'],'sid'=>$_SESSION['auth_binding'],'iat'=>time(),'nbf'=>time(),'exp'=>time()+900],auth_jwt_secret());
    }
    $_POST = $request['post'] ?? [];
    $_GET = $request['get'] ?? [];
    if (isset($request['role'])) $_POST['csrf_token'] = !empty($request['bad_csrf']) ? 'bad' : csrf_token();
    register_shutdown_function(static function (): void { fwrite(STDERR, 'STATUS:' . (http_response_code() ?: 200)); });
    require dirname(__DIR__, 2) . $request['path'];
    exit;
}
$dir = sys_get_temp_dir() . '/taxonomy-' . bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR=' . $dir);
putenv('ADMIN_USERNAME=super_user'); putenv('ADMIN_PASSWORD_HASH=' . password_hash('Taxonomy test password 123!',PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET=' . str_repeat('taxonomy-test-',5));
ini_set('session.save_path',$dir);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/public-detail.php';
require_once __DIR__ . '/../includes/blog-renderer.php';
function tax_check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function tax_handler(array $request): array {
    $process = proc_open([PHP_BINARY,__FILE__,'--handler'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($request)); fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    tax_check(proc_close($process)===0, 'Handler failure: ' . $error);
    tax_check(preg_match('/^STATUS:(\d+)$/',$error,$match)===1,'Unexpected handler warnings: ' . $error);
    return [(int)$match[1],json_decode($body,true),$body];
}
function tax_remove(string $path): void { foreach (scandir($path) as $name) { if ($name==='.' || $name==='..') continue; $file="$path/$name"; is_dir($file) ? tax_remove($file) : unlink($file); } rmdir($path); }
try {
    // A previous database with custom and colliding normalized category names.
    $old = new PDO('sqlite:' . $dir . '/blogs.sqlite');
    $old->exec('CREATE TABLE blog_categories(id TEXT PRIMARY KEY,name TEXT UNIQUE NOT NULL,description TEXT NOT NULL DEFAULT "",sort_order INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL)');
    $old->exec('INSERT INTO blog_categories VALUES("legacy","Legacy","keep",7,100,200)');
    $old->exec('CREATE TABLE blog_posts(id TEXT PRIMARY KEY,slug TEXT UNIQUE NOT NULL,title TEXT NOT NULL,category TEXT NOT NULL,author TEXT NOT NULL,excerpt TEXT NOT NULL,content TEXT NOT NULL,featured_image TEXT NOT NULL,date_label TEXT NOT NULL,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL)');
    $old->exec('INSERT INTO blog_posts VALUES("legacy-post","legacy-post","Old","Legacy","Original","Excerpt","Body","/uploads/old.svg","Old date",100,200)');
    $old->exec('INSERT INTO blog_posts SELECT "collision", "collision",title,"Legacy!",author,excerpt,content,featured_image,date_label,created_at,updated_at FROM blog_posts');
    $before = $old->query('SELECT * FROM blog_posts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); $old = null;
    $pdo = blogs_pdo();
    foreach ($pdo->query('SELECT * FROM blog_posts ORDER BY id')->fetchAll() as $index=>$row) {
        tax_check(array_intersect_key($row,$before[$index])===$before[$index], 'Migration preserves original post fields');
        tax_check($row['category_id'] !== null,'All legacy categories mapped, including slug collisions');
    }
    tax_check(blog_category_find('legacy')['description']==='keep','Existing category metadata preserved');
    $count = count(blog_categories_all()); blogs_schema($pdo); tax_check(count(blog_categories_all())===$count,'Migration idempotent');
    $_SESSION['user'] = auth_authenticate_credentials('super_user','Taxonomy test password 123!');
    foreach (['editor','admin'] as $role) admin_user_save(['username'=>$role,'display_name'=>$role,'password'=>'Taxonomy test password 123!','active'=>1,'role'=>$role]);
    $promotions = blog_category_find('promotions'); tax_check($promotions !== null,'Top-level Promotions');
    foreach (['VIP','Cash Back','Free Spins'] as $name) {
        $result = blog_category_save(['name'=>$name,'parent_id'=>'promotions']); tax_check($result['ok'],'Create '.$name);
    }
    tax_check(!blog_category_save(['id'=>'vip','name'=>'VIP','parent_id'=>'vip'])['ok'],'Self-parent rejected');
    tax_check(!blog_category_save(['id'=>'promotions','name'=>'Promotions','parent_id'=>'vip'])['ok'],'Cycle rejected');
    tax_check(!blog_category_save(['name'=>'Invalid','parent_id'=>'missing'])['ok'],'Missing parent rejected');
    tax_check(!blog_category_delete('promotions')['ok'],'Parent deletion blocked');
    tax_check(blog_category_save(['id'=>'vip','name'=>'VIP','slug'=>'vip-offers','parent_id'=>null])['ok'],'Parent reassignment');
    tax_check(blog_category_find('vip')['slug']==='vip-offers','Stable ID on slug edit');
    tax_check(blog_category_save(['id'=>'vip','name'=>'VIP','parent_id'=>'promotions'])['ok'],'Move back');
    foreach (['Rewards','Welcome Bonus'] as $name) tax_check(blog_taxonomy_save('tag',['name'=>$name])['ok'],'Tag create');
    tax_check(!blog_taxonomy_save('tag',['name'=>' rewards '])['ok'],'Case-insensitive duplicate');
    foreach ([['name'=>''],['name'=>str_repeat('x',51)],['name'=>'Valid','slug'=>str_repeat('x',97)],['name'=>'Valid','slug'=>'!!!']] as $input) tax_check(!blog_taxonomy_save('tag',$input)['ok'],'Invalid name/slug rejected');
    $post = blog_normalize_existing(['slug'=>'tagged','title'=>'Tagged','content'=>'Body','excerpt'=>'Excerpt','category'=>'VIP']);
    $post['categoryId']='vip'; $post['tagIds']=['rewards','welcome-bonus','rewards'];
    blogs_upsert($post); $saved=blogs_find('tagged');
    tax_check($saved['categoryId']==='vip' && $saved['categoryLabel']==='Promotions › VIP','Stored ID and hierarchy label');
    tax_check(count($saved['tags'])===2,'Multiple unique assignments restored');
    unset($post['tagIds']); blogs_upsert($post); tax_check(count(blogs_find('tagged')['tags'])===2,'Omitted tags preserved');
    $post['tagIds']=['missing'];
    try { blogs_upsert($post); throw new RuntimeException('Invalid tag accepted'); } catch (InvalidArgumentException $expected) {}
    tax_check(count(blogs_find('tagged')['tags'])===2,'Invalid save leaves relationships intact');
    tax_check(!blog_category_delete('vip')['ok'],'Assigned category deletion blocked');
    $counted = new class('sqlite:' . $dir . '/blogs.sqlite') extends PDO {
        public int $queries = 0;
        public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
            $this->queries++;
            return $fetchMode === null ? parent::query($query) : parent::query($query,$fetchMode,...$args);
        }
        public function prepare(string $query, array $options = []): PDOStatement|false {
            $this->queries++; return parent::prepare($query,$options);
        }
    };
    $counted->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    blogs_add_taxonomy($counted,[$saved]); $singleQueries=$counted->queries; $counted->queries=0;
    blogs_add_taxonomy($counted,array_fill(0,100,$saved));
    tax_check($singleQueries===2 && $counted->queries===2,'Taxonomy query count stays two for 1 or 100 posts');

    $detail=public_blog_payload(public_blog_find('tagged'))['blog'];
    tax_check($detail['category']['parent']['name']==='Promotions' && count($detail['tags'])===2,'Detail serializer hierarchy/tags');
    foreach (['GET','POST'] as $method) {
        [$status,$data]=tax_handler(['path'=>'/api/blog-post-list.php','method'=>$method]);
        $item=array_values(array_filter($data['blogs'],static fn($b)=>$b['slug']==='tagged'))[0];
        tax_check($status===200 && $item['category']['parent_name']==='Promotions' && count($item['tags'])===2,'Public list handler '.$method);
    }
    [$status,$data]=tax_handler(['path'=>'/api/blog.php','method'=>'GET','get'=>['slug'=>'tagged']]);
    tax_check($status===200 && $data['blog']['category']['parent']['slug']==='promotions','Detail endpoint');
    [$status,,$html]=tax_handler(['path'=>'/blog/view.php','method'=>'GET','get'=>['slug'=>'tagged']]);
    tax_check($status===200 && str_contains($html,'Promotions › VIP') && str_contains($html,'Welcome Bonus'),'Public taxonomy display');
    foreach (['/admin/blog-categories.php','/admin/blog-category-save.php','/admin/blog-tags.php','/admin/blog-tag-save.php'] as $path) {
        [$status]=tax_handler(['path'=>$path,'role'=>'editor']); tax_check($status===403,'Editor management denied '.$path);
    }
    [$status]=tax_handler(['path'=>'/admin/blog-tag-save.php','role'=>'admin','bad_csrf'=>true,'post'=>['name'=>'Blocked']]); tax_check($status===403,'CSRF denied');
    foreach (['admin','super_user'] as $role) {
        [$status,$data]=tax_handler(['path'=>'/admin/blog-tag-save.php','role'=>$role,'post'=>['name'=>'Created by '.$role]]);
        tax_check($status===200 && $data['ok'],'Authorized tag CRUD '.$role);
    }
    [$status,$data]=tax_handler(['path'=>'/admin/blog-tag-list.php','role'=>'editor']); tax_check($status===200 && count($data['tags'])===4,'Editor lookup');
    $quick=['id'=>'tagged','slug'=>'tagged','title'=>'Tagged','seo_title'=>'Tagged','category_id'=>'vip','tags_present'=>'1','tag_ids'=>['rewards'],'excerpt'=>'Excerpt','content'=>'Body','status'=>'published'];
    [$status,$data]=tax_handler(['path'=>'/admin/blog-save.php','role'=>'editor','post'=>$quick]); tax_check($status===200 && $data['ok'],'Editor assignment via blog-save');
    tax_check(blogs_find('tagged')['tagIds']===['rewards'],'Quick Edit tags saved');
    unset($quick['tag_ids']);
    [$status]=tax_handler(['path'=>'/admin/blog-save.php','role'=>'editor','post'=>$quick]); tax_check($status===200 && blogs_find('tagged')['tags']===[],'Clear all tags');
    foreach (['blog-categories','blog-tags','blog-publish','blogs'] as $page) {
        [$status,,$html]=tax_handler(['path'=>'/admin/'.$page.'.php','method'=>'GET','role'=>'admin']);
        tax_check($status===200,'Render admin '.$page);
        preg_match_all('~<script(?:\s[^>]*)?>(.*?)</script>~s',$html,$scripts);
        file_put_contents($dir.'/'.$page.'.js',implode("\n",$scripts[1]));
        $process=proc_open(['node','--check',$dir.'/'.$page.'.js'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        tax_check(proc_close($process)===0,'Rendered JavaScript: '.$page.' '.$out);
    }
    tax_check(blog_taxonomy_save('tag',['id'=>'rewards','name'=>'Renamed','slug'=>'renamed'])['ok'],'Tag update');
    $post['tagIds']=['rewards']; blogs_upsert($post);
    tax_check(blog_taxonomy_delete('tag','rewards')['ok'] && blogs_find('tagged')!==null && blogs_find('tagged')['tags']===[],'Tag deletion preserves post');
    // Category filtering uses stable relationships in the shared admin/public path.
    foreach (['promotions','vip','cash-back','free-spins','legacy'] as $i=>$categoryId) {
        $fixture = blog_normalize_existing(['slug'=>'filter-'.$categoryId,'title'=>'Filter fixture','content'=>'Body','excerpt'=>'Excerpt','category'=>blog_category_find($categoryId)['name']]);
        $fixture['categoryId']=$categoryId; blogs_upsert($fixture);
        $pdo->prepare('UPDATE blog_posts SET created_at=? WHERE id=?')->execute([1000+$i,$fixture['id']]);
    }
    tax_check(blog_category_save(['name'=>'Nested','parent_id'=>'vip'])['ok'],'Deeper category');
    $fixture['id']=$fixture['slug']='filter-nested'; $fixture['categoryId']='nested'; blogs_upsert($fixture);
    $expected=['tagged','filter-promotions','filter-vip','filter-cash-back','filter-free-spins','filter-nested'];
    $filtered=blogs_page(100,1,'promotions','published');
    $ids=array_column($filtered['items'],'id'); sort($ids); sort($expected);
    tax_check($ids===$expected && $filtered['total']===6,'Parent includes own posts and all descendants without duplicates');
    tax_check(blogs_page(100,1,'VIP')['total']===2,'Direct child excludes siblings and its descendants');
    tax_check(blog_category_save(['id'=>'promotions','name'=>'Promotions','slug'=>'promotion-offers'])['ok'],'Rename filter slug');
    tax_check(blogs_page(100,1,'promotion-offers')['total']===6,'Custom slug resolves to stable parent ID');
    tax_check(blogs_page(100,1,'promotions',null,'Filter fixture')['total']===5,'Text search combines with category filter');
    tax_check(blogs_page(100,1,null,null,'Promotions')['total']===6,'Parent name search includes descendants');
    tax_check(blogs_page(100,1,'missing-category')['total']===0,'Unknown filter never returns unrelated posts');
    $paged=[];
    for ($page=1;$page<=3;$page++) {
        $result=blogs_page(2,$page,'promotions','published');
        tax_check($result['total']===6 && $result['totalPages']===3,'Pagination totals');
        $paged=array_merge($paged,array_column($result['items'],'id'));
        foreach (['GET','POST'] as $method) {
            $input=['category'=>'promotions','count'=>'2','page'=>(string)$page];
            [$status,$public]=tax_handler(['path'=>'/api/blog-post-list.php','method'=>$method,'get'=>$input,'post'=>$input]);
            [$adminStatus,$admin]=tax_handler(['path'=>'/admin/blog-list.php','role'=>'editor','post'=>$input]);
            tax_check($status===200 && $adminStatus===200 && array_column($public['blogs'],'id')===array_column($admin['blogs'],'id'),'Admin/API category page parity');
            tax_check($public['pagination']['total']===6 && $admin['pagination']['total']===6,'Endpoint pagination totals');
        }
    }
    tax_check($paged===array_column($filtered['items'],'id') && count(array_unique($paged))===6,'Sorting and pagination preserve unique posts');
    foreach (['promotions','vip','cash-back','free-spins','legacy','nested'] as $categoryId) blogs_delete('filter-'.$categoryId);
    blog_category_delete('nested');
    tax_check(blog_category_delete('free-spins')['ok'],'Unused child deletion');
    blogs_schema($pdo); tax_check(blog_category_find('free-spins')===null,'Deleted category stays deleted');
    tax_check($pdo->query('PRAGMA foreign_key_check')->fetchAll()===[],'Foreign key integrity');
    echo "PASS: legacy migration, stable IDs, hierarchy/cycles/reassignment/deletion, tag CRUD, assignment/preservation/clearing, public APIs/view, recursive category filtering/search, pagination and admin/API parity, admin/editor permissions, CSRF and rendered admin JavaScript.\n";
} finally { tax_remove($dir); }
