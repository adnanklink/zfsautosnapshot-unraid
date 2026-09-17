(() => {
  'use strict';
  const $=id=>document.getElementById(id), body=$('zfsas_send_jobs_body'), list=$('replication-job-list'), edit=$('edit-job-dialog'), add=$('new-job-dialog');
  let editing=null, original=[], committed=false, summary=null, placeholder=null, editIndex=0;
  const records=()=>[...body.querySelectorAll('tr'),...(editing?[editing]:[])];
  function render(force=false) {
    if(!force && list.contains(document.activeElement))return;
    list.replaceChildren();
    if(!records().length){list.innerHTML='<div class="ui-empty"><strong>No replication jobs yet</strong>Add a source and destination to keep another copy of your datasets.</div>';return;}
    const table=document.createElement('table');table.innerHTML='<thead><tr><th>Source → destination</th><th>Schedule</th><th>Status</th><th></th></tr></thead><tbody></tbody>';
    for(const row of records()) {
      const value=name=>row.querySelector('[name^="job_'+name+'["]')?.value || '';
      const id=value('id'), schedule=summary?.schedules.find(item=>item.id===id);
      const item=document.createElement('tr');
      const path=document.createElement('td'); const code=document.createElement('code');code.textContent=value('source')+' → '+value('destination');path.append(code);
      const when=document.createElement('td'); const freq=row.querySelector('[name^="job_frequency["]');when.textContent=freq?.selectedOptions[0]?.textContent || 'Configure schedule';
      const next=document.createElement('small');next.className='ui-operation-kind';next.textContent=schedule?.preview.nextScheduledText || (id?'Next occurrence unavailable':'Starts after Save');when.append(next);
      const state=document.createElement('td');const badge=document.createElement('span');badge.className='ui-badge';badge.textContent=schedule?.paused?'Paused':id?'Configured':'Unsaved';state.append(badge);
      if(schedule?.paused){const resume=document.createElement('button');resume.type='button';resume.textContent='Resume';resume.addEventListener('click',async()=>{resume.disabled=true;try{await ZfsasRequests.request('replication-resume','/plugins/zfs.autosnapshot/php/send-queue-action.php',{action:'resume',job_id:id});status.refresh();}catch(error){ZfsasUI.notice(error.message,true);resume.disabled=false;}});state.append(resume);}
      const actions=document.createElement('td');const button=document.createElement('button');button.type='button';button.textContent='Edit';button.addEventListener('click',()=>open(row,button));actions.append(button);
      item.append(path,when,state,actions);table.tBodies[0].append(item);
    }
    const wrap=document.createElement('div');wrap.className='table-wrap';wrap.append(table);list.append(wrap);
  }
  function open(row,trigger) {
    if(edit.open)return;
    editIndex=records().indexOf(row);placeholder=document.createComment('job position');row.before(placeholder);
    editing=row;committed=false;original=[...row.querySelectorAll('input,select')].map(input=>[input,input.value,input.checked]);
    const headings=[...$('zfsas_send_jobs_table').querySelectorAll('thead th')].map(th=>th.textContent);
    [...row.cells].forEach((cell,index)=>{if(!cell.querySelector('.ui-editor-label')){const label=document.createElement('span');label.className='ui-editor-label';label.textContent=headings[index];cell.prepend(label);cell.querySelectorAll('input,select').forEach(input=>{if(input.type!=='hidden'&&!input.hasAttribute('aria-label'))input.setAttribute('aria-label',headings[index]);});}});
    $('job-editor-body').append(row);ZfsasUI.open(edit,trigger);
  }
  $('open-new-job').addEventListener('click',event=>ZfsasUI.open(add,event.currentTarget));
  $('cancel-job-edit').addEventListener('click',()=>edit.close());
  $('finish-job-edit').addEventListener('click',()=>{for(const input of editing.querySelectorAll('input,select'))if(!input.reportValidity())return;committed=true;edit.close();});
  edit.addEventListener('close',()=>{
    if(!editing)return;
    if(!committed)original.forEach(([input,value,checked])=>{input.value=value;input.checked=checked;});
    if(editing.isConnected)placeholder.before(editing);
    placeholder.remove();
    editing=null;render(true);
    const buttons=list.querySelectorAll('td:last-child button');(buttons[Math.min(editIndex,buttons.length-1)] || $('open-new-job')).focus();
    $('zfsas_send_form').dispatchEvent(new Event('change',{bubbles:true}));
  });
  edit.addEventListener('click',event=>{if(event.target.closest('.zfsas-send-remove-row')){committed=true;edit.close();}});
  $('zfsas_send_form').addEventListener('invalid',event=>{const row=event.target.closest('#zfsas_send_jobs_body tr');if(row)open(row);},true);
  const observer=new MutationObserver(()=>{if(edit.open)return;render();});observer.observe(body,{childList:true});
  $('zfsas_add_send_job').addEventListener('click',()=>{if(!document.getElementById('new_job_source').value && !document.getElementById('new_job_destination').value){add.close();render();}});
  $('zfsas_send_form').addEventListener('change',()=>{if(!edit.open)render();});
  const status=ZfsasRequests.poll('replication-status',()=>ZfsasRequests.request('replication-status','/plugins/zfs.autosnapshot/php/workspace-summary.php'),data=>{summary=data;if(!edit.open&&!add.open&&!list.contains(document.activeElement))render();return false;});
  render();
})();
