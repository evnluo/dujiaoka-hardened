// Run with PHP_BINARY and PLAYWRIGHT_MODULE pointing to installed local runtimes.
// Uses actual Blade output and public assets, an isolated in-memory catalog, and a loopback-only server.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

const root = resolve(import.meta.dirname, '..');
const out = resolve(process.env.HOME_QA_OUTPUT || '.tools/home-qa');
await mkdir(out, { recursive: true });
const { chromium, webkit } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const html = execFileSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/render-storefront.php'], { cwd: root, encoding: 'utf8' });
await writeFile(resolve(out, 'after.html'), html);
const vendor = new Map();
for (const path of ['projects/tailwind-playcdn/3.4-typography.js', 'lib/toastify/toastify-js.js', 'lib/toastify/toastify.min.css']) {
    const response = await fetch(`https://assets.ohevan.com/${path}`);
    assert(response.ok, `Existing vendor asset unavailable: ${path}`);
    vendor.set(`/__vendor/${path}`, Buffer.from(await response.arrayBuffer()));
}
const types = { '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.jpg': 'image/jpeg', '.png': 'image/png', '.woff2': 'font/woff2', '.ico': 'image/x-icon' };
let delay = 0;
let broken = false;
let brokenFallback = false;
const requests = [];
const report = { engines: [], checks: [], before: null, runs: [], errors: [] };
function check(label, actual, expected = true) {
    assert.deepEqual(actual, expected, label);
    report.checks.push(label);
}
const server = createServer(async (req, res) => {
    try {
        const pathname = new URL(req.url, 'http://localhost').pathname;
        if (pathname === '/' || pathname === '/before') {
            const content = pathname === '/before' ? await readFile(resolve(out, 'before.html'), 'utf8') : html;
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
            res.end(content.replaceAll('http://localhost', '').replaceAll('https://assets.ohevan.com/', '/__vendor/'));
            return;
        }
        const image = pathname.match(/^\/uploads\/fixture-(\d+)\.svg$/);
        if (image) {
            const id = Number(image[1]);
            requests.push({ id, time: Date.now(), path: pathname });
            await new Promise(done => setTimeout(done, delay));
            if (broken && id === 1) { res.writeHead(404); res.end(); return; }
            const color = id % 2 ? '#c5d9f6' : '#f2ce73';
            res.writeHead(200, { 'Content-Type': 'image/svg+xml', 'Cache-Control': 'public, max-age=3600' });
            // Deliberately non-square intrinsic artwork tests that image dimensions cannot shift the square card.
            res.end(`<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600"><rect width="800" height="600" fill="${color}"/><text x="130" y="140" fill="#18181b" font-family="sans-serif" font-size="54">ChatGPT</text><text x="130" y="230" fill="#18181b" font-family="sans-serif" font-size="44">${id % 2 ? 'Plus' : 'Pro'}</text><rect x="130" y="300" width="540" height="220" rx="12" fill="white"/><text x="160" y="370" fill="#52525b" font-family="sans-serif" font-size="28">Synthetic QA image ${id}</text></svg>`);
            return;
        }
        if (brokenFallback && pathname === '/assets/common/images/default.jpg') { res.writeHead(404); res.end(); return; }
        const file = resolve(root, 'public', `.${pathname}`);
        if (!file.startsWith(`${resolve(root, 'public')}/`)) { res.writeHead(403); res.end(); return; }
        const body = vendor.get(pathname) || await readFile(file);
        res.writeHead(200, { 'Content-Type': types[extname(pathname)] || 'application/octet-stream', 'Cache-Control': 'public, max-age=3600' });
        res.end(body);
    } catch { res.writeHead(404); res.end(); }
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const url = `http://127.0.0.1:${server.address().port}`;
const sleep = ms => new Promise(done => setTimeout(done, ms));
const viewport = width => ({ width, height: width > 500 ? 1000 : 844 });
async function ready(page) {
    await page.waitForFunction(() => document.querySelector('[data-shop-categories]')?.hidden === false);
    await page.evaluate(() => document.fonts.ready);
    await page.waitForFunction(() => getComputedStyle(document.querySelector('.shop-products > a')).display === 'flex');
}
async function geometry(page) {
    return page.evaluate(() => ({
        time: performance.now(), viewport: innerWidth, pageWidth: document.documentElement.scrollWidth, scrollY,
        reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
        stripWidth: document.querySelector('.shop-categories').clientWidth,
        stripScrollWidth: document.querySelector('.shop-categories').scrollWidth,
        buttons: [...document.querySelectorAll('[data-category]')].map(b => ({ text: b.textContent, width: b.getBoundingClientRect().width, height: b.getBoundingClientRect().height, nowrap: getComputedStyle(b).whiteSpace, shrink: getComputedStyle(b).flexShrink })),
        images: [...document.querySelectorAll('.shop-product-media img')].map(i => {
            const r = i.getBoundingClientRect(); return { top: r.top + scrollY, width: r.width, height: r.height, opacity: getComputedStyle(i).opacity, transition: getComputedStyle(i).transitionDuration, complete: i.complete, naturalWidth: i.naturalWidth };
        }),
        clippedDetails: [...document.querySelectorAll('.shop-product-details, .shop-product-price')].some(el => el.scrollWidth > el.clientWidth + 1),
    }));
}
async function instrument(page) {
    await page.addInitScript(() => {
        window.imageMutations = [];
        window.shifts = [];
        new MutationObserver(records => records.forEach(record => {
            if (record.target.matches?.('.shop-product-media img')) window.imageMutations.push({ attr: record.attributeName, time: performance.now() });
        })).observe(document, { subtree: true, attributes: true, attributeFilter: ['src', 'style', 'class'] });
        if (PerformanceObserver.supportedEntryTypes.includes('layout-shift')) {
            new PerformanceObserver(list => window.shifts.push(...list.getEntries().map(e => ({ value: e.value, recentInput: e.hadRecentInput, time: e.startTime, sources: e.sources.map(s => ({ tag: s.node?.tagName, className: s.node?.className, previous: s.previousRect.toJSON(), current: s.currentRect.toJSON() })) })))).observe({ type: 'layout-shift', buffered: true });
        }
    });
}
let browser;
try {
    for (const [engine, type] of [['chromium', chromium], ['webkit', webkit]]) {
        try {
            browser = await type.launch({ headless: true, ...(engine === 'chromium' && process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}), ...(engine === 'chromium' ? { args: ['--no-sandbox'] } : {}) });
        } catch (error) {
            if (engine === 'chromium') throw error;
            report.engines.push({ engine, available: false, reason: error.message });
            continue;
        }
        report.engines.push({ engine, available: true, version: browser.version() });
        if (engine === 'chromium' && existsSync(resolve(out, 'before.html'))) {
            const page = await browser.newPage({ viewport: viewport(390) });
            delay = 700;
            await page.goto(`${url}/before`, { waitUntil: 'domcontentloaded' });
            const samples = [];
            for (let i = 0; i < 12; i++) {
                samples.push(await page.locator('#group-all img').first().evaluate(img => ({ time: performance.now(), opacity: getComputedStyle(img).opacity, inline: img.style.opacity, complete: img.complete })));
                await sleep(80);
            }
            report.before = { samples, geometry: await page.locator('.custom-tab').evaluateAll(bs => bs.map(b => ({ text: b.textContent.trim(), width: b.offsetWidth, height: b.offsetHeight, nowrap: getComputedStyle(b).whiteSpace, shrink: getComputedStyle(b).flexShrink }))), images: await page.locator('.tab-content img').count() };
            await page.screenshot({ path: resolve(out, 'before-390.png') });
            check('baseline reproduces opacity manipulation', samples.some(s => Number(s.opacity) < 1 || s.inline !== ''));
            check('baseline duplicates image nodes', report.before.images, 48);
            await page.close();
        }
        delay = 0;
        for (const width of [320, 375, 390, 430, 1440]) {
            const context = await browser.newContext({ viewport: viewport(width), reducedMotion: width === 430 ? 'reduce' : 'no-preference' });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', e => errors.push(e.message));
            page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
            await page.goto(url);
            await ready(page);
            const g = await geometry(page);
            check(`${engine} ${width}: effective reduced-motion state`, g.reducedMotion, width === 430);
            check(`${engine} ${width}: no page overflow`, g.pageWidth <= width);
            check(`${engine} ${width}: readable touch pills`, g.buttons.every(b => b.height >= 44 && b.nowrap === 'nowrap' && b.shrink === '0'));
            check(`${engine} ${width}: square stable image boxes`, g.images.every(i => Math.abs(i.width - i.height) < 1 && i.width > 0));
            check(`${engine} ${width}: no clipped badges/prices`, !g.clippedDetails);
            check(`${engine} ${width}: no image reveal transition`, g.images.every(i => i.opacity === '1' && i.transition === '0s'));
            await page.screenshot({ path: resolve(out, `${engine}-after-${width}.png`) });
            const beforeScroll = await page.evaluate(() => scrollY);
            await page.locator('[data-category="all"]').focus();
            await page.keyboard.press('End');
            check(`${engine} ${width}: End selects final category`, await page.locator('[aria-pressed="true"]').getAttribute('data-category'), '3');
            check(`${engine} ${width}: category correctness`, await page.locator('[data-product-group]:visible').evaluateAll(cs => cs.map(c => c.dataset.productGroup)), Array(8).fill('3'));
            check(`${engine} ${width}: selection does not scroll page`, await page.evaluate(() => scrollY), beforeScroll);
            check(`${engine} ${width}: final category in strip`, await page.locator('[data-category="3"]').evaluate(b => { const r = b.getBoundingClientRect(), p = b.parentElement.getBoundingClientRect(); return r.left >= p.left - 1 && r.right <= p.right + 1; }));
            const focus = await page.locator('[data-category="3"]').evaluate(b => ({ active: document.activeElement === b, focusVisible: b.matches(':focus-visible'), outline: getComputedStyle(b).outlineStyle, rules: [...document.styleSheets].flatMap(s => { try { return [...s.cssRules].filter(r => r.selectorText && b.matches(r.selectorText) && r.style.outline).map(r => r.cssText); } catch { return []; } }) }));
            check(`${engine} ${width}: focus ring`, focus.outline, 'solid');
            await page.keyboard.press('ArrowLeft');
            check(`${engine} ${width}: arrow selection`, await page.locator('[aria-pressed="true"]').getAttribute('data-category'), '2');
            await page.keyboard.press('Home');
            check(`${engine} ${width}: Home restores all`, await page.locator('[data-product-group]:visible').count(), 24);
            await page.keyboard.press('Tab');
            await page.keyboard.press('Space');
            check(`${engine} ${width}: native Space`, await page.locator('[aria-pressed="true"]').getAttribute('data-category'), '1');
            await page.keyboard.press('Tab');
            await page.keyboard.press('Enter');
            check(`${engine} ${width}: native Enter`, await page.locator('[aria-pressed="true"]').getAttribute('data-category'), '2');
            await page.locator('[data-category="3"]').click();
            await page.locator('[onclick="sell_out_tip()"]').click();
            check(`${engine} ${width}: out-of-stock toast`, await page.locator('.toastify').count(), 1);
            check(`${engine} ${width}: console clean`, errors, []);
            report.runs.push({ engine, width, geometry: g, errors });
            await context.close();
        }
        // Cold requests with deterministic 900ms server latency. No routing: browser HTTP cache stays enabled.
        delay = 900;
        const context = await browser.newContext({ viewport: viewport(390) });
        const page = await context.newPage();
        await instrument(page);
        const requestStart = requests.length;
        await page.goto(url, { waitUntil: 'domcontentloaded' });
        await ready(page);
        const initial = await geometry(page);
        const samples = [];
        for (let i = 0; i < 15; i++) { samples.push(await geometry(page)); await sleep(100); }
        check(`${engine}: cold images include pending requests`, samples.some(s => s.images.some(i => !i.complete)) || initial.images.some(i => !i.complete));
        check(`${engine}: opacity stays 1 while loading`, samples.every(s => s.images.every(i => i.opacity === '1')));
        check(`${engine}: no image-induced geometry shift`, samples.every(s => s.images.every((img, i) => img.top === initial.images[i].top && img.height === initial.images[i].height)));
        check(`${engine}: offscreen requests deferred`, requests.length - requestStart < 24);
        for (let y = 600; y < 5200; y += 600) { await page.evaluate(y => scrollTo(0, y), y); await sleep(220); }
        await page.waitForFunction(() => [...document.querySelectorAll('.shop-product-media img')].every(i => i.complete && i.naturalWidth > 0));
        await page.evaluate(() => scrollTo(0, 0));
        const countBeforeSwitch = requests.length;
        check(`${engine}: rapid switching retains exact nodes`, await page.evaluate(() => {
            const images = [...document.querySelectorAll('.shop-product-media img')];
            for (let n = 0; n < 12; n++) document.querySelector(`[data-category="${n % 3 + 1}"]`).click();
            document.querySelector('[data-category="all"]').click();
            return images.every((img, i) => img === document.querySelectorAll('.shop-product-media img')[i] && img.complete);
        }));
        await sleep(300);
        check(`${engine}: switches do not refetch`, requests.length, countBeforeSwitch);
        check(`${engine}: no image style/class/src mutations`, await page.evaluate(() => window.imageMutations), []);
        const shifts = await page.evaluate(() => window.shifts);
        check(`${engine}: no loading layout shifts after initial styles/fonts settle`, shifts.filter(s => s.time >= initial.time && !s.recentInput), []);
        const coldRequests = requests.slice(requestStart);
        await page.reload();
        await ready(page);
        for (let y = 600; y < 5200; y += 600) { await page.evaluate(y => scrollTo(0, y), y); await sleep(50); }
        await page.waitForFunction(() => [...document.querySelectorAll('.shop-product-media img')].every(i => i.complete && i.naturalWidth > 0));
        check(`${engine}: warm cache avoids image requests`, requests.length, countBeforeSwitch);
        report.runs.push({ engine, case: 'cold-scroll-warm-rapid', latencyMs: delay, initial, samples, coldRequests, shifts, imageMutations: await page.evaluate(() => window.imageMutations) });
        await context.close();
        delay = 0;
        const nojs = await browser.newContext({ viewport: viewport(390), javaScriptEnabled: false });
        const plain = await nojs.newPage();
        await plain.goto(url);
        check(`${engine}: no-JS all images load`, await plain.locator('.shop-product-media img').evaluateAll(imgs => imgs.every(i => i.complete && i.naturalWidth > 0)));
        check(`${engine}: no-JS catalog visible`, await plain.locator('[data-product-group]:visible').count(), 24);
        check(`${engine}: no-JS no dead filter controls`, await plain.locator('[data-shop-categories]').isVisible(), false);
        await plain.locator('.shop-product-media').first().scrollIntoViewIfNeeded();
        await plain.screenshot({ path: resolve(out, `${engine}-nojs.png`) });
        await nojs.close();
        for (const fallbackFails of [false, true]) {
            broken = true; brokenFallback = fallbackFails;
            const ctx = await browser.newContext({ viewport: viewport(390) });
            const p = await ctx.newPage();
            await p.goto(url);
            await ready(p);
            await p.waitForFunction(() => { const i = document.querySelector('.shop-product-media img'); return i.complete && !i.hasAttribute('data-fallback-src'); });
            const state = await p.locator('.shop-product-media img').first().evaluate(i => ({ src: i.getAttribute('src'), width: i.naturalWidth, opacity: getComputedStyle(i).opacity, box: i.getBoundingClientRect().height, alt: i.alt }));
            check(`${engine}: failure fallback only once ${fallbackFails}`, state.src, '/assets/common/images/default.jpg');
            check(`${engine}: failure retains media box and text ${fallbackFails}`, state.box > 0 && state.opacity === '1' && !!state.alt);
            check(`${engine}: fallback result ${fallbackFails}`, state.width > 0, !fallbackFails);
            report.runs.push({ engine, case: 'broken', fallbackFails, state });
            await p.screenshot({ path: resolve(out, `${engine}-broken-${fallbackFails}.png`) });
            await ctx.close();
        }
        broken = false; brokenFallback = false;
        await browser.close(); browser = null;
    }
    report.passed = true;
} catch (error) {
    report.passed = false; report.errors.push(error.stack); process.exitCode = 1;
    console.error(error);
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
    await writeFile(resolve(out, 'browser-results.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify({ passed: report.passed, checks: report.checks.length, engines: report.engines.map(({ engine, available, version }) => ({ engine, available, version })), report: resolve(out, 'browser-results.json') }));
}
