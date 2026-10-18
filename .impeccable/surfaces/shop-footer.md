---
version: 1
slug: "shop-footer"
primary_target: "resources/views/hyper/layouts/_footer.blade.php"
related_targets: ["public/assets/hyper/css/shop-footer.css", "public/assets/hyper/js/shop-footer.js"]
---

# Shared storefront footer

Mode: Operate. Inherit white/zinc storefront typography and rounded controls; DESIGN.md is admin-only and unchanged. Customers finish browsing, look up orders or contact Evan. Desktop: brand left, useful links and inline back-to-top right; below 640px: brand then wrapping navigation. Minimum 44px controls, visible keyboard outline, no fixed overlay. No claims, credits, template advertisement, entry animation or site-wide redesign.

## Direction contract

THESIS: A compact branded close, not template boilerplate or a marketing sitemap.
OWN-WORLD: White surface, zinc text, hairline top rule, existing sans-serif, quiet rounded hover targets.
STORY: Recognize Evan’s Shop; query an order or contact Evan directly.
FIRST VIEWPORT: Footer follows content in normal flow. Brand at left; 查询订单, 联系 Evan and 返回顶部 align right on desktop and stack below the brand on mobile.
FORM: Precisely specified local component replacement; no concept seed required.
FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

Interactions: explicit 200ms background/color transitions, instant reduced-motion feedback; back-to-top uses native scroll with reduced-motion auto behavior. No entry/opacity animation. Preserve stored footer settings and custom/legal content, scripts and styles; omit only the exact installed obsolete support prefix at render time. Keep repository licenses intact. Verify shared home/product/order-search, desktop/390/320, focus, touch, link destinations and retired copy. No generated raster assets.

Finish review: actual Blade fixture screenshots at 320/390/1440 show unclipped controls and clear brand-left/navigation-right desktop alignment, stacked mobile alignment and visible focus. Computed bounds confirm 44px controls with no page overflow. Chromium and WebKit exercise native hover-in/out, touch, focus and reduced motion; PHP tests preserve custom/legal HTML and stored settings. The scoped storefront brief records the built component; admin DESIGN.md remains intentionally unchanged.
