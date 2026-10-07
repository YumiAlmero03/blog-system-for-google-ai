const fs = require('fs');
const {spawn, spawnSync} = require('child_process');
const path=require('path'), os=require('os');
const root=path.resolve(__dirname, '../..');
const port=20000 + Math.floor(Math.random()*20000);
const chromeBinary=process.env.CHROME_BINARY || (process.platform==='darwin' ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : 'chromium');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'yoast-ui-'));
const source=fs.readFileSync(path.join(root,'admin/blog-publish.php'),'utf8');
const functions=source.slice(source.indexOf('      function checkItem('),source.indexOf('      function measureSeoTitleWidth('));
const ids=['seoScoreBadge','seoMeterScore','seoMeterRing','seoMeterStatus','seoMeterHelp','yoastBottomScore','seoAnalysisIcon','readabilityAnalysisIcon','seoCheckList','readabilityCheckList','readabilityHighlightToggle','readabilityHighlightStatus'];
const html=`<!doctype html><html><body><div id="result">RUNNING</div><div id="editorShell" class="editor-mode-write"><div id="wysiwygEditor" contenteditable="true"><p>The ball was <strong>thrown</strong> by the player. The game was played by the team. The prize was awarded to the winner.</p></div><div id="markdownPreview"></div></div>${ids.map(id=>`<${id.includes('Toggle')?'button':'div'} id="${id}"><span></span></${id.includes('Toggle')?'button':'div'}>`).join('')}
<script src="/admin/yoast-analysis.js"></script><script type="module">
import * as yoast from '/admin/assets-admin/vendor/yoastseo/yoastseo.bundle.mjs';
import Researcher from '/admin/assets-admin/vendor/yoastseo/researcher.bundle.mjs';
${ids.concat(['editorShell','wysiwygEditor','markdownPreview']).map(id=>`const ${id}=document.getElementById('${id}');`).join('\n')}
let readabilityHighlightMode=null,currentReadabilityChecks=[];
const escapeHtml=s=>String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
const setHtml=(el,s)=>{el.innerHTML=s},setText=(el,s)=>{if(el)el.textContent=s},setClassName=(el,s)=>{el.className=s};
${functions}
try {
let n=0; const assert=(ok,message)=>{if(!ok)throw new Error(message);n++};
const original=wysiwygEditor.innerHTML;
const paper=new yoast.Paper(original,{locale:'en_US',keyword:'game'});
const assessor=new yoast.ContentAssessor(new Researcher(paper),{locale:'en_US'});assessor.assess(paper);
assert(assessor.results.some(r=>!r.getText()),'fixture includes empty result');
const checks=normalizeYoastChecks(getYoastAssessorResults(assessor));
assert(checks.every(c=>c.label && !c.label.includes('Yoast assessment completed')),'no fake results');
assert(checks.some(c=>c.id==='passiveVoice' && c.state==='bad' && c.passages.length===3),'actual Yoast passive voice marks');
assert(window.BlogYoastAnalysis.checkState(60)==='warn' && window.BlogYoastAnalysis.checkState(30)==='bad','score scales');
assert(normalizeYoastScore(5)===5,'overall low score not inflated');
assert(normalizeYoastChecks([new yoast.AssessmentResult()]).length===0,'unscored result excluded');
renderAnalysisResults({seoScore:60,readabilityScore:60,seoChecks:[],readabilityChecks:checks,help:'Test'});
assert(readabilityAnalysisIcon.classList.contains('warn'),'moderate score orange');
assert(!CSS.highlights.has('blog-readability'),'off by default');
const selection=getSelection(),caret=document.createRange();caret.setStart(wysiwygEditor.querySelector('strong').firstChild,2);caret.collapse(true);selection.addRange(caret);
readabilityHighlightToggle.click();
assert(CSS.highlights.get('blog-readability').size===3,'all passive passages highlighted across inline elements');
assert(selection.anchorNode===caret.startContainer && selection.anchorOffset===2,'selection unchanged');
assert(wysiwygEditor.innerHTML===original,'no changes to content HTML');
assert(readabilityHighlightToggle.getAttribute('aria-pressed')==='true','toggle accessible state on');
readabilityHighlightToggle.click();assert(!CSS.highlights.has('blog-readability'),'toggle clears highlights');
readabilityCheckList.querySelector('[data-readability-check]').click();
assert(CSS.highlights.get('blog-readability').size===3,'individual issue toggle works');
readabilityCheckList.querySelector('[data-readability-check]').click();assert(!CSS.highlights.has('blog-readability'),'individual toggle clears');
readabilityHighlightToggle.click();
editorShell.className='editor-mode-markdown';updateReadabilityHighlights();
assert(!CSS.highlights.has('blog-readability') && readabilityHighlightStatus.textContent.includes('Switch to Write'),'source mode guidance');
markdownPreview.innerHTML=original;editorShell.className='editor-mode-preview';updateReadabilityHighlights();
assert(CSS.highlights.get('blog-readability').size===3,'preview mode highlights');
renderAnalysisResults({seoScore:90,readabilityScore:90,seoChecks:[],readabilityChecks:[],help:'Test'});
assert(CSS.highlights.get('blog-readability').size===0,'fresh analysis removes old findings');
const br=document.createElement('div');br.innerHTML='<p>First<br>second <em>third</em>.</p><button>First second third.</button>';
assert(window.BlogYoastAnalysis.ranges(br,['First second third.']).length===1,'line breaks and formatting matched, controls skipped');
assert(br.innerHTML.includes('<br>'),'range matching does not edit markup');
document.getElementById('result').textContent='PASS: '+n+' Yoast browser checks';
} catch(error) {document.getElementById('result').textContent='FAIL: '+error.stack;}
</script></body></html>`;
fs.writeFileSync(path.join(temp,'index.html'),html);
fs.writeFileSync(path.join(temp,'router.php'),`<?php $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); if($path==='/'){header('Content-Type:text/html');readfile(__DIR__.'/index.html');exit;} if($path==='/admin/yoast-analysis.js'||str_starts_with($path,'/admin/assets-admin/vendor/yoastseo/')){ $file=realpath(${JSON.stringify(root)}.$path); if($file && ($file===${JSON.stringify(root+'/admin/yoast-analysis.js')}||str_starts_with($file,${JSON.stringify(root+'/admin/assets-admin/vendor/yoastseo/')}))){header('Content-Type:text/javascript');readfile($file);exit;}} http_response_code(404);`);
const server=spawn('php',['-S','127.0.0.1:'+port,path.join(temp,'router.php')],{stdio:'ignore'});
setTimeout(()=>{
try {
const chrome=spawnSync(chromeBinary,['--headless','--disable-gpu','--no-first-run','--no-default-browser-check','--user-data-dir='+path.join(temp,'chrome'),'--dump-dom','--virtual-time-budget=5000','http://127.0.0.1:'+port+'/'],{encoding:'utf8',timeout:45000,maxBuffer:2e6});
const match=chrome.stdout?.match(/<div id="result">([\s\S]*?)<\/div>/);console.log(match?.[1] || chrome.error || chrome.stderr?.slice(-1500) || `Chrome exited with status ${chrome.status}, signal ${chrome.signal}`);
if(!match?.[1]?.startsWith('PASS:'))process.exitCode=1;
}finally{server.kill();setTimeout(()=>fs.rmSync(temp,{recursive:true,force:true}),500);}
},600);
