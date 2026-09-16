/* Run inside the disposable test image (Playwright Core + Chromium). */
const {chromium} = require(process.env.PLAYWRIGHT_CORE || '/opt/zfsas-tests/node_modules/playwright-core');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const plugin = path.resolve(__dirname, '../../source/usr/local/emhttp/plugins/zfs.autosnapshot');
(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM || '/usr/bin/chromium', args: ['--no-sandbox'], headless: true});
  try {
    const page = await browser.newPage({viewport: {width: 1400, height: 900}});
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    let addNew = false, captures = [], datasetRequests = 0;
    const row = (i, dataset = 'tank/data') => ({dataset, snapshot: dataset + '@auto-' + String(i).padStart(5, '0'), snapshotName: 'auto-' + String(i).padStart(5, '0'), guid: String(i + 1), identity: dataset + '@auto-' + String(i).padStart(5, '0') + '#' + (i + 1), createdEpoch: i, createdText: String(i), usedBytes: i % 2, writtenBytes: i % 3, usedText: i % 2 + ' B', writtenText: i % 3 + ' B', metadataComplete: true, pendingAction: i === 2 ? 'delete' : '', eligibility: {delete: '', hold: '', release: 'No plugin hold', send: '', rollback: ''}});
    const all = () => Array.from({length: 10000 + Number(addNew)}, (_, i) => row(i));
    await page.route('http://zfsas.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('.js')) return route.fulfill({contentType: 'application/javascript', body: fs.readFileSync(plugin + '/js/' + path.basename(url.pathname), 'utf8')});
      if (url.pathname === '/') return route.fulfill({contentType: 'text/html', body: fs.readFileSync(plugin + '/php/snapshot-manager-page.php', 'utf8').replace(/<\?php[\s\S]*?\?>/g, '')});
      let payload;
      if (url.pathname.endsWith('snapshot-manager-list.php')) payload = {ok: true, datasets: [{dataset: 'tank/data', pool: 'tank', snapshotCount: 10000}, {dataset: 'tank/other', pool: 'tank', snapshotCount: 10000}]};
      else if (url.pathname.endsWith('snapshot-manager-dataset.php')) {
        datasetRequests++;
        const dataset = url.searchParams.get('dataset'), search = url.searchParams.get('search') || '';
        let matching = all().map(r => ({...r, dataset, snapshot: r.snapshot.replace('tank/data', dataset), identity: r.identity.replace('tank/data', dataset)})).filter(r => r.snapshotName.includes(search));
        if (url.searchParams.get('used_max') === '0') matching = matching.filter(r => !r.usedBytes);
        if (url.searchParams.get('direction') !== 'asc') matching.reverse();
        const size = Number(url.searchParams.get('page_size') || 100), current = Number(url.searchParams.get('page') || 1);
        payload = {ok: true, dataset, total: all().length, matching: matching.length, page: current, pages: Math.max(1, Math.ceil(matching.length / size)), snapshots: matching.slice((current - 1) * size, current * size)};
        if (dataset === 'tank/other') await new Promise(r => setTimeout(r, 250));
      } else if (url.pathname.endsWith('snapshot-manager-batch.php')) {
        const post = new URLSearchParams(route.request().postData() || ''), action = post.get('action') || url.searchParams.get('action');
        if (action === 'matching') payload = {ok: true, dataset: 'tank/data', items: all()};
        else if (action === 'capture') {
          const items = JSON.parse(post.get('items')); assert(items.length <= 500); captures.push(...items);
          payload = {ok: true, token: 'a'.repeat(32), dataset: 'tank/data', action: post.get('operation'), state: post.get('seal') === '1' ? 'review' : 'draft', selected: captures.length, eligible: captures.length, counts: {queued: captures.length, completed: 0, skipped: 0, failed: 0}, expires: Math.floor(Date.now()/1000)+300, page: 1, pages: Math.ceil(captures.length/100), items: captures.slice(0, 100).map(r => ({...r, candidate: true, state: 'queued', reason: 'Selected snapshot'}))};
        } else payload = {ok: false, error: 'Unexpected test action ' + action};
      } else throw Error('Unexpected URL ' + url);
      await route.fulfill({contentType: 'application/json', body: JSON.stringify(payload)});
    });
    await page.goto('http://zfsas.test/');
    await page.selectOption('#dataset', 'tank/data');
    await page.waitForFunction(() => document.querySelector('#counts').textContent.startsWith('10000 matching'));
    assert.equal(await page.locator('#snapshots tr').count(), 100);
    await page.locator('[data-sort="name"]').click(); // first desc
    await page.locator('[data-sort="name"]').click(); // asc
    await page.waitForFunction(() => document.querySelector('#snapshots code').textContent === 'auto-00000');
    await page.locator('[data-select]').nth(0).check();
    await page.locator('[data-select]').nth(4).click({modifiers: ['Shift']});
    assert.equal(await page.locator('#selected-count').textContent(), '4 selected');
    assert(await page.locator('#page-checkbox').evaluate(el => el.indeterminate));
    await page.locator('[data-select]').nth(0).click({modifiers: ['Shift']});
    assert.equal(await page.locator('#selected-count').textContent(), '0 selected');
    await page.locator('#select-page').click();
    assert.equal(await page.locator('#selected-count').textContent(), '99 selected');
    await page.locator('#next').click();
    await page.waitForFunction(() => document.querySelector('#page-text').textContent === 'Page 2 of 100');
    await page.locator('[data-select]').nth(0).check();
    assert.equal(await page.locator('#selected-count').textContent(), '100 selected');
    await page.locator('[data-sort="used"]').click();
    assert.equal(await page.locator('#selected-count').textContent(), '100 selected');
    await page.locator('#select-matching').click();
    await page.waitForFunction(() => document.querySelector('#selected-count').textContent === '9999 selected');
    addNew = true;
    await page.locator('[data-sort="creation"]').click();
    await page.waitForFunction(() => document.querySelector('#counts').textContent.startsWith('10001 matching'));
    assert.equal(await page.locator('#selected-count').textContent(), '9999 selected');
    await page.locator('[data-bulk="hold"]').click();
    await page.waitForFunction(() => !document.querySelector('#review').hidden);
    assert.equal(captures.length, 9999);
    assert(!captures.some(r => r.guid === '10001'));
    await page.locator('[name="search"]').fill('09999');
    await page.waitForFunction(() => document.querySelector('#counts').textContent.startsWith('1 matching'));
    assert.equal(await page.locator('#selected-count').textContent(), '0 selected');
    await page.selectOption('#dataset', 'tank/other');
    await page.selectOption('#dataset', 'tank/data');
    await page.waitForTimeout(350);
    assert.equal(await page.locator('#dataset-title').textContent(), 'tank/data');
    assert((await page.locator('[data-select]').first().getAttribute('data-select')).startsWith('tank/data@'));
    assert.deepEqual(errors, []);
    assert(datasetRequests > 6);
    console.log('PASS: Chromium 10,000 rows, disabled Shift ranges, cross-page and frozen matching selection, 500-item uploads, filters, out-of-order dataset responses');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
