import {renderLayout} from './render.js';
const status=document.querySelector('#status'),events=document.querySelector('#events'),refresh=document.querySelector('#refresh');
let controller,generation=0;
async function load(){
  const current=++generation;controller?.abort();controller=new AbortController();const active=controller,timeout=setTimeout(()=>active.abort(),35000);
  refresh.disabled=true;status.textContent='Loading…';events.replaceChildren();
  try{
    const query=new URLSearchParams({id:new URLSearchParams(location.search).get('iframe')||'1'});
    const sportOverride=new URLSearchParams(location.search).get('sport');if(sportOverride)query.set('sport',sportOverride);
    const response=await fetch('/api/sports-layout.php?'+query,{signal:active.signal,cache:'no-store'}),data=await response.json();
    if(current!==generation)return;if(!response.ok||!data.ok)throw new Error(data.error||'Data unavailable.');
    const headerTitleEl=document.querySelector('#iframe-title'),headerTitleSection=document.querySelector('#iframe-title-section');
    if(data.title_blocks&&data.title_blocks.length){if(headerTitleEl)headerTitleEl.hidden=true;if(headerTitleSection)headerTitleSection.hidden=false;}
    else{if(headerTitleEl){headerTitleEl.hidden=false;headerTitleEl.textContent=data.title;}if(headerTitleSection)headerTitleSection.hidden=true;}
    document.title=data.title;
    renderLayout(events,data);status.dataset.error='false';status.textContent=!data.enabled?'This layout is turned off.':!data.records.length?'No data currently available.':`Updated ${new Date(data.updatedAt).toLocaleString()}${data.truncated?' · Showing a limited number of items.':''}`;
  }catch(error){if(current===generation){status.dataset.error='true';status.textContent=active.signal.aborted?'Request timed out. Please refresh.':error.message;}}
  finally{clearTimeout(timeout);if(current===generation)refresh.disabled=false;}
}
refresh.onclick=load;setInterval(()=>{if(!document.hidden&&!refresh.disabled)load();},60000);load();
