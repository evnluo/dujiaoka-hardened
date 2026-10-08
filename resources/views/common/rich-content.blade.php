@once
<style>
    /* Only authored content: catalog crops, logos, CAPTCHA and QR images keep their own rules. */
    .shop-rich-content { min-width: 0; overflow-wrap: anywhere; }
    .shop-rich-content img { max-width: 100%; height: auto !important; }
</style>
@endonce
<div class="shop-rich-content">{!! \App\Support\ShopRichContent::render($content) !!}</div>
