<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__,2);$tmp=sys_get_temp_dir().'/admin-layout-'.bin2hex(random_bytes(5));mkdir($tmp,0700);
function layout_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function layout_remove(string $d):void{foreach(scandir($d) as $f)if($f!=='.'&&$f!=='..'){is_dir($d.'/'.$f)?layout_remove($d.'/'.$f):unlink($d.'/'.$f);}rmdir($d);}
$server=null;
try {
    foreach(['admin/includes','admin/storage','admin/uploads','admin/scripts','admin/data','admin/chats','admin/update-packages','api','promo-code','.git'] as $d)mkdir($tmp.'/'.$d,0700,true);
    copy($root.'/.htaccess',$tmp.'/.htaccess');
    $files=['admin/.env'=>'private','admin/includes/config.php'=>'private','admin/storage/blogs.sqlite'=>'private','admin/scripts/tool.php'=>'private','admin/uploads/image.svg'=>'image','admin/login.php'=>'login','admin/login-handler.php'=>'handler','admin/logout.php'=>'logout','admin/VerificationKey123.txt'=>'VerificationKey123','api/test.php'=>'api','promo-code/view.php'=>'promo','sitemap-index.xml'=>'sitemap','robots.txt'=>'robots','.git/config'=>'private'];
    foreach($files as $p=>$body)file_put_contents($tmp.'/'.$p,$body);
    $socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
    $apache=getenv('TEST_HTTPD')?:'/opt/homebrew/bin/httpd';$modules=getenv('TEST_HTTPD_MODULES')?:'/opt/homebrew/opt/httpd/lib/httpd/modules';
    $conf="ServerRoot \"$tmp\"\nServerName localhost\nListen $address\nPidFile \"$tmp/httpd.pid\"\nErrorLog \"$tmp/httpd.log\"\n";
    foreach(['mpm_prefork','authz_core','unixd','mime','dir','rewrite'] as $module)$conf.="LoadModule {$module}_module \"$modules/mod_$module.so\"\n";
    $conf.="TypesConfig /dev/null\nDocumentRoot \"$tmp\"\n<Directory \"$tmp\">\nAllowOverride All\nOptions FollowSymLinks\nRequire all granted\n</Directory>\n";
    file_put_contents($tmp.'/httpd.conf',$conf);
    $server=proc_open([$apache,'-X','-f',$tmp.'/httpd.conf'],[0=>['pipe','r'],1=>['file',$tmp.'/process.log','a'],2=>['file',$tmp.'/process.log','a']],$pipes);
    usleep(500000);
    foreach(['/uploads/image.svg'=>[200,'image'],'/VerificationKey123.txt'=>[200,'VerificationKey123'],'/login.php'=>[200,'login'],'/login-handler.php'=>[200,'handler'],'/logout.php'=>[200,'logout'],'/admin/login.php'=>[200,'login'],'/api/test.php'=>[200,'api'],'/promo-code/example'=>[200,'promo'],'/sitemap-index.xml'=>[200,'sitemap'],'/robots.txt'=>[200,'robots'],'/admin/.env'=>[403,null],'/admin/includes/config.php'=>[403,null],'/admin/storage/blogs.sqlite'=>[403,null],'/admin/scripts/tool.php'=>[403,null],'/.git/config'=>[403,null]] as $url=>[$expected,$content]){
        $curl=curl_init('http://'.$address.$url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        layout_check($status===$expected && ($content===null || $body===$content),'HTTP layout: '.$url.' returned '.$status);
    }
    echo "PASS: Apache upload/login/IndexNow rewrites, root API/promo/sitemap/robots, and private backend/Git denial.\n";
} finally {if(is_resource($server)){proc_terminate($server);fclose($pipes[0]);proc_close($server);}layout_remove($tmp);}
