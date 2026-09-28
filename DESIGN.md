# Shop admin design system

## Intent

Compact operations workspace for a small digital-goods shop. Use Filament core components for consistency, not its default generous spacing. Orders are the entry screen; no decorative dashboard. Preserve the public storefront independently.

## Visual rules

- System sans-serif; body/table text about 13px, secondary details 12px, section headings 15px, page heading 22px.
- White work surface, #f8f9fb chrome, #e3e5e9 separators, #525866 secondary text. Filament Blue for primary actions, Zinc neutrals; semantic badge colors indicate state.
- Small 6–10px radii, no ornamental shadows, no gradients or oversized cards.
- Full-width content with 24px desktop gutters and 13.5rem sidebar. Desktop page padding 20px; remove the nested default header padding to avoid doubled whitespace.
- Sidebar navigation owns a balanced 12px inset; group negative margins are reset. Desktop rows are 34px with 2px item gaps and 12px between groups; mobile rows remain 44px. The shared topbar-height variable is 52px, matching the rendered topbar and sidebar offset. Stable scrollbar gutters reserve both edges rather than introducing asymmetric right padding.
- Tables show order number/time, product/quantity, customer, amount, payment record and delivery separately. Numeric columns use tabular figures.
- Queue counts belong to their actionable status tabs, not a duplicate summary strip. Today's order count is quiet page context. Left-aligned order tabs sit 8px above the table toolbar. Empty toolbar-action slots do not reserve a mobile row.
- Destructive operations are explicit, confirmed, and domain-validated. Inventory card content is visible/copyable and prefilled for authorized administrators, including sold and looping cards. Configuration secrets and protected order details remain concealed. Downloads require password reconfirmation; ordinary inventory viewing/editing does not.

## Mobile

- 14px gutters, 44px minimum buttons/toggles, collapsible navigation.
- Tables and status tabs scroll inside their own containers. The page itself must not overflow.
- Forms stack actions on narrow screens; preserve legibility instead of shrinking all text.

## Placement rationale

- Product identity, description and image lead the main editing column. Pricing follows, with sale/reference prices paired for comparison and quantity discounts directly below. Desktop price fields are bounded to 232px in the measured layout, not stretched across the entire workspace.
- Fulfillment groups delivery type, current stock, purchase limit, purchase instructions and customer-input requirements. Initial manual stock appears only when creating a manual product; existing stock is a fact, not a disabled editable-looking input. Atomic replenishment and product-filtered card management sit in this section. Replenishing does not discard unsaved product edits.
- Publishing gets a narrower 18rem supporting rail at 1280px and above: availability, category and ordering decide where the finished product appears. Search metadata/integration hooks are behind one disclosure. Below that breakpoint the same DOM order stacks; Save/Cancel remain reachable at the end. The editor is bounded to 76rem on wide displays.
- Order detail separates delivery/customer facts from payment evidence and a vertical amount breakdown. Secondary timestamps/IP/archive metadata follows those decisions in a collapsed section, including on mobile. Protected order contents retain the existing authenticated download policy.
- SMTP test belongs below SMTP configuration inside the Mail tab and uses saved settings only. Uncontained settings tabs remove the extra panel padding around sections. The global Save action remains after the settings form.
- Inventory retains visible multiline content, full copy/view/edit and the parent card's sold/loop policies. The shared navigation and toolbar fixes apply without redesigning the inventory workflow.

## Implementation

`resources/css/filament/admin/theme.css` is the custom Tailwind/Filament theme, compiled with Vite. Panel configuration lives in `app/Providers/Filament/AdminPanelProvider.php`. Use normal Filament APIs before overriding view templates or internals.

## Verification evidence

Current-source synthetic renders were inspected at 1440px and 390px in one visual pass plus one consolidated correction/confirmation. Desktop sidebar bounds are x0–216, main wrapper x216, content x240; navigation x12–203 within a 215px inner width confirms balanced 12px insets rather than a duplicated main gutter. Desktop rows measure 34px; opened mobile rows 44px. All five checked mobile surfaces measure 390px client/scroll width. Orders tabs scroll independently (362/483px), as does the table (362/1040px), with Details reachable at the right. The corrected mobile order toolbar is 61px high, with no internal overflow. Mobile product Save is reachable by ordinary scrolling and focuses normally.

Fixtures are generated from a source-only isolated image with synthetic data and are never part of release images. Static product/settings fixtures cannot service Livewire file-upload initialization requests; their expected update-endpoint 404 dialogs were suppressed only in the fixture harness. Upload interactions are not claimed as browser-verified. Real form saves, validation, contextual stock actions, tab switching and notification/security behavior are covered by Livewire feature tests. Canonical image verification passed after the visual corrections; publication/deployment remains the downstream release gate.
