const fs=require('fs'),path=require('path'),os=require('os');
const {spawn,spawnSync}=require('child_process');
const root=path.resolve(__dirname,'../../../..');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'sports-iframe-'));
const port=21000+Math.floor(Math.random()*20000);
const php=process.env.PHP_BINARY||'php';
const chrome=process.env.CHROME_BINARY||(process.platform==='darwin'?'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome':'chromium');
const phpString=s=>"'"+s.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
const setup=`<?php
putenv('APP_STORAGE_DIR='.__DIR__.'/storage');putenv('SITE_BASE_URL=[insert_domain_here]');
require ${phpString(root+'/admin/plugin/odds-api/includes/sports-images.php')};
require ${phpString(root+'/admin/plugin/odds-api/includes/sports-api.php')};
$c=sports_category_save(['name'=>'Basketball','slug'=>'basketball','enabled'=>1]);
$t=tournament_save(['sports_category_id'=>$c['id'],'title'=>'NBA','odds_sport_key'=>'basketball_nba','enabled'=>1]);
$p=sports_registry_pdo();
foreach(['Home Team','Away Team'] as $name) {
 $p->prepare('INSERT INTO participants(sports_category_id,participant_id,name,slug,image_key,image_path,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$c['id'],$name,$name,sports_registry_slugify($name),$name,'/uploads/sports/fixture.png',time(),time()]);
}
sports_images_record('tournament',$t,'saved','/uploads/sports/fixture.png',['source'=>'sportsdataverse'],'');
$layout=[];
foreach(['sport','home_team','away_team'] as $source)$layout[]=['id'=>$source,'type'=>'image','path'=>'$.'.$source,'label'=>$source,'image_source'=>$source,'section'=>$source==='sport'?'title':'loop','width'=>'48px'];
$data=sports_api_project([(object)['sport_key'=>'basketball_nba','home_team'=>'Home Team','away_team'=>'Away Team']],$layout,1,0,'basketball_nba');
file_put_contents(__DIR__.'/response.json',json_encode(['ok'=>true,'enabled'=>true,'title'=>'NBA','updatedAt'=>gmdate('c')]+$data));
`;
fs.writeFileSync(path.join(temp,'setup.php'),setup);
const seeded=spawnSync(php,[path.join(temp,'setup.php')],{encoding:'utf8'});
if(seeded.status!==0)throw new Error(seeded.stderr||seeded.stdout);
fs.writeFileSync(path.join(temp,'fixture.png'),Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==','base64'));
fs.writeFileSync(path.join(temp,'assert.js'),`
let tries=0;
const timer=setInterval(()=>{
 const imgs=[...document.querySelectorAll('img.api-img')];
 if(imgs.length===3 && imgs.every(img=>img.complete)) {
  clearInterval(timer);
  const ok=imgs.every(img=>img.naturalWidth>0 && new URL(img.src).origin===location.origin);
  document.querySelector('#test-result').textContent=ok?'PASS: tournament, home and away logos load under iframe CSP with placeholder site origin':'FAIL: image did not load';
 } else if(++tries>50) { clearInterval(timer);document.querySelector('#test-result').textContent='FAIL: expected three images; got '+imgs.length; }
},100);
`);
fs.writeFileSync(path.join(temp,'router.php'),`<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/api/sports-layout.php'){header('Content-Type:application/json');readfile(__DIR__.'/response.json');exit;}
if($path==='/uploads/sports/fixture.png'){header('Content-Type:image/png');readfile(__DIR__.'/fixture.png');exit;}
if($path==='/assert.js'){header('Content-Type:text/javascript');readfile(__DIR__.'/assert.js');exit;}
if($path==='/odds-api/'){
 ob_start();require ${phpString(root+'/admin/plugin/odds-api/public/index.php')};$html=ob_get_clean();
 echo str_replace('</body>','<div id="test-result">RUNNING</div><script src="/assert.js"></script></body>',$html);exit;
}
return false;
`);
const server=spawn(php,['-S','127.0.0.1:'+port,'-t',root,path.join(temp,'router.php')],{stdio:'ignore'});
setTimeout(()=>{
 try {
  const result=spawnSync(chrome,['--headless','--disable-gpu','--no-first-run','--no-default-browser-check','--user-data-dir='+path.join(temp,'chrome'),'--dump-dom','--virtual-time-budget=7000','http://127.0.0.1:'+port+'/odds-api/'],{encoding:'utf8',timeout:30000,maxBuffer:2e6});
  const match=result.stdout?.match(/<div id="test-result">([\s\S]*?)<\/div>/);
  console.log(match?.[1]||result.error||result.stderr?.slice(-1000)||'Browser failed');
  if(!match?.[1]?.startsWith('PASS:'))process.exitCode=1;
 }finally{server.kill();setTimeout(()=>fs.rmSync(temp,{recursive:true,force:true}),500);}
},700);
