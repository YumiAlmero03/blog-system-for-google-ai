<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir() . '/writers-' . bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR=' . $dir); ini_set('session.save_path',$dir);
putenv('ADMIN_USERNAME=owner-test'); putenv('ADMIN_PASSWORD_HASH=' . password_hash('Owner password for testing!',PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET=' . str_repeat('test-secret-',6));
// Exercise the previous schema with a real preserved identity/hash.
$legacy = new PDO('sqlite:' . $dir . '/blogs.sqlite');
$legacy->exec("CREATE TABLE admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE COLLATE NOCASE,display_name TEXT NOT NULL,password_hash TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'editor' CHECK(role='editor'),active INTEGER NOT NULL DEFAULT 1 CHECK(active IN(0,1)),auth_version INTEGER NOT NULL DEFAULT 1,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,last_login_at INTEGER)");
$hash = password_hash('Preserved editor password!',PASSWORD_DEFAULT);
$legacy->prepare("INSERT INTO admin_users VALUES(17,'old-editor','Existing Writer',?,'editor',1,3,100,200,150)")->execute([$hash]);
$legacy->exec("UPDATE sqlite_sequence SET seq=50 WHERE name='admin_users'");
$before = $legacy->query('SELECT * FROM admin_users')->fetch(PDO::FETCH_ASSOC); $legacy = null;
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/public-detail.php';
require_once __DIR__ . '/../includes/blog-renderer.php';
function writer_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function writer_reject(callable $call,string $class): void {
    try { $call(); } catch (Throwable $e) { writer_check($e instanceof $class,'Expected ' . $class . ', got ' . get_class($e)); return; }
    throw new RuntimeException('Invalid action accepted.');
}
try {
    $pdo = blogs_pdo(); $after = admin_user_find(17);
    writer_check(array_intersect_key($after,$before)===$before,'Migration preserves every existing field');
    admin_users_schema($pdo); writer_check(admin_user_find(17)['password_hash']===$hash,'Repeated migration');
    $owner = auth_authenticate_credentials('owner-test','Owner password for testing!');
    writer_check($owner['account_id']>50,'Autoincrement sequence preserved');
    writer_check($owner['role']==='super_user','Existing environment owner role'); $_SESSION['user']=$owner;
    foreach (['admin','super_user','blogs.publish','unknown-module'] as $cap) writer_check(auth_can($cap),'Super user access');
    $adminId = admin_user_save(['username'=>'normal-admin','display_name'=>'Admin Writer','password'=>'Admin password for testing!','active'=>1,'role'=>'admin']);
    $admin = auth_authenticate_credentials('normal-admin','Admin password for testing!');
    writer_check($admin['role']==='admin','Database admin login');
    $profile = ['id'=>17,'written_name'=>'Public Pen Name','bio'=>"First line\nSecond line <b>safe text</b>",'socials'=>[
        ['platform'=>'<b>Website</b>','url'=>'https://example.test/','label'=>'Personal site'],
        ['platform'=>'X','url'=>'https://example.test/social','label'=>'']]];
    $profile['role_name']='  <b>Super User</b>  ';
    writer_profile_save($profile);
    writer_check(admin_user_find(17)['role']==='editor' && admin_user_find(17)['role_name']==='Super User','Public role name does not change authorization');
    writer_check(admin_user_find(17)['auth_version']===3,'Profile save preserves sessions');
    writer_check(admin_user_find(17)['bio']==="First line\nSecond line safe text",'Plain bio preserves line breaks');
    $base = ['id'=>'writer-post','slug'=>'writer-post','title'=>'Writer test','category'=>blog_default_category(),'author'=>'Legacy author','excerpt'=>'Excerpt','content'=>'## Content','featuredImage'=>'','status'=>'published'];
    $_SESSION['user']=auth_authenticate_credentials('old-editor','Preserved editor password!');
    blogs_upsert($base);
    writer_check(blogs_find('writer-post')['writerId']===17,'Current editor default attribution');
    $_SESSION['user']=$admin; blogs_upsert($base);
    writer_check(blogs_find('writer-post')['writerId']===17,'Other admin preserves omitted attribution');
    $public = public_blog_payload(public_blog_find('writer-post'))['blog']['writer'];
    writer_check($public['name']==='Public Pen Name' && count($public['socials'])===2,'Public name and multiple socials');
    writer_check(array_keys($public)===['name','role_name','profile_image','bio','socials'] && array_keys($public['socials'][0])===['platform','url','label'],'Public field whitelist');
    writer_check($public['role_name']==='Super User' && $public['profile_image']==='', 'New public writer fields');
    foreach (['/etc/passwd','/uploads/blogs/../image.png','javascript:alert(1)','https://example.test/image.png'] as $badImage) writer_reject(fn()=>writer_profile_save($profile+['profile_image'=>$badImage]),InvalidArgumentException::class);
    writer_check($public['socials'][0]['platform']==='Website','Platform sanitized and order preserved');
    $page = blogs_page(25,1,null,null,'Writer test');
    writer_check($page['items'][0]['writer']['name']==='Public Pen Name','Filtered list writer lookup');
    writer_check(array_keys($page['items'][0]['writer'])===['name','role_name','profile_image'],'Lightweight list fields');
    foreach (['javascript:alert(1)','data:text/html,test','ftp://example.test','https://user:pass@example.test'] as $url) {
        $bad=$profile; $bad['socials'][0]['url']=$url; writer_reject(fn()=>writer_profile_save($bad),InvalidArgumentException::class);
    }
    writer_check(count(writer_socials($pdo,17))===2,'Rejected profile leaves links intact');
    $profile['socials']=[$profile['socials'][1]]; $profile['written_name']=''; writer_profile_save($profile);
    writer_check(count(writer_socials($pdo,17))===1,'Remove social link');
    writer_check(public_blog_find('writer-post')['writer']['name']==='Existing Writer','Display name fallback');
    blogs_upsert($base+['writerId'=>'']);
    writer_check(public_blog_find('writer-post')['writer']['name']==='Legacy author','Legacy fallback');
    writer_reject(fn()=>blogs_upsert($base+['writerId'=>999999]),InvalidArgumentException::class);
    writer_reject(fn()=>blogs_upsert($base+['writerId'=>['17']]),InvalidArgumentException::class);
    writer_reject(fn()=>admin_user_save(['id'=>$adminId,'role'=>'super_user']),DomainException::class);
    writer_reject(fn()=>admin_user_save(['role'=>'super_user']),DomainException::class);
    writer_reject(fn()=>writer_profile_save($profile+['role'=>'super_user']),DomainException::class);
    $_SESSION['user']=auth_authenticate_credentials('old-editor','Preserved editor password!');
    writer_check(!auth_can('admin') && auth_can('blogs.publish'),'Editor capabilities retained');
    writer_reject(fn()=>admin_user_save([]),DomainException::class);
    writer_reject(fn()=>writer_profile_save($profile),DomainException::class);
    $_SESSION['user']=$owner;
    $pdo->exec('UPDATE admin_users SET active=0 WHERE id=17');
    writer_reject(fn()=>blogs_upsert($base+['writerId'=>17]),InvalidArgumentException::class);
    $pdo->exec("UPDATE blog_posts SET writer_id=17 WHERE id='writer-post'");
    blogs_upsert($base+['writerId'=>17]);
    writer_check(blogs_find('writer-post')['writerId']===17,'Historical inactive writer preserved');
    // Count actual profile SELECTs independently of list size.
    $counter = new class('sqlite::memory:') extends PDO {
        public array $queries = [];
        public function prepare(string $query,array $options=[]): PDOStatement|false { $this->queries[]=$query; return parent::prepare($query,$options); }
    };
    $counter->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $counter->exec("CREATE TABLE admin_users(id INTEGER,written_name TEXT,display_name TEXT,role_name TEXT,profile_image TEXT)");
    $counter->exec("INSERT INTO admin_users VALUES(1,'Writer','Fallback','Reviewer','')");
    $many = array_fill(0,100,['writerId'=>1,'author'=>'Legacy']);
    $mapped = blogs_add_writers($counter,$many,false);
    writer_check(count($counter->queries)===1 && !str_contains($counter->queries[0],'bio') && count($mapped)===100,'One grouped summary query, no bio/social lookup');
    writer_check($pdo->query('PRAGMA foreign_key_check')->fetchAll()===[],'Foreign key integrity');
    echo "PASS: migration preserves identities/passwords; writer defaults/updates/fallbacks; grouped list lookup; bio/social validation and removal; public field privacy; super/admin/editor permissions.\n";
} finally {
    session_write_close(); foreach (glob($dir . '/*') ?: [] as $file) if (is_file($file)) unlink($file); rmdir($dir);
}
