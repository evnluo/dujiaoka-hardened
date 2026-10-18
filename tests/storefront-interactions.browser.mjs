// Shared by synthetic and read-only live browser checks; never submits an order.
export async function checkStorefrontInteractions(page, { check, label, reduced = false, touch = false, screenshot }) {
    const card = page.locator('.shop-product-available').first();
    const image = card.locator('img');
    const state = () => card.evaluate(c => {
        const i = getComputedStyle(c.querySelector('img')), a = getComputedStyle(c.querySelector('.shop-product-action')), s = getComputedStyle(c);
        return { transform: i.transform, opacity: i.opacity, imageDuration: i.transitionDuration, imageProperty: i.transitionProperty, shadow: s.boxShadow, border: s.borderColor, duration: s.transitionDuration, action: a.backgroundColor, actionColor: a.color, focus: c.matches(':focus-visible'), outline: s.outlineStyle };
    });
    await card.scrollIntoViewIfNeeded();
    await page.mouse.move(0, 0);
    await page.waitForTimeout(250);
    const initial = await state();
    const fine = await page.evaluate(() => matchMedia('(hover: hover) and (pointer: fine)').matches);
    check(`${label}: effective pointer capability`, fine, !touch);
    await card.evaluate(c => { window.catalogTransitions = []; c.addEventListener('transitionrun', e => window.catalogTransitions.push(e.propertyName)); });
    await card.hover();
    await page.waitForTimeout(60);
    const entering = await state();
    if (fine && !reduced) await image.evaluate(i => Promise.all(i.getAnimations().map(a => a.finished)));
    await page.waitForTimeout(100);
    const hovered = await state();
    check(`${label}: images always opaque`, [initial, entering, hovered].every(s => s.opacity === '1'));
    if (fine && !reduced) {
        check(`${label}: explicit image transform duration`, hovered.imageDuration, '0.2s');
        check(`${label}: explicit image transform property`, hovered.imageProperty, 'transform');
        check(`${label}: hover zoom endpoint`, hovered.transform, 'matrix(1.035, 0, 0, 1.035, 0, 0)');
        // WebKit's headless compositor may skip intermediate painted frames; transitionrun below proves native motion started.
        if (page.context().browser().browserType().name() === 'chromium') check(`${label}: actual hover-in intermediate`, entering.transform !== initial.transform && entering.transform !== hovered.transform);
        check(`${label}: card shadow changes`, hovered.shadow !== initial.shadow);
        check(`${label}: card border changes`, hovered.border !== initial.border);
        check(`${label}: action contrast changes`, hovered.action !== initial.action && hovered.actionColor === 'rgb(255, 255, 255)');
        const events = await page.evaluate(() => window.catalogTransitions);
        check(`${label}: real transitions run`, ['transform', 'box-shadow', 'border-top-color', 'background-color', 'color'].every(p => events.includes(p)));
    } else {
        check(`${label}: touch/reduced image never scales`, hovered.transform, 'none');
        if (reduced) check(`${label}: reduced transitions disabled`, hovered.duration, '0s');
    }
    await page.mouse.move(0, 0);
    await page.waitForTimeout(60);
    const leaving = await state();
    if (fine && !reduced) await image.evaluate(i => Promise.all(i.getAnimations().map(a => a.finished)));
    await page.waitForTimeout(100);
    const exited = await state();
    check(`${label}: hover-out restores image`, exited.transform, initial.transform);
    if (fine && !reduced) {
        check(`${label}: real hover-out transition`, await page.evaluate(() => window.catalogTransitions.filter(p => p === 'transform').length >= 2));
        if (page.context().browser().browserType().name() === 'chromium') check(`${label}: actual hover-out intermediate`, leaving.transform !== hovered.transform && leaving.transform !== exited.transform);
    }
    if (touch) {
        await card.evaluate(c => c.addEventListener('click', e => e.preventDefault(), { once: true }));
        await card.tap();
        await page.waitForTimeout(250);
        check(`${label}: touch tap never leaves sticky zoom`, (await state()).transform, 'none');
    }
    await page.keyboard.press('Tab');
    await card.focus();
    await page.waitForTimeout(250);
    const focused = await state();
    check(`${label}: keyboard focus outline`, focused.focus && focused.outline === 'solid');
    check(`${label}: keyboard action contrast`, focused.action, 'rgb(24, 24, 27)');
    check(`${label}: keyboard has no zoom`, focused.transform, 'none');
    await card.evaluate(c => c.blur());
    const sold = page.locator('[onclick="sell_out_tip()"]');
    if (await sold.count()) {
        await sold.first().hover();
        await page.waitForTimeout(250);
        check(`${label}: sold-out never zooms`, await sold.first().locator('img').evaluate(i => getComputedStyle(i).transform), 'none');
        check(`${label}: sold-out action stays dim`, await sold.first().locator('.shop-product-action').evaluate(i => getComputedStyle(i).opacity), '0.5');
    }
    const footer = page.locator('.shop-footer');
    await footer.scrollIntoViewIfNeeded();
    const text = await footer.innerText();
    check(`${label}: retired footer copy absent`, !/Powered by Dujiaoka|Modified by Evan|buy this template|Need help\?|Contact me/.test(text));
    check(`${label}: footer brand`, await footer.locator('.shop-footer-brand').innerText(), 'Evan’s Shop');
    check(`${label}: order route`, new URL(await footer.getByRole('link', { name: '查询订单' }).getAttribute('href'), page.url()).pathname, '/order-search');
    const contact = footer.getByRole('link', { name: '联系 Evan' });
    check(`${label}: contact destination`, await contact.getAttribute('href'), 'https://me.ohevan.com');
    check(`${label}: external link isolated`, await contact.getAttribute('rel'), 'noopener noreferrer');
    check(`${label}: no obsolete template links`, await footer.locator('[href*="evannotfound.com"]').count(), 0);
    const geometry = await footer.evaluate(f => {
        const nav = f.querySelector('nav'), brand = f.querySelector('.shop-footer-brand');
        return { page: document.documentElement.scrollWidth, width: innerWidth, nav: nav.getBoundingClientRect().toJSON(), brand: brand.getBoundingClientRect().toJSON(), controls: [...f.querySelectorAll('nav a,button')].map(e => { const r = e.getBoundingClientRect(); return { width: r.width, height: r.height, right: r.right, position: getComputedStyle(e).position }; }) };
    });
    check(`${label}: footer no page overflow`, geometry.page <= geometry.width);
    check(`${label}: footer controls touchable in flow`, geometry.controls.every(c => c.height >= 44 && c.width >= 44 && c.right <= geometry.width && c.position !== 'fixed'));
    check(`${label}: responsive footer composition`, geometry.width < 640 ? geometry.nav.top >= geometry.brand.bottom : Math.abs(geometry.nav.top - geometry.brand.top) < 2);
    await contact.focus();
    check(`${label}: footer focus outline`, await contact.evaluate(a => getComputedStyle(a).outlineStyle), 'solid');
    if (reduced) check(`${label}: footer reduced motion`, await contact.evaluate(a => getComputedStyle(a).transitionDuration), '0s');
    if (screenshot) await page.screenshot({ path: screenshot });
    await page.evaluate(() => {
        const native = window.scrollTo.bind(window);
        window.scrollTo = (...args) => { window.footerScrollRequest = args[0]; native(...args); };
    });
    const top = footer.getByRole('button', { name: '返回顶部' });
    if (touch) await top.tap(); else await top.click();
    check(`${label}: back-to-top motion policy`, await page.evaluate(() => window.footerScrollRequest.behavior), reduced ? 'auto' : 'smooth');
    await page.waitForFunction(() => scrollY === 0);
    check(`${label}: back-to-top works`, await page.evaluate(() => scrollY), 0);
    await page.mouse.move(0, 0);
    await page.evaluate(() => document.activeElement?.blur());
}
