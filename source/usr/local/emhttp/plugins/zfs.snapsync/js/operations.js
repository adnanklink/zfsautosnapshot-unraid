(() => {
  'use strict';
  const $=id=>document.getElementById(id), escape=ZfsasUI.escape, base='/plugins/zfs.snapsync/php/';
  const overview=document.querySelector('.zfsas-workspace').dataset.section==='overview';
  const terminal=new Set(['complete','completed','failed','canceled','skipped','recorded']);
  let snapshot=null, selected=null, busy=false, logPoll=null;
  const active=operation=>!terminal.has(operation.state);
  const date=epoch=>epoch ? new Date(epoch*1000).toLocaleString(undefined,{timeZone:snapshot?.timezone || 'UTC',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : 'Not recorded';
  const label=operation=>operation.state==='canceling'?'Stopping · cancellation saved':operation.stateLabel || ({retry_wait:'Waiting to retry',complete:'Completed',running:'Running',queued:'Queued',failed:'Failed',canceled:'Canceled',waiting:'Waiting'}[operation.state] || operation.state);
  function render(data) {
    snapshot=data;
    const available=Object.values(data.sources).every(source=>source.available);
    $('summary-availability').replaceChildren();
    for(const source of Object.values(data.sources)) if(!source.available) {
      const notice=document.createElement('p');notice.className='ui-notice';notice.textContent=source.message;$('summary-availability').append(notice);
    }
    document.querySelectorAll('[data-host-zone]').forEach(node=>node.textContent=data.timezone);
    $('activity-updated').textContent='Updated '+date(data.generatedAt);
    if(overview) {
      $('summary-active').textContent=data.operations.filter(active).length+(available?'':' + ?');
      $('summary-attention').textContent=data.operations.filter(op=>op.state==='failed'||op.recoveryRequired).length+(available?'':' + ?');
      $('summary-paused').textContent=data.sources.configuration?.available?data.pausedSchedules.length:'Unavailable';
      const upcoming=$('upcoming-schedules');
      if (!upcoming.contains(document.activeElement)) { upcoming.replaceChildren();
      for(const schedule of [...data.schedules].sort((a,b)=>(a.preview.nextScheduledTime||Infinity)-(b.preview.nextScheduledTime||Infinity)).slice(0,6)) {
        const item=document.createElement('div');item.className='ui-schedule-item';
        const name=document.createElement('strong');name.textContent=schedule.label;
        const when=document.createElement('p');when.className='muted';when.textContent=schedule.paused?'Paused until Resume':schedule.preview.nextScheduledText || 'Not scheduled';
        item.append(name,when);
        if(schedule.paused) {const resume=document.createElement('button');resume.type='button';resume.textContent='Resume schedule';resume.addEventListener('click',()=>resumeSchedule(schedule,resume));item.append(resume);}
        upcoming.append(item);
      }
      if(!data.schedules.length) upcoming.textContent=data.sources.configuration?.available?'No schedules configured.':'Schedule information unavailable.';
      }
      const attention=$('attention-list');
      if (!attention.contains(document.activeElement)) { attention.replaceChildren();
      const failures=data.operations.filter(op=>op.state==='failed'||op.recoveryRequired).slice(0,4);
      for(const op of failures) { const button=document.createElement('button');button.type='button';button.className='ui-attention-item';button.textContent=op.title+' · '+(op.source||label(op));button.addEventListener('click',()=>open(op.id,button));attention.append(button); }
      if(!failures.length) attention.innerHTML='<div class="ui-empty"><strong>'+ (available?'No reported failures':'Status is incomplete')+'</strong>'+ (available?'Nothing needs attention in the available recent records.':'Some sources are unavailable. See the notices above.')+'</div>';
    }
    }
    rows();
    if(selected && $('operation-detail').open && !busy) { const op=data.operations.find(op=>op.id===selected);if(op) detail(op); }
    return data.operations.some(active);
  }
  function rows() {
    let operations=snapshot.operations;
    const type=$('activity-type')?.value,state=$('activity-state')?.value;
    if(type) operations=operations.filter(op=>op.type===type);
    if(state) operations=operations.filter(op=>state==='active'?active(op):state==='failed'?(op.state==='failed'||op.recoveryRequired):state==='complete'?['complete','completed'].includes(op.state):op.state===state);
    if(overview) operations=operations.slice(0,8);
    const body=$('operation-rows'), existing=new Map([...body.querySelectorAll('[data-operation]')].map(row=>[row.dataset.operation,row]));
    body.querySelector('[data-empty]')?.remove();
    if(!existing.size) body.replaceChildren();
    for(const op of operations) {
      let row=existing.get(op.id);
      if(!row) {row=document.createElement('tr');row.dataset.operation=op.id;row.innerHTML='<td><strong></strong><small class="ui-operation-kind"></small></td><td><code></code></td><td><span class="ui-badge"></span></td><td></td><td><button type="button">Details</button></td>';row.querySelector('button').addEventListener('click',event=>open(op.id,event.currentTarget));}
      existing.delete(op.id);
      row.cells[0].querySelector('strong').textContent=op.title;
      row.cells[0].querySelector('small').textContent=op.parentId?'Run '+op.parentId:op.type;
      row.cells[1].querySelector('code').textContent=[op.source,op.destination].filter(Boolean).join(' → ') || 'Configured datasets';
      const badge=row.cells[2].firstChild;badge.dataset.state=op.state;badge.textContent=label(op);
      row.cells[3].textContent=date(op.createdAt);
      // Avoid detaching focused rows on routine refresh.
      if(row.parentElement!==body) body.append(row);
    }
    for(const row of existing.values()) row.remove();
    if(!operations.length) { const row=document.createElement('tr');row.dataset.empty='1';row.innerHTML='<td colspan="5" class="ui-empty"><strong>No operations to show</strong>New work will appear here. Earlier history may be unavailable after reboot.</td>';body.append(row); }
  }
  function detail(op) {
    $('operation-title').textContent=op.title;
    const entries=[['Status',label(op)],['Source',op.source||'Configured datasets'],['Destination',op.destination||'—'],['Requested',date(op.createdAt)],['Next retry',op.retryAt?date(op.retryAt):'—'],['Waiting for',(op.blocked||[]).join(', ')||'—'],['Run',op.parentId||op.nativeId]];
    const existingLog=$('operation-detail-log');
    const content=$('operation-body');
    if (content.contains(document.activeElement)) return;
    content.innerHTML='<dl>'+entries.map(([key,value])=>'<dt>'+escape(key)+'</dt><dd>'+escape(value)+'</dd>').join('')+'</dl>'+(Number.isFinite(op.progress)?'<label>Reported progress<progress max="100" value="'+Math.max(0,Math.min(100,op.progress))+'"></progress>'+escape(op.progress)+'%</label>':'')+'<p class="ui-notice">'+escape(op.message||'No additional message recorded.')+'</p>'+(op.recoveryRequired?'<p class="error">Recovery requires review. Open the workflow before taking further action.</p>':'')+'<a href="'+escape(op.url)+'">Open workflow →</a>';
    if(existingLog) content.append(existingLog);
    const actions=$('operation-actions');
    const fingerprint=JSON.stringify([op.id,op.actions]);
    if(actions.dataset.fingerprint!==fingerprint) {
      actions.replaceChildren();actions.dataset.fingerprint=fingerprint;
      for(const action of op.actions) {const button=document.createElement('button');button.type='button';button.textContent={cancel:'Cancel run',retry:'Retry',clear_failed:'Clear failed record'}[action];if(action==='cancel')button.className='ui-danger';button.addEventListener('click',()=>perform(op,action));actions.append(button);}
      const log=document.createElement('button');log.type='button';log.textContent='Show available log';log.addEventListener('click',()=>showDetailLog(op));actions.append(log);
      if(op.logDownloadUrl){const link=document.createElement('a');link.className='btn';link.textContent='Download failure log';link.href=op.logDownloadUrl;actions.append(link);}
    }
  }
  function open(id,trigger) {const op=snapshot.operations.find(item=>item.id===id);if(!op)return;if(selected!==id)$('operation-detail-log')?.remove();selected=id;$('operation-action-message').textContent='';detail(op);ZfsasUI.open($('operation-detail'),trigger);}
  async function perform(op,action) {
    if(busy)return;
    if(action==='cancel' && !window.confirm(op.type==='batch'?'Cancel this batch and its pending operations? Completed results will remain available.':'Cancel this whole run? Its schedule will remain paused until Resume.'))return;
    if(action==='clear_failed' && op.recoveryRequired && !window.confirm('Clear this recovery record after reviewing the preserved snapshots? Clearing releases its cleanup protection; it does not verify or remove those snapshots.'))return;
    busy=true; $('operation-actions').querySelectorAll('button').forEach(button=>button.disabled=true);
    try {
      const coordinator=op.type==='auto'||op.type==='batch';
      const data=await ZfsasRequests.request('operation-action',base+(coordinator?'coordinator-action.php':'send-queue-action.php'),coordinator?{action,run_id:op.nativeId}:{action,job_id:op.nativeId});
      $('operation-action-message').textContent=action==='cancel'?'Cancellation saved. Waiting for verified worker shutdown.':data.message||'Request accepted.';
    }catch(error){$('operation-action-message').textContent=error.message;}
    finally{busy=false;$('operation-actions').dataset.fingerprint='';poll.refresh();}
  }
  async function resumeSchedule(schedule,button) {
    button.disabled=true;
    try {await ZfsasRequests.request('resume',base+(schedule.type==='auto'?'coordinator-action.php':'send-queue-action.php'),{action:'resume',job_id:schedule.id});poll.refresh();}
    catch(error){ZfsasUI.notice(error.message,true);button.disabled=false;}
  }
  async function showDetailLog(op) {
    let output=$('operation-detail-log');if(!output){output=document.createElement('pre');output.id='operation-detail-log';$('operation-body').append(output);}
    output.textContent='Loading shared log…';
    try{const data=await ZfsasRequests.request('detail-log',base+'workspace-log.php?type='+encodeURIComponent(op.logType));if(selected===op.id && $('operation-detail').open){if(!output.isConnected)$('operation-body').append(output);output.textContent=data.content||'No log is available for this boot.';}}
    catch(error){output.textContent=error.message;}
  }
  const poll=ZfsasRequests.poll('workspace-summary',()=>ZfsasRequests.request('workspace-summary',base+'workspace-summary.php'),render);
  ['activity-type','activity-state'].forEach(id=>$(id)?.addEventListener('change',()=>{rows();const url=new URL(location.href);url.searchParams.set(id==='activity-type'?'type':'state',$(id).value);history.replaceState(null,'',url);}));
  if($('activity-type')) {const params=new URLSearchParams(location.search);$('activity-type').value=params.get('type')||'';$('activity-state').value=params.get('state')||'';}
  $('activity-refresh')?.addEventListener('click',()=>poll.refresh());
  const logs=$('activity-logs');
  function startLogs(){logPoll?.stop();logPoll=null;if(!logs?.open)return;logPoll=ZfsasRequests.poll('activity-log',()=>ZfsasRequests.request('activity-log',base+'workspace-log.php?type='+encodeURIComponent($('activity-log-type').value)),data=>{const node=$('activity-log-output'), bottom=node.scrollHeight-node.scrollTop-node.clientHeight<20;node.textContent=data.content||'No log is available for this boot.';if(bottom)node.scrollTop=node.scrollHeight;return snapshot?.operations.some(active);});}
  logs?.addEventListener('toggle',startLogs);$('activity-log-type')?.addEventListener('change',startLogs);$('activity-log-refresh')?.addEventListener('click',()=>logPoll?.refresh());
})();
