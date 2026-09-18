const {chromium}=require('/opt/zfsas-tests/node_modules/playwright-core');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const plugin=path.resolve(__dirname,'../../source/usr/local/emhttp/plugins/zfs.snapsync');
const summary={ok:true,generatedAt:1700000000,timezone:'UTC',sources:{configuration:{available:true},coordinator:{available:true}},operations:[{id:'coordinator:batch',nativeId:'batch-run',type:'batch',title:'Snapshot batch',state:'running',createdAt:1699999999,actions:['cancel'],url:'?section=snapshots',logType:'batch'},{id:'coordinator:example',nativeId:'example',type:'auto',title:'Automatic snapshots',state:'running',createdAt:1700000000,actions:['cancel'],url:'?section=snapshots&tab=automation',logType:'auto'},{id:'replication:recovery',nativeId:'recovery',type:'replication',title:'Interrupted snapshot creation',state:'failed',createdAt:1700000000,recoveryRequired:true,actions:['clear_failed'],url:'?section=replication',logType:'replication'}],schedules:[],pausedSchedules:[]};
summary.operations.push({id:'coordinator:native',nativeId:'native-run',type:'replication',coordinator:true,manual:true,title:'Native replication',state:'failed',createdAt:1700000000,recoveryRequired:true,actions:['retry'],url:'?section=activity'});
(async()=>{const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});try{
 for(const query of ['section=overview','section=snapshots','section=snapshots&tab=automation','section=replication','section=activity','section=tools','section=tools&tab=migrator','section=help']){
 const html=execFileSync('php',['-r','parse_str($argv[1],$_GET); require $argv[2];',query,plugin+'/php/workspace.php'],{encoding:'utf8'});
 const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];let summaryRequests=0,mutationRequests=0,discoveryRequests=0;page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://workspace.test/**',async route=>{const url=new URL(route.request().url());
 if(url.pathname.endsWith('.png'))return route.fulfill({contentType:'image/png',body:fs.readFileSync(plugin+url.pathname.replace('/plugins/zfs.snapsync',''))});
 if(/\.(js|css)$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(plugin+url.pathname.replace('/plugins/zfs.snapsync',''),'utf8')});
 if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
 let data={ok:true,probe:true,spec:{kind:'interval',seconds:21600},status:{},datasets:[{dataset:'tank/data',mountpoint:'/mnt/tank/data',pool:'tank',sendDestination:false}],snapshots:[],jobs:[],pausedSchedules:[],pendingDeleteCount:0,content:'Test log',logTail:[],docker:{runningContainers:[]}};
 if(url.pathname.endsWith('dataset-inventory.php')){
   discoveryRequests++;
   if(discoveryRequests===1){await new Promise(resolve=>setTimeout(resolve,300));data={ok:false,error:'Test discovery failure'};}
   if(discoveryRequests===3) await new Promise(resolve=>setTimeout(resolve,1200));
 }
 if(url.pathname.endsWith('save-interface-settings.php')) data={ok:true,enabled:true,revision:'saved-revision'};
 if(url.pathname.endsWith('send-queue-action.php'))mutationRequests++;
 if(url.pathname.endsWith('workspace-summary.php')){summaryRequests++;data=summary;}
 if(url.pathname.endsWith('migrate-datasets-status.php') && url.searchParams.get('dataset'))data.preview={folders:[]};
 return route.fulfill({contentType:'text/plain',body:'ZFSAS_JSON_BEGIN'+JSON.stringify(data)+'ZFSAS_JSON_END'});
 });
 await page.addInitScript(()=>{const original=window.setTimeout;window.setTimeout=(fn,ms,...args)=>original(fn,ms===20000?800:ms,...args);});
 await page.goto('http://workspace.test/?'+query);
 if(query==='section=snapshots' || query.endsWith('tab=automation') || query==='section=replication'){
   const browse=query==='section=snapshots',status=page.locator(browse?'#notice':'#dataset-discovery-status');
   assert.match(await status.textContent(),/Discovering/);
   await page.waitForTimeout(400);assert.match(await status.textContent(),/discovery failed/i);
   const retry=page.getByRole('button',{name:browse?'Refresh datasets':'Retry dataset discovery',exact:true});
   assert(await retry.isVisible());await retry.click();
   await page.waitForFunction(()=>document.querySelector('#notice')?.textContent.includes('Choose a dataset') || document.querySelector('#dataset-discovery-status')?.textContent.includes('datasets discovered'));
   if(browse){
     assert.equal(await page.locator('#dataset option').count(),2);
     const bounds=await page.locator('#dataset').boundingBox(),button=await retry.boundingBox();
     assert(Math.abs(bounds.y+bounds.height-button.y-button.height)<3,'Refresh button is misaligned');
     await retry.click();await page.waitForTimeout(900);
     assert.match(await status.textContent(),/timed out/);assert(await retry.isEnabled());
     await retry.click();await page.waitForTimeout(100);assert.match(await status.textContent(),/Choose a dataset/);
   }else{
     assert.equal(await page.locator('[data-config-tools] button:visible').count(),1);
     assert(await page.locator('[data-config-tools]').evaluate(el=>el===el.parentElement.lastElementChild));
   }
 }
 await page.waitForTimeout(500);
 assert(await page.locator('.ui-brand-mark').evaluate(img=>img.complete && img.naturalWidth>0),'Brand icon did not load');
 assert.equal(await page.locator('.zfsas-workspace').count(),1,query);assert.equal(await page.locator('h1').count(),1,query);assert.equal(await page.locator('iframe').count(),0);
 if(query==='section=activity' || query.endsWith('tab=automation')){
 const groups=query==='section=activity' ? [['#activity-state','#activity-refresh']] : [['#dataset_pool_filter','#dataset_select_visible','#dataset_clear_visible','#dataset_select_all','#dataset_clear_all'],['#manual_run','#zfsas_save_btn'],['[data-cancel]','[data-resume]']];
 for(const selectors of groups){const boxes=await Promise.all(selectors.map(selector=>page.locator(selector).boundingBox()));for(const box of boxes.slice(1)){assert(Math.abs((boxes[0].y+boxes[0].height)-(box.y+box.height))<3,'Misaligned controls: '+selectors.join(', '));}}
 }
 if(query==='section=overview'){const button=page.getByRole('button',{name:'Details',exact:true}).first();await button.click();await page.getByRole('button',{name:'Show available log'}).click();await page.waitForTimeout(2300);assert.match(await page.locator('#operation-detail-log').textContent(),/Test log/);await page.keyboard.press('Escape');await page.waitForFunction(()=>!document.getElementById('operation-detail').open);assert(await button.evaluate(el=>el===document.activeElement));
 await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));});const previous=summaryRequests;await page.waitForTimeout(2200);assert.equal(summaryRequests,previous,'Hidden Overview polled');await page.evaluate(()=>{delete document.hidden;document.dispatchEvent(new Event('visibilitychange'));});}
 if(query==='section=activity'){
 await page.locator('[data-operation="coordinator:native"] button').click();
 page.once('dialog',async dialog=>{assert.match(dialog.message(),/validated before resuming/);await dialog.accept();});
 const [nativeRequest]=await Promise.all([page.waitForRequest(request=>request.url().endsWith('coordinator-action.php')),page.getByRole('button',{name:'Retry',exact:true}).click()]);
 assert.match(nativeRequest.postData(),/action=retry/);assert.match(nativeRequest.postData(),/run_id=native-run/);
 await page.keyboard.press('Escape');
 await page.locator('[data-operation="replication:recovery"] button').click();
 assert.match(await page.locator('#operation-body').textContent(),/Recovery requires review/);
 assert.equal(await page.getByRole('button',{name:'Retry',exact:true}).count(),0);
 page.once('dialog',async dialog=>{assert.match(dialog.message(),/releases its cleanup protection/);await dialog.dismiss();});
 await page.getByRole('button',{name:'Clear failed record',exact:true}).click();
 assert.equal(mutationRequests,0,'Dismissed review warning submitted a mutation');
 page.once('dialog',dialog=>dialog.accept());
 const [request]=await Promise.all([page.waitForRequest(request=>request.url().endsWith('send-queue-action.php')),page.getByRole('button',{name:'Clear failed record',exact:true}).click()]);
 assert.match(request.postData(),/action=clear_failed/);assert.match(request.postData(),/job_id=recovery/);
 await page.keyboard.press('Escape');
 await page.locator('[data-operation="coordinator:batch"] button').click();
 page.once('dialog',async dialog=>{assert.match(dialog.message(),/Cancel this batch/);assert.doesNotMatch(dialog.message(),/paused/);await dialog.accept();});
 const [batchRequest]=await Promise.all([page.waitForRequest(request=>request.url().endsWith('coordinator-action.php')),page.getByRole('button',{name:'Cancel run',exact:true}).click()]);
 assert.match(batchRequest.postData(),/action=cancel/);assert.match(batchRequest.postData(),/run_id=batch-run/);
 await page.keyboard.press('Escape');
 }
 if(query==='section=replication'){
 await page.locator('#open-new-job').click();assert(await page.locator('#new-job-dialog').evaluate(el=>el.open));
 await page.locator('#new_job_source').evaluate(el=>el.add(new Option('tank/anchor-test','tank/anchor-test')));
 await page.selectOption('#new_job_source','tank/anchor-test');await page.fill('#new_job_destination','backup/anchor-test');
 await page.locator('#zfsas_add_send_job').click();
 await page.getByRole('button',{name:'Edit',exact:true}).last().click();
 const policy=page.locator('#job-editor-body select[name^="job_cleanup_policy["]');
 assert.equal(await policy.inputValue(),'retention_only','New job inherited cleanup authority');
 await policy.selectOption('older_anchors');await page.locator('#cancel-job-edit').click();
 await page.getByRole('button',{name:'Edit',exact:true}).last().click();
 assert.equal(await policy.inputValue(),'retention_only','Cancel did not restore cleanup policy');
 await policy.selectOption('older_anchors');await page.locator('#finish-job-edit').click();
 assert.equal(await page.locator('#zfsas_send_jobs_body select[name^="job_cleanup_policy["]').last().inputValue(),'older_anchors','Editor lost explicit opt-in');
 }
 if(query==='section=tools'){
 assert(!(await page.locator('[name=show_tab]').isChecked()));
 await page.locator('[name=show_tab]').check();
 const [request]=await Promise.all([page.waitForRequest(r=>r.url().endsWith('save-interface-settings.php')),page.getByRole('button',{name:'Save interface preference'}).click()]);
 assert.match(request.postData(),/show_tab=1/);
 await page.waitForFunction(()=>document.getElementById('interface-status').textContent.includes('Saved.'));
 assert(await page.locator('#interface-reload').isVisible());
 }
 if(query.endsWith('tab=migrator')){await page.selectOption('#migrate_dataset','tank/data');assert(await page.locator('#migrate_start').isDisabled());await page.locator('#migrate_preview').click();await page.waitForTimeout(100);await page.locator('#migrate-review-confirm').check();assert(await page.locator('#migrate_start').isEnabled());}
 fs.mkdirSync('/tmp/zfsas-ui-screenshots',{recursive:true});const name=query.replaceAll(/[=&]/g,'-');await page.screenshot({path:'/tmp/zfsas-ui-screenshots/'+name+'-light.png',fullPage:query!=='section=snapshots&tab=automation'});
 await page.evaluate(()=>document.body.style.backgroundColor='rgb(25,25,25)');await page.waitForTimeout(50);await page.screenshot({path:'/tmp/zfsas-ui-screenshots/'+name+'-dark.png',fullPage:true});
 await page.setViewportSize({width:900,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Tablet overflow: '+query);
 await page.setViewportSize({width:390,height:844});await page.locator('.ui-menu-toggle').click();assert.equal(await page.locator('.ui-menu-toggle').getAttribute('aria-expanded'),'true');await page.locator('.ui-menu-toggle').click();await page.screenshot({path:'/tmp/zfsas-ui-screenshots/'+name+'-mobile.png',fullPage:true});
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Horizontal overflow: '+query);
 assert.deepEqual(errors,[],query);await page.close();console.log('PASS '+query);
 }
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exit(1)});
