// Run against tests/fixtures/admin-layout-router.php only; never point at a live shop.
// PLAYWRIGHT_MODULE and CHROMIUM_PATH select an existing local browser installation.
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright-core');
const base = 'http://127.0.0.1:18776';
const phase = process.env.QA_PHASE || 'after';
assert.match(phase, /^(before|after|confirm)$/);
const output = `.tools/admin-compact/${phase}`;
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true, args: ['--no-sandbox'] });
const results = [];
const sizes = [{ width: 1440, height: 900 }, { width: 1366, height: 768 }, { width: 390, height: 844 }];
async function capture(page, name, size) {
  const metrics = await page.evaluate(() => {
    const visible = el => el.getBoundingClientRect().height > 0 && getComputedStyle(el).visibility !== 'hidden';
    const rect = selector => {
      const el = [...document.querySelectorAll(selector)].find(visible);
      if (!el) return null;
      const r = el.getBoundingClientRect(), s = getComputedStyle(el);
      return { x: r.x, y: r.y, width: r.width, height: r.height, padding: s.padding, gap: s.gap, font: s.fontSize, lineHeight: s.lineHeight };
    };
    const rows = [...document.querySelectorAll('.fi-ta-row')].filter(visible);
    return {
      viewport: innerWidth, scroll: document.documentElement.scrollWidth, height: innerHeight,
      rowsVisible: rows.filter(el => { const r = el.getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight; }).length,
      row: rect('.fi-ta-row'), table: rect('.fi-ta-table'), toolbar: rect('.fi-ta-header-toolbar'),
      topbar: rect('.fi-topbar'), heading: rect('.fi-header-heading'), sidebarRow: rect('.fi-sidebar-item-btn'),
      sectionHeader: rect('.fi-section-header'), section: rect('.fi-section-content'),
      schema: rect('.fi-section-content.fi-sc-has-gap'), input: rect('input.fi-input'),
      button: rect('.fi-ta-actions .fi-btn, .fi-header-actions .fi-btn, .fi-form-actions .fi-btn'),
      dialog: rect('.fi-modal-open .fi-modal-window'),
      dropdown: rect('.fi-dropdown-panel'),
      active: document.activeElement?.textContent?.trim().slice(0, 60),
    };
  });
  assert.ok(metrics.scroll <= size.width + 1, `${name}: page overflow ${JSON.stringify(metrics)}`);
  if (metrics.dialog) {
    assert.ok(metrics.dialog.x >= 0 && metrics.dialog.x + metrics.dialog.width <= size.width + 1, `${name}: dialog overflow`);
  }
  if (metrics.dropdown) assert.ok(metrics.dropdown.x >= -1 && metrics.dropdown.x + metrics.dropdown.width <= size.width + 1, `${name}: dropdown overflow`);
  if (phase !== 'before') {
    if (['orders', 'inventory'].includes(name) && size.width >= 1280) {
      assert.ok(metrics.row.height <= 64, `${name}: nested padding regression`);
      assert.ok(metrics.table.width <= size.width - metrics.table.x - 20, `${name}: desktop actions overflow`);
    }
    if (name === 'settings-mail') {
      assert.equal(metrics.schema.gap, '12px 16px', 'Field rhythm');
      if (size.width < 640) assert.ok(metrics.input.height >= 44, 'Mobile form target');
    }
  }
  await page.screenshot({ path: `${output}/${name}-${size.width}.png` });
  results.push({ name, ...size, metrics });
  await writeFile(`${output}/results.json`, JSON.stringify(results, null, 2));
}
try {
  for (const size of sizes) {
    const page = await browser.newPage({ viewport: size, reducedMotion: 'reduce' });
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('response', r => { if (r.status() >= 400) errors.push(`${r.status()} ${new URL(r.url()).pathname}`); });
    const go = async route => {
      await page.goto(`${base}/evansadmin${route}`, { waitUntil: 'networkidle' });
      await page.waitForFunction(() => !!window.Livewire);
    };
    await go('');
    assert.ok(page.url().endsWith('/orders'), 'Dashboard must remain the orders workbench');
    await capture(page, 'orders', size);
    await page.locator('.fi-ta-search-field input').fill('no-synthetic-match');
    await page.getByText('没有匹配的订单', { exact: true }).waitFor();
    await capture(page, 'orders-empty', size);
    await go('/orders');
    const filters = page.locator('.fi-ta-header-toolbar .fi-dropdown-trigger button').filter({ visible: true }).first();
    await filters.click();
    await page.locator('.fi-dropdown-panel:visible').waitFor();
    await capture(page, 'filters', size);
    await page.keyboard.press('Escape');
    await go('/orders/30');
    await capture(page, 'order-detail', size);
    await go('/cards');
    await capture(page, 'inventory', size);
    const row = page.locator('tr').filter({ has: page.locator('input[value="501"]') });
    if (phase !== 'before') {
      await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
      const preview = row.locator('.shop-card-preview');
      await preview.scrollIntoViewIfNeeded();
      assert.ok((await preview.innerText()).startsWith('SYNTHETIC-LONG-501\n  <script>'));
      assert.equal(await row.locator('.shop-card-content script').count(), 0, 'Card markup is text');
      await row.locator('.fi-copyable').click();
      const copied = await page.evaluate(() => navigator.clipboard.readText());
      assert.equal(copied, 'SYNTHETIC-LONG-501\n  <script>not executable</script> & literal content\n' + 'unbroken-credential-'.repeat(20));
      const height = await row.evaluate(el => el.getBoundingClientRect().height);
      assert.ok(height <= 116, 'Four-line preview stays bounded');
      await capture(page, 'inventory-long', size);
    }
    await row.getByRole('button', { name: '删除', exact: true }).click();
    const modal = page.locator('.fi-modal-open');
    await modal.getByText('永久删除 1 条卡密', { exact: true }).waitFor();
    await page.keyboard.press('Tab');
    await modal.getByRole('button', { name: '永久删除', exact: true }).focus();
    await capture(page, 'delete-single', size);
    const focus = await modal.getByRole('button', { name: '永久删除', exact: true }).evaluate(el => ({ focused: document.activeElement === el, shadow: getComputedStyle(el).boxShadow, height: el.getBoundingClientRect().height }));
    assert.ok(focus.focused && focus.shadow !== 'none', 'Destructive confirmation retains focus ring');
    if (size.width < 640) assert.ok(focus.height >= 44, 'Mobile modal target');
    await page.keyboard.press('Tab');
    assert.ok(await page.evaluate(() => !!document.activeElement.closest('.fi-modal-open')), 'Modal traps keyboard focus');
    await modal.getByRole('button', { name: '取消', exact: true }).click();
    for (const id of [501, 502]) await page.locator(`input.fi-ta-record-checkbox[value="${id}"]`).check();
    await page.getByRole('button', { name: '批量操作', exact: true }).filter({ visible: true }).click();
    await capture(page, 'inventory-menu', size);
    await page.getByRole('button', { name: '删除所选卡密', exact: true }).click();
    await modal.getByText('永久删除 2 条卡密', { exact: true }).waitFor();
    await capture(page, 'delete-bulk', size);
    await modal.getByRole('button', { name: '取消', exact: true }).click();
    await go('/goods/7/edit');
    await capture(page, 'product', size);
    await page.getByRole('textbox', { name: '批量优惠', exact: true }).fill('invalid');
    await page.getByRole('button', { name: '保存', exact: true }).click();
    await page.locator('.fi-fo-field-wrp-error-message').first().waitFor();
    await page.getByRole('textbox', { name: '批量优惠', exact: true }).scrollIntoViewIfNeeded();
    await capture(page, 'product-validation', size);
    await page.getByRole('button', { name: '保存', exact: true }).scrollIntoViewIfNeeded();
    await page.getByRole('button', { name: '保存', exact: true }).focus();
    await capture(page, 'product-save', size);
    await go('/settings');
    await capture(page, 'settings', size);
    await page.getByRole('tab', { name: '邮件', exact: true }).click();
    await capture(page, 'settings-mail', size);
    await page.getByRole('button', { name: '保存设置', exact: true }).scrollIntoViewIfNeeded();
    await page.getByRole('button', { name: '保存设置', exact: true }).focus();
    assert.ok(await page.getByRole('button', { name: '保存设置', exact: true }).evaluate(el => document.activeElement === el), 'Settings save remains reachable');
    await page.evaluate(() => window.scrollTo(0, 0));
    if (size.width < 640) {
      await page.locator('.fi-topbar-open-sidebar-btn').click();
      await page.locator('.fi-sidebar-item-btn').first().waitFor();
      await capture(page, 'navigation', size);
      const height = await page.locator('.fi-sidebar-item-btn').first().evaluate(el => el.getBoundingClientRect().height);
      assert.ok(height >= 44, 'Mobile navigation target');
    }
    assert.deepEqual(errors, [], `Browser errors at ${size.width}`);
    await page.close();
  }
  if (phase !== 'before') {
    // Intermediate breakpoints, narrow screen, 200% effective viewport and touch laptop.
    const boundaries = [];
    for (const width of [320, 640, 768, 1024, 1280]) {
      const page = await browser.newPage({ viewport: { width, height: 900 }, hasTouch: true, reducedMotion: 'reduce' });
      for (const route of ['/orders', '/cards', '/goods/7/edit', '/settings']) {
        await page.goto(`${base}/evansadmin${route}`, { waitUntil: 'networkidle' });
        const geometry = await page.evaluate(() => ({
          scroll: document.documentElement.scrollWidth,
          coarse: matchMedia('(pointer: coarse)').matches,
          buttons: [...document.querySelectorAll('.fi-btn')].filter(e => e.getBoundingClientRect().height > 0).map(e => e.getBoundingClientRect().height),
        }));
        assert.ok(geometry.scroll <= width + 1, `Breakpoint overflow ${route} ${width}`);
        assert.ok(geometry.coarse && geometry.buttons.every(h => h >= 44), `Touch targets ${route} ${width}`);
        boundaries.push({ width, route, ...geometry });
      }
      await page.close();
    }
    await writeFile(`${output}/boundaries.json`, JSON.stringify(boundaries, null, 2));
  }
  console.log(JSON.stringify({ phase, captures: results.length, browserErrors: [], results: `${output}/results.json` }));
} finally { await browser.close(); }
