(function () {
    'use strict';

    document.querySelectorAll('[data-turnstile-checkout]').forEach(function (checkout) {
        const form = checkout.closest('form');
        const widget = checkout.querySelector('[data-turnstile-widget]');
        const status = checkout.querySelector('[data-turnstile-status]');
        const error = checkout.querySelector('[data-checkout-error]');
        const retry = checkout.querySelector('[data-turnstile-retry]');
        const responseField = checkout.querySelector('input[name="cf-turnstile-response"]');
        const buttons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
        const messages = JSON.parse(checkout.dataset.messages);
        let token = '', widgetId = null, busy = false, script = null, loadTimer = null;
        let recoveringFromError = false;

        function updateButtons() {
            buttons.forEach(function (button) { button.disabled = busy || !token; });
            retry.disabled = busy;
            form.setAttribute('aria-busy', busy ? 'true' : 'false');
        }

        function clearToken() {
            token = '';
            responseField.value = '';
            updateButtons();
        }

        function verificationStatus(message, canRetry) {
            status.textContent = message;
            retry.hidden = !canRetry;
        }

        function showError(message) {
            // Treat server messages as text, never markup. Keep the form and its fields intact.
            error.textContent = message;
            error.hidden = false;
            error.focus();
        }

        function resetWidget(message) {
            clearToken();
            verificationStatus(message || messages.required, true);
            if (widgetId !== null && window.turnstile) {
                try {
                    window.turnstile.reset(widgetId);
                } catch (_) {
                    verificationStatus(messages.unavailable, true);
                }
            }
        }

        function renderWidget() {
            window.clearTimeout(loadTimer);
            if (widgetId !== null || !window.turnstile) return;
            try {
                widgetId = window.turnstile.render(widget, {
                    sitekey: widget.dataset.sitekey,
                    action: widget.dataset.action,
                    appearance: widget.dataset.appearance,
                    size: widget.dataset.size,
                    theme: widget.dataset.theme,
                    'response-field': false,
                    retry: 'never',
                    'refresh-expired': 'manual',
                    'refresh-timeout': 'manual',
                    callback: function (value) {
                        if (typeof value !== 'string' || !value) return;
                        token = value;
                        responseField.value = value;
                        recoveringFromError = false;
                        verificationStatus(busy ? messages.submitting : messages.ready, false);
                        updateButtons();
                    },
                    'expired-callback': function () { resetWidget(messages.expired); },
                    'timeout-callback': function () { resetWidget(messages.expired); },
                    'error-callback': function () {
                        clearToken();
                        verificationStatus(messages.failed, true);
                        // One automatic reset per error sequence; a blocked provider must not loop.
                        if (!recoveringFromError) {
                            recoveringFromError = true;
                            resetWidget(messages.failed);
                        }
                        return true;
                    },
                    'unsupported-callback': function () {
                        clearToken();
                        verificationStatus(messages.unavailable, true);
                    },
                });
            } catch (_) {
                clearToken();
                verificationStatus(messages.unavailable, true);
            }
        }

        function loadWidget() {
            if (!widget.dataset.sitekey) {
                verificationStatus(messages.unavailable, false);
                return;
            }
            verificationStatus(messages.loading, false);
            if (window.turnstile) {
                renderWidget();
                return;
            }
            window.clearTimeout(loadTimer);
            if (script) script.remove();
            script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=checkoutTurnstileReady&render=explicit';
            script.async = true;
            script.defer = true;
            script.onerror = function () {
                window.clearTimeout(loadTimer);
                clearToken();
                verificationStatus(messages.unavailable, true);
            };
            window.checkoutTurnstileReady = renderWidget;
            loadTimer = window.setTimeout(script.onerror, 20000);
            document.head.appendChild(script);
        }

        retry.addEventListener('click', function () {
            if (busy) return;
            recoveringFromError = false;
            if (widgetId === null) loadWidget();
            else resetWidget(messages.loading);
        });

        // Capture before theme/layui submit listeners. The enabled Luna button deliberately
        // has no lay-submit attribute, which would otherwise submit the form natively.
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (busy) return;
            if (!token) {
                verificationStatus(messages.required, true);
                return;
            }
            if (!form.reportValidity()) return;

            // Serialize BEFORE disabling anything; this includes manual inputs, selects,
            // coupon, query password, payment choice, and Laravel's CSRF field.
            const body = new FormData(form);
            const quantity = Number(body.get('by_amount'));
            const stock = Number(checkout.dataset.stock);
            const limit = Number(checkout.dataset.limit);
            if (!Number.isInteger(quantity) || quantity < 1) { showError(messages.quantity); return; }
            if (quantity > stock) { showError(messages.stock); return; }
            if (limit > 0 && quantity > limit) { showError(messages.limit); return; }
            if (!body.get('payway') || body.get('payway') === '0') { showError(messages.payment); return; }
            body.set('cf-turnstile-response', token);
            busy = true;
            clearToken();
            error.hidden = true;
            error.textContent = '';
            verificationStatus(messages.submitting, false);
            const controller = new AbortController();
            const requestTimer = window.setTimeout(function () { controller.abort(); }, 30000);
            let navigated = false;
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    body: body,
                    signal: controller.signal,
                });
                let data = null;
                try { data = await response.json(); } catch (_) { /* HTML/proxy errors stay inline. */ }
                if (!response.ok) {
                    const message = response.status === 419 ? messages.session_expired
                        : (response.status === 422 && data && typeof data.message === 'string' && data.message.trim()
                            ? data.message : messages.retry_order);
                    showError(message);
                    return;
                }
                if (!data || typeof data.redirect !== 'string') throw new Error('Invalid checkout response');
                const destination = new URL(data.redirect);
                if (!/^https?:$/.test(destination.protocol) || destination.origin !== window.location.origin
                    || destination.username || destination.password) throw new Error('Invalid checkout destination');
                window.location.assign(destination.href);
                navigated = true;
            } catch (_) {
                showError(messages.retry_order);
            } finally {
                window.clearTimeout(requestTimer);
                if (!navigated) {
                    busy = false;
                    recoveringFromError = false;
                    resetWidget(messages.required);
                }
            }
        }, true);

        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                busy = false;
                resetWidget(messages.required);
            }
        });

        clearToken();
        loadWidget();
    });
}());
