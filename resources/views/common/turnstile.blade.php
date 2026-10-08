@if(config('turnstile.enabled'))
    @once
        <style>
            .checkout-verification { margin-block: 1.25rem 1rem; min-width: 0; text-align: start; overflow-wrap: anywhere; }
            .checkout-verification__label { margin: 0 0 .5rem; font-size: 1rem; font-weight: 600; color: #27272a; }
            /* Preserve Cloudflare's full 300 × 65 minimum: never scale, clip, or use compact. */
            .checkout-verification__widget { min-width: 300px; min-height: 65px; width: 100%; font-size: 0; line-height: 0; }
            .checkout-verification__status, .checkout-verification__error { margin: .5rem 0 0; font-size: .875rem; line-height: 1.5; color: #52525b; }
            .checkout-verification__error { color: #b91c1c; }
            .checkout-verification__retry { display: inline-block; min-height: 44px; padding: .5rem 0; border: 0; background: transparent; color: #27272a; font: inherit; text-decoration: underline; text-underline-offset: .2em; cursor: pointer; }
            .checkout-verification__retry:hover { color: #09090b; }
            .checkout-verification__retry:focus-visible, .checkout-verification__error:focus-visible { outline: 2px solid #52525b; outline-offset: 3px; }
            .checkout-verification [hidden] { display: none !important; }
            form:has([data-turnstile-checkout]) button[type="submit"]:disabled { opacity: .55; cursor: not-allowed; }
            .turnstile-buy-grid > * { min-width: 0; }
            @media (min-width: 768px) {
                .turnstile-buy-grid { grid-template-columns: minmax(332px, 1fr) minmax(0, 2fr) !important; }
                .turnstile-buy-grid > * { grid-column: auto !important; }
            }
            @media (min-width: 992px) {
                .turnstile-buy-grid { grid-template-columns: minmax(0, 2fr) minmax(332px, 1fr) !important; }
            }
            @media (max-width: 575px) {
                /* Borrow only the surrounding mobile gutters when the field column is narrow. */
                .checkout-verification__widget { width: max(100%, 300px); margin-inline-start: min(0px, calc((100% - 300px) / 2)); }
                .turnstile-unicorn .card-body.p-4 { padding-inline: .5rem !important; }
            }
        </style>
    @endonce
    <div class="checkout-verification" data-turnstile-checkout
         data-stock="{{ $in_stock }}" data-limit="{{ $buy_limit_num }}"
         data-messages="{{ json_encode(__('checkout'), JSON_UNESCAPED_UNICODE) }}">
        <p class="checkout-verification__label" id="checkout-verification-label">{{ __('checkout.label') }}</p>
        <div class="checkout-verification__widget" data-turnstile-widget
             data-sitekey="{{ config('turnstile.site_key') }}" data-action="create_order"
             data-appearance="always" data-size="flexible" data-theme="light"
             role="group" aria-labelledby="checkout-verification-label"></div>
        <input type="hidden" name="cf-turnstile-response" value="" autocomplete="off">
        <p class="checkout-verification__status" data-turnstile-status role="status" aria-live="polite">{{ __('checkout.loading') }}</p>
        <button type="button" class="checkout-verification__retry" data-turnstile-retry hidden>{{ __('checkout.retry') }}</button>
        <p class="checkout-verification__error" data-checkout-error role="alert" tabindex="-1" hidden></p>
        <noscript><p class="checkout-verification__error">{{ __('checkout.no_script') }}</p></noscript>
    </div>
    @once
        <script src="{{ asset('assets/common/checkout-turnstile.js') }}" defer></script>
    @endonce
@endif
