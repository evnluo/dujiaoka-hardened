// Synthetic localhost harness only; no production data or login credentials.
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright-core');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox'] });
const output = '.tools/order-admin-visual';
await mkdir(output, { recursive: true });
const results = [];
try {
  for (const width of [1440, 390]) {
    const context = await browser.newContext({ viewport: { width, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto('http://127.0.0.1:18776/evansadmin/orders/1', { waitUntil: 'networkidle' });
    const fields = page.locator('.shop-order-content');
    assert.equal(await fields.count(), 2);
    await fields.first().scrollIntoViewIfNeeded();
    const content = await fields.first().innerText();
    assert.ok(content.includes('\n  历史交付：SYNTHETIC-DELIVERY-1\n'));
    assert.ok(content.includes('<script>window.syntheticExecuted = true</script>'));
    assert.equal(await page.evaluate(() => window.syntheticExecuted), undefined);
    await fields.nth(1).click();
    assert.equal(await page.evaluate(() => navigator.clipboard.readText()), 'SYNTHETIC-LOOKUP-1');
    await fields.first().click();
    assert.equal(await page.evaluate(() => navigator.clipboard.readText()), content);
    const metrics = await page.evaluate(() => ({ viewport: innerWidth, scroll: document.documentElement.scrollWidth,
      whitespace: getComputedStyle(document.querySelector('.shop-order-content')).whiteSpace,
      userSelect: getComputedStyle(document.querySelector('.shop-order-content')).userSelect }));
    assert.ok(metrics.scroll <= width + 1);
    assert.equal(metrics.whitespace, 'pre-wrap');
    assert.equal(metrics.userSelect, 'text');
    await page.screenshot({ path: `${output}/details-${width}.png` });
    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: '下载完整详情', exact: true }).click();
    assert.equal((await download).suggestedFilename(), 'order-QA2026101800000001.txt');
    await page.getByRole('button', { name: '归档订单', exact: true }).click();
    await page.locator('.fi-modal-open .fi-modal-window').waitFor({ state: 'visible' });
    assert.equal(await page.locator('.fi-modal-open input[type=password]').count(), 0);
    await page.screenshot({ path: `${output}/archive-${width}.png` });
    await page.goto('http://127.0.0.1:18776/evansadmin/orders', { waitUntil: 'networkidle' });
    assert.equal(await page.locator('.shop-order-content').count(), 0);
    await page.getByRole('button', { name: '详情', exact: true }).first().click();
    const modal = page.locator('.fi-modal-open');
    await modal.locator('.shop-order-content').first().waitFor({ state: 'visible' });
    await modal.locator('.shop-order-content').last().scrollIntoViewIfNeeded();
    assert.equal(await modal.locator('.shop-order-content').count(), 2);
    assert.equal(await modal.locator('input[type=password]').count(), 0);
    assert.equal(await page.evaluate(() => window.syntheticExecuted), undefined);
    const bounds = await modal.locator('.fi-modal-window').boundingBox();
    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width + 1);
    await page.screenshot({ path: `${output}/modal-${width}.png` });
    assert.deepEqual(errors, []);
    results.push({ width, metrics, modalBounds: bounds, clipboardExact: true, directDownload: true, escapedScript: true, errors });
    await context.close();
  }
  await writeFile(`${output}/results.json`, JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results));
} finally { await browser.close(); }
