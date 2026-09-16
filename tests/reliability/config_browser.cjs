const {chromium} = require('/opt/zfsas-tests/node_modules/playwright-core');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const plugin = path.resolve(__dirname, '../../source/usr/local/emhttp/plugins/zfs.autosnapshot');
(async () => {
 const browser = await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try {
  for (const kind of ['auto','send']) {
   const page = await browser.newPage(); const errors=[];page.on('pageerror',error=>errors.push(error.message));
   const html = execFileSync('php',[plugin+'/php/'+(kind==='auto'?'settings.php':'send-settings.php')],{encoding:'utf8'});
   await page.route('http://config.test/**', async route=>{
    const url=new URL(route.request().url());
    if(url.pathname.endsWith('.js')) return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(plugin+'/js/'+path.basename(url.pathname),'utf8')});
    if(url.pathname==='/') return route.fulfill({contentType:'text/html',body:html});
    let data={ok:true,jobs:[],pausedSchedules:[],pendingDeleteCount:0,content:'',datasets:[{dataset:'tank/data',pool:'tank',sendDestination:false},{dataset:'tank/dest',pool:'tank',sendDestination:true}]};
    return route.fulfill({contentType:'text/plain',body:'ZFSAS_JSON_BEGIN'+JSON.stringify(data)+'ZFSAS_JSON_END'});
   });
   await page.goto('http://config.test/');
   await page.waitForFunction(()=>document.querySelector('[data-config-tools]')?.dataset.ready==='1');
   const prefix=kind==='auto'?'prefix':'send_snapshot_prefix';
   await page.locator('[name="'+prefix+'"]').fill('unique-'+kind+'-');
   const retention=kind==='auto'?'keep_all_for_days':'send_keep_all_for_days';
   await page.locator('[name="'+retention+'"]').fill('99');
   if(kind==='auto') {
    await page.waitForFunction(()=>document.querySelectorAll('.zfsas-dataset-row').length===2);
    await page.locator('.zfsas-dataset-checkbox').first().check();
    await page.locator('[name="dry_run"]').check();
    await page.selectOption('[name="schedule_mode"]','daily');
   } else {
    await page.waitForFunction(()=>document.querySelector('#new_job_source').options.length===3);
    await page.selectOption('#new_job_source','tank/data');
    await page.locator('[name="send_max_parallel"]').fill('4');
    await page.locator('[name="send_rate_limit"]').fill('20M');
    await page.locator('[name="new_job_time"]').fill('23:17');
    await page.selectOption('[name="new_job_day"]','2');
    await page.selectOption('#new_job_frequency','7d');
    await page.locator('[name="new_job_destination"]').fill('backup/data');
    await page.locator('#zfsas_add_send_job').click();
    await page.waitForSelector('[name="job_time[0]"]');
    assert.equal(await page.locator('[name="job_time[0]"]').inputValue(),'23:17');
    assert.equal(await page.locator('[name="job_day[0]"]').inputValue(),'2');
    await page.selectOption('#new_job_source','tank/data');
   }
   await page.locator('[data-restore-tuning]').click();
   assert.equal(await page.locator('[name="'+retention+'"]').inputValue(),'14');
   assert.equal(await page.locator('[name="'+prefix+'"]').inputValue(),'unique-'+kind+'-');
   assert.match(await page.locator('[data-dirty]').textContent(),/Unsaved/);
   if(kind==='auto') {
    assert(await page.locator('.zfsas-dataset-checkbox').first().isChecked());
    assert(await page.locator('[name="dry_run"]').isChecked());
    assert.equal(await page.locator('[name="schedule_mode"]').inputValue(),'disabled');
   } else {
    assert.equal(await page.locator('#new_job_source').inputValue(),'tank/data');
    assert.equal(await page.locator('[name="send_max_parallel"]').inputValue(),'1');
    assert.equal(await page.locator('[name="send_rate_limit"]').inputValue(),'0');
   }
   const options=JSON.parse(await page.locator('[data-config-tools]').getAttribute('data-config-tools'));
   await page.locator('[name="'+prefix+'"]').fill(options.otherPrefix+'overlap-');
   assert(await page.locator('[name="'+prefix+'"]').evaluate(input=>!input.checkValidity()));
   assert.match(await page.locator('[data-prefix-feedback]').textContent(),/Conflict/);
   assert.deepEqual(errors,[]);
   await page.close();
  }
  console.log('PASS: actual settings pages in Chromium, async discovery, reset preserves choices/prefix/Dry Run, shared tuning defaults, dirty state and inline prefix conflicts');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exit(1);});
