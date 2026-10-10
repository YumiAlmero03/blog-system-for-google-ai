import {renderLayout} from './render.js';
const events=document.querySelector('#events'),refresh=document.querySelector('#refresh');
let controller,generation=0;
async function load(){
  const current=++generation;controller?.abort();controller=new AbortController();const active=controller,timeout=setTimeout(()=>active.abort(),35000);
  refresh.disabled=true;events.replaceChildren();document.querySelector('#status')?.remove();
  try{
    const query=new URLSearchParams({id:new URLSearchParams(location.search).get('iframe')||'1'});
    const sportOverride=new URLSearchParams(location.search).get('sport');if(sportOverride)query.set('sport',sportOverride);
    const colsOverride=new URLSearchParams(location.search).get('columns');if(colsOverride)query.set('columns',colsOverride);
    const response=await fetch('/api/sports-layout.php?'+query,{signal:active.signal,cache:'no-store'}),data=await response.json();
    if(current!==generation)return;if(!response.ok||!data.ok)throw new Error(data.error||'Data unavailable.');
    const headerTitleEl=document.querySelector('#iframe-title'),headerTitleSection=document.querySelector('#iframe-title-section');
    if(data.title_blocks&&data.title_blocks.length){if(headerTitleEl)headerTitleEl.hidden=true;if(headerTitleSection)headerTitleSection.hidden=false;}
    else{if(headerTitleEl){headerTitleEl.hidden=false;headerTitleEl.textContent=data.title;}if(headerTitleSection)headerTitleSection.hidden=true;}
    document.title=data.title;
    if(!data.enabled){events.innerHTML='<p class="status-message">This layout is turned off.</p>';}
    else if(!data.records.length){events.innerHTML='<p class="status-message">No data currently available.</p>';}
    else{renderLayout(events,data);}
  }catch(error){if(current===generation){events.innerHTML='<p class="status-error" data-error="true">'+(active.signal.aborted?'Request timed out. Please refresh.':error.message)+'</p>';}}
  finally{clearTimeout(timeout);if(current===generation)refresh.disabled=false;}
}
refresh.onclick=load;setInterval(()=>{if(!document.hidden&&!refresh.disabled)load();},60000);load();
