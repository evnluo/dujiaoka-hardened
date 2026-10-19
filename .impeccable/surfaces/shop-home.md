---
version: 1
slug: "shop-home"
primary_target: "resources/views/hyper/static_pages/home.blade.php"
related_targets: ["public/assets/hyper/css/home-catalog.css", "public/assets/hyper/js/home-catalog.js"]
---

# Homepage catalog

Mode: Operate. Target: resources/views/hyper/static_pages/home.blade.php.

Preserve the storefront's white/zinc palette, black selected pills, existing branding, announcement, two-column mobile cards and product content. No admin, checkout, pricing, artwork or fulfillment changes.

Hierarchy: announcement → left-aligned category strip → catalog. Category buttons have single-line labels, 44px minimum touch height, nonshrinking widths, native horizontal scrolling and a visible focus ring. One pressed filter at a time; Enter/Space activate natively, arrows/Home/End move focus and select. Scroll only the strip enough to expose the selected button; never call document scrollIntoView.

One product grid replaces duplicated hidden panels. Preserve global descending ord and stable per-group order. Filtering only toggles hidden on existing cards; no recreation or src reassignment. Without JavaScript all products and images remain available and the nonfunctional filter is hidden.

Images use real src, square reserved space, async decode, two eager images (the first high priority), then native lazy loading. The browser owns request distance and caching. No opacity gating, spinner, shimmer, stagger, loading animation or timed reveal. A failed request uses the existing default image once; if that also fails, native alt text and the product title/link remain. Loading has no motion. Available cards have reversible 200ms shadow/border/action-color transitions and a subtle 1.035 image scale only for hover-capable fine pointers. Keyboard focus uses an outline and action contrast, without zoom. Reduced motion disables transitions and scale; touch and sold-out cards never zoom. CSS transitions naturally reverse/supersede without callbacks.

Verification: rendered Blade contracts plus Chromium mobile 320/375/390/430 and desktop 1440, WebKit when runtime dependencies permit. Cold/delayed/warm requests, scrolling, image-node identity across rapid filters, keyboard, no JS, broken images, reduced motion, card/image geometry and console. Local fixtures are synthetic and never touch production records. Independent child card owns release.
