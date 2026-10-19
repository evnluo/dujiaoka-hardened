@php
    $footerContent = (string) dujiaoka_config_get('footer');
    // Omit only the installed obsolete support prefix; keep custom content and settings intact.
    $legacySupport = 'Need help? <a href="https://me.ohevan.com" style="text-decoration: underline;"> Contact me </a>';
    if (str_starts_with(ltrim($footerContent), $legacySupport)) {
        $footerContent = substr(ltrim($footerContent), strlen($legacySupport));
    }
@endphp
<link rel="stylesheet" href="/assets/hyper/css/shop-footer.css?v=1">
<footer class="shop-footer">
    <div class="container shop-footer-inner">
        <div class="shop-footer-main">
            <a class="shop-footer-brand" href="{{ url('/') }}">Evan’s Shop</a>
            <nav class="shop-footer-nav" aria-label="页脚导航">
                <a href="{{ url('/order-search') }}">查询订单</a>
                <a href="https://me.ohevan.com" target="_blank" rel="noopener noreferrer">联系 Evan</a>
                <button type="button" class="shop-footer-top" data-shop-back-to-top hidden>
                    返回顶部
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 12 6-6 6 6M12 6v14"/></svg>
                </button>
            </nav>
        </div>
        @if(trim($footerContent) !== '')
            <div class="shop-footer-custom">{!! $footerContent !!}</div>
        @endif
    </div>
</footer>
<script src="/assets/hyper/js/shop-footer.js?v=1" defer></script>
