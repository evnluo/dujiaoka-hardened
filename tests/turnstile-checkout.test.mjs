import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../public/assets/common/checkout-turnstile.js', import.meta.url), 'utf8');
const messages = {
    loading: 'Loading verification…', ready: 'Verified. You can place your order.',
    required: 'Complete verification before placing your order.', expired: 'Verification expired. Please verify again.',
    unavailable: 'Verification could not load. Check your connection and retry.',
    failed: 'Verification failed. Please retry.', submitting: 'Placing your order…',
    retry_order: 'We could not confirm your order. Your details are still here. Verify again and retry.',
    session_expired: 'Your session expired. Open this shop in another tab, then return and retry.',
    quantity: 'Enter a whole quantity of at least 1.', stock: 'Not enough stock.', limit: 'Purchase limit exceeded.',
    payment: 'Select a payment method.',
};

function element(extra = {}) {
    const listeners = new Map();
    return Object.assign({
        value: '', textContent: '', hidden: false, disabled: false, dataset: {},
        setAttribute(name, value) { this[name] = value; },
        removeAttribute(name) { delete this[name]; },
        addEventListener(name, fn) { listeners.set(name, fn); },
        dispatch(name, extra = {}) {
            const event = { preventDefault() { this.prevented = true; }, stopImmediatePropagation() {}, ...extra };
            return listeners.get(name)?.(event);
        },
        focus() { this.focused = true; },
        remove() { this.removed = true; },
    }, extra);
}

function setup({ fetch = async () => ({ ok: false, status: 422, json: async () => ({ message: 'Please verify again.' }) }), sitekey = 'test-public-key', api = true } = {}) {
    const fields = Object.fromEntries(Object.entries({
        _token: 'csrf-fixture', gid: '7', email: 'buyer@example.test', by_amount: '2',
        coupon_code: 'COUPON', search_pwd: 'query-password', account_name: 'customer-account', region: 'West',
        payway: '14', 'cf-turnstile-response': '',
    }).map(([key, value]) => [key, element({ name: key, value })]));
    const button = element({ disabled: true, innerHTML: '<span>Place order</span>' });
    const status = element(), error = element({ hidden: true }), retry = element({ hidden: true });
    const widget = element({ dataset: { sitekey, action: 'create_order', appearance: 'always', size: 'flexible', theme: 'light' } });
    const nodes = {
        '[data-turnstile-widget]': widget, '[data-turnstile-status]': status,
        '[data-checkout-error]': error, '[data-turnstile-retry]': retry,
        'input[name="cf-turnstile-response"]': fields['cf-turnstile-response'],
    };
    const form = element({
        action: 'https://shop.example.test/create-order', method: 'post',
        reportValidity: () => true,
        querySelector: (selector) => nodes[selector] ?? fields[selector.match(/\[name="(.*?)"\]/)?.[1]],
        querySelectorAll: () => [button],
    });
    const root = element({
        dataset: { messages: JSON.stringify(messages), stock: '10', limit: '4' },
        closest: () => form, querySelector: (selector) => nodes[selector],
    });
    let options, resets = 0, calls = [], redirects = [], scripts = [], timers = new Map(), timerId = 0;
    const turnstile = {
        render: (target, config) => { options = config; return 'widget-1'; },
        reset: () => { resets++; },
    };
    class TestFormData {
        constructor() { this.data = new Map(Object.entries(fields).filter(([, field]) => !field.disabled).map(([key, field]) => [key, field.value])); }
        get(key) { return this.data.get(key); }
        set(key, value) { this.data.set(key, value); }
    }
    const window = element({
        turnstile: api ? turnstile : undefined,
        location: { href: 'https://shop.example.test/buy/7', origin: 'https://shop.example.test', assign: (url) => redirects.push(url) },
        setTimeout: (fn, delay) => { timers.set(++timerId, { fn, delay }); return timerId; },
        clearTimeout: (id) => timers.delete(id),
    });
    const context = {
        window, document: { querySelectorAll: () => [root], createElement: () => element(), head: { appendChild: (script) => scripts.push(script) } },
        FormData: TestFormData, URL, AbortController,
        fetch: async (...args) => { calls.push(args); return fetch(...args); },
    };
    vm.runInNewContext(source, context);
    return { fields, button, status, error, retry, root, form, window, scripts, timers, turnstile,
        get options() { return options; }, get resets() { return resets; }, calls, redirects,
        solve: (token = 'single-use-token') => options.callback(token),
        submit: () => form.dispatch('submit'),
    };
}

function customerValues(s) {
    return Object.fromEntries(Object.entries(s.fields).filter(([key]) => key !== 'cf-turnstile-response').map(([key, field]) => [key, field.value]));
}

test('visible widget gates submission and never navigates without a token', async () => {
    const s = setup();
    assert.equal(s.options.action, 'create_order');
    assert.equal(s.options.appearance, 'always');
    assert.equal(s.options.size, 'flexible');
    assert.equal(s.options.theme, 'light');
    assert.equal(s.options['response-field'], false);
    assert.equal(s.button.disabled, true);
    await s.submit();
    assert.equal(s.calls.length, 0);
    s.solve();
    assert.equal(s.button.disabled, false);
});

test('422 keeps ALL fields in place, sends CSRF and JSON Accept, consumes token and resets', async () => {
    const s = setup();
    const before = customerValues(s);
    s.solve();
    await s.submit();
    assert.deepEqual(customerValues(s), before);
    const [, request] = s.calls[0];
    assert.equal(request.headers.Accept, 'application/json');
    assert.equal(request.credentials, 'same-origin');
    for (const [key, value] of Object.entries(before)) assert.equal(request.body.get(key), value, key);
    assert.equal(request.body.get('cf-turnstile-response'), 'single-use-token');
    assert.equal(s.fields['cf-turnstile-response'].value, '');
    assert.equal(s.error.textContent, 'Please verify again.');
    assert.equal(s.error.hidden, false);
    assert.equal(s.error.focused, true);
    assert.equal(s.resets, 1);
    assert.equal(s.button.disabled, true);
    assert.deepEqual(s.redirects, []);
    s.solve('new-token');
    assert.equal(s.button.disabled, false);
    assert.equal(s.error.hidden, false, 'verification success must not erase the order validation error');
});

for (const callback of ['expired-callback', 'timeout-callback', 'error-callback']) {
    test(`${callback} invalidates and resets the widget without losing customer fields`, () => {
        const s = setup(), before = customerValues(s);
        s.solve();
        s.options[callback]();
        assert.equal(s.button.disabled, true);
        assert.equal(s.fields['cf-turnstile-response'].value, '');
        assert.equal(s.resets, 1);
        assert.deepEqual(customerValues(s), before);
        assert.equal(s.retry.hidden, false);
    });
}

test('repeated widget errors do not loop automatic reset; explicit retry is available', () => {
    const s = setup();
    s.options['error-callback']();
    s.options['error-callback']();
    assert.equal(s.resets, 1);
    s.retry.dispatch('click');
    assert.equal(s.resets, 2);
});

for (const [name, response] of [
    ['network failure', async () => { throw new TypeError('offline'); }],
    ['HTML error page', async () => ({ ok: false, status: 500, json: async () => { throw new SyntaxError(); } })],
    ['malformed success', async () => ({ ok: true, status: 200, json: async () => ({}) })],
    ['null response', async () => ({ ok: true, status: 200, json: async () => null })],
    ['unsafe redirect', async () => ({ ok: true, status: 200, json: async () => ({ redirect: 'javascript:alert(1)' }) })],
    ['external redirect', async () => ({ ok: true, status: 200, json: async () => ({ redirect: 'https://evil.example/order' }) })],
]) {
    test(`${name} offers a friendly inline retry, with no navigation or field loss`, async () => {
        const s = setup({ fetch: response }), before = customerValues(s);
        s.solve();
        await s.submit();
        assert.deepEqual(customerValues(s), before);
        assert.equal(s.error.textContent, messages.retry_order);
        assert.equal(s.resets, 1);
        assert.equal(s.button.disabled, true);
        assert.deepEqual(s.redirects, []);
    });
}

test('session expiry explains how to retry without discarding this form', async () => {
    const s = setup({ fetch: async () => ({ ok: false, status: 419, json: async () => ({ message: 'CSRF token mismatch.' }) }) });
    s.solve();
    await s.submit();
    assert.equal(s.error.textContent, messages.session_expired);
});

test('successful response navigates exactly once and prevents duplicate submits', async () => {
    let finish;
    const s = setup({ fetch: () => new Promise(resolve => { finish = resolve; }) });
    s.solve();
    const request = s.submit();
    await s.submit();
    assert.equal(s.calls.length, 1);
    assert.equal(s.button.disabled, true);
    assert.equal(s.form['aria-busy'], 'true');
    finish({ ok: true, status: 200, json: async () => ({ redirect: 'https://shop.example.test/bill/ORDER1' }) });
    await request;
    assert.deepEqual(s.redirects, ['https://shop.example.test/bill/ORDER1']);
    assert.equal(s.button.disabled, true);
});

test('blocked script fails closed and retry reloads only the verification script', () => {
    const s = setup({ api: false });
    assert.equal(s.scripts.length, 1);
    s.scripts[0].onerror();
    assert.equal(s.button.disabled, true);
    assert.equal(s.status.textContent, messages.unavailable);
    assert.equal(s.retry.hidden, false);
    s.retry.dispatch('click');
    assert.equal(s.scripts.length, 2);
    s.window.turnstile = s.turnstile;
    s.window.checkoutTurnstileReady();
    s.solve();
    assert.equal(s.button.disabled, false);
});

test('missing site key does not load a widget or enable checkout', () => {
    const s = setup({ sitekey: '' });
    assert.equal(s.scripts.length, 0);
    assert.equal(s.button.disabled, true);
    assert.equal(s.status.textContent, messages.unavailable);
});

test('quantity, stock, purchase limit and payment validation cannot be bypassed by Enter', async () => {
    for (const [field, value, message] of [
        ['by_amount', '0', messages.quantity], ['by_amount', '1.5', messages.quantity],
        ['by_amount', '11', messages.stock], ['by_amount', '5', messages.limit], ['payway', '0', messages.payment],
    ]) {
        const s = setup();
        s.fields[field].value = value;
        s.solve();
        await s.submit();
        assert.equal(s.calls.length, 0);
        assert.equal(s.error.textContent, message);
    }
});

test('back-forward cache restore drops any previously consumed token', () => {
    const s = setup();
    s.solve();
    s.window.dispatch('pageshow', { persisted: true });
    assert.equal(s.button.disabled, true);
    assert.equal(s.fields['cf-turnstile-response'].value, '');
    assert.equal(s.resets, 1);
});

test('checkout code never stores or logs tokens and never submits forms natively', () => {
    assert.doesNotMatch(source, /localStorage|sessionStorage|console\.|\.submit\(|\.reset\(\s*\)/);
});
