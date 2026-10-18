# Shop admin design system

## Intent

Compact operations workspace for a small digital-goods shop. Use Filament core components for consistency, not its default generous spacing. Orders are the entry screen; no decorative dashboard. Preserve the public storefront independently.

## Visual rules

- System sans-serif; body/table text about 13px, secondary details 12px, section headings 15px, page heading 22px.
- White work surface, #f8f9fb chrome, #e3e5e9 separators, #525866 secondary text. Filament Blue for primary actions, Zinc neutrals; semantic badge colors indicate state.
- Small 6–10px radii, no ornamental shadows, no gradients or oversized cards.
- Full-width content with 24px desktop gutters and 13.5rem sidebar. Page padding/gaps are 16px; nested page headers have no additional padding. Density follows Stripe's operational dashboard reference, not its branding or marketing pages.
- Shared spacing roles: 8px tight/table spacing, 12px between fields, 16px section padding/separation. Section headers use 12px vertical and 16px horizontal padding, aligned with their content. Field schemas use 12px row and 16px column gaps. Settings tabs are left-aligned with a 12px panel gap.
- Sidebar navigation owns a balanced 12px inset; group negative margins are reset. Desktop rows are 32px with 2px item gaps and 12px between groups; mobile rows remain 44px. The shared topbar-height variable is 48px, matching the rendered topbar and sidebar offset. Stable scrollbar gutters reserve both edges rather than introducing asymmetric right padding.
- Desktop row actions are 32px high with 8px gaps. Action-cell padding owns vertical breathing room; do not add a second layer on the action group. Text remains 13px with 20px desktop line height, not a smaller type scale. Form inputs remain 36px on fine-pointer desktop.
- Tables show order number/time, product/quantity, customer, amount, payment record and delivery separately. Numeric columns use tabular figures.
- Queue counts belong to their actionable status tabs, not a duplicate summary strip. Today's order count is quiet page context. Left-aligned order tabs sit 8px above the table toolbar. Empty toolbar-action slots do not reserve a mobile row.
- Destructive operations are explicit, confirmed, and domain-validated. Inventory card content is visible/copyable and prefilled for authorized administrators, including sold and looping cards. Configuration secrets and protected order details remain concealed. Downloads require password reconfirmation; ordinary inventory viewing/editing does not.

## Mobile

- 14px gutters; buttons, text/select inputs, tabs, dropdown items and navigation retain 44px minimum heights. The same targets apply on coarse-pointer laptops. Native switch/checkbox semantics and focus treatments remain intact.
- Tables and status tabs scroll inside their own containers. The page itself must not overflow.
- Forms stack actions on narrow screens; preserve legibility instead of shrinking all text.

## Placement rationale

- Product identity, description and image lead the main editing column. Pricing follows, with sale/reference prices paired for comparison and quantity discounts directly below. Desktop price fields are bounded to 232px in the measured layout, not stretched across the entire workspace.
- Fulfillment groups delivery type, current stock, purchase limit, purchase instructions and customer-input requirements. Initial manual stock appears only when creating a manual product; existing stock is a fact, not a disabled editable-looking input. Atomic replenishment and product-filtered card management sit in this section. Replenishing does not discard unsaved product edits.
- Publishing gets a narrower 18rem supporting rail at 1280px and above: availability, category and ordering decide where the finished product appears. Search metadata/integration hooks are behind one disclosure. Below that breakpoint the same DOM order stacks; Save/Cancel remain reachable at the end. The editor is bounded to 76rem on wide displays.
- Order detail separates delivery/customer facts from payment evidence and a vertical amount breakdown. Secondary timestamps/IP/archive metadata follows those decisions in a collapsed section, including on mobile. Protected order contents retain the existing authenticated download policy.
- SMTP test belongs below SMTP configuration inside the Mail tab and uses saved settings only. Uncontained settings tabs remove the extra panel padding around sections. The global Save action remains after the settings form.
- Inventory retains visible multiline content, full copy/view/edit and the parent card's sold/loop policies. Escape and limit the preview value before wrapping it in `.shop-card-preview`; preserve whitespace only inside that span. Applying `pre-wrap` to Filament's outer list also preserves Blade indentation, producing blank lines and oversized rows. Keep the four-line preview limit and full untruncated copy/view/edit values.

## Bulk workflows

- Selection reveals contextual bulk menus rather than permanent extra buttons. Orders group the two delivery outcomes; inventory separates sale status from delivery reuse, ahead of the existing archive/download controls.
- Order confirmation shows selection count, the 1,000-record limit and eligible manual-order scope, then the common customer-visible result and default-off notification choice. Failure is explicitly not a refund. Inventory confirmations spell out resale for unused resets and unchanged sale status for cycling; no private-note or automatic-notification ambiguity.
- Results distinguish changed, already-at-target and skipped records with actionable grouped reasons; confirmed operations clear selection. No layout/theme replacement was needed. Synthetic real Livewire modals were opened at 1440px and 390px iframe viewports (plus native desktop completion), with default-off notification, wrapped copy and visible confirmation controls. No page or modal horizontal overflow was measured. Fixtures cover modal behavior/geometry only; persistent mutations and concurrency are verified by application/MariaDB tests.

## Implementation

`resources/css/filament/admin/theme.css` is the custom Tailwind/Filament theme, compiled with Vite. Panel configuration lives in `app/Providers/Filament/AdminPanelProvider.php`. Use normal Filament APIs before overriding view templates or internals.

## Verification evidence

The compact pass used actual local Laravel/Livewire routes with fresh in-memory synthetic records, not static HTML. Baseline: 40 captures. Final confirmation: 43 captures at 1440×900, 1366×768 and 390×844, after one consolidated correction. No browser/HTTP errors were suppressed. Additional geometry checks cover 320/640/768/1024/1280px coarse-pointer viewports. Dashboard still redirects to Orders. Tables/tabs scroll independently; forms, validation, filters, contextual menus, single/bulk permanent-deletion confirmations, cancellation, keyboard focus and full card copying were exercised.

At 1366×768, fully visible order rows increased from 4 to 8 and inventory rows from 2 to 9. At 1440×900, orders increased from 7 to 10 and inventory from 3 to 11. Two-line inventory rows decreased from 213px to 57px; long previews remain bounded to four lines. The SMTP section's desktop content height decreased from 392px to 352px, with 36px input height unchanged. Mobile inputs increased to 44px, while the form became shorter through reduced field gaps. Detailed reproduction, screenshots and evidence boundaries: `docs/admin-compact-qa.md`.

This evidence is local implementation verification, not a deployed release. PHPUnit covers persistence and business invariants; browser fixtures reset business data per request. Upload submission was not part of this layout pass. No production data, payment semantics, permissions, API contracts or inventory mutations changed. Publication/deployment requires separate review and exact combined-head release gates.
