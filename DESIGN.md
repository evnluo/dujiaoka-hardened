# Shop admin design system

## Intent

Compact operations workspace for a small digital-goods shop. Use Filament core components for consistency, not its default generous spacing. Orders are the entry screen; no decorative dashboard. Preserve the public storefront independently.

## Visual rules

- System sans-serif; body/table text about 13px, secondary details 12px, section headings 15px, page heading 22px.
- White work surface, #f8f9fb chrome, #e3e5e9 separators, #525866 secondary text. Filament Blue for primary actions, Zinc neutrals; semantic badge colors indicate state.
- Small 6–10px radii, no ornamental shadows, no gradients or oversized cards.
- Full-width content with 24px desktop gutters and 13.5rem sidebar. Desktop page padding 20px; remove the nested default header padding to avoid doubled whitespace.
- Tables show order number/time, product/quantity, customer, amount, payment record and delivery separately. Numeric columns use tabular figures.
- Summary is an inline bordered strip, not KPI cards; render it synchronously to avoid a placeholder/layout shift. Tabs sit at the left of the working area.
- Destructive operations are explicit, confirmed, and domain-validated. Secrets never appear as prefilled form values. Downloads require password reconfirmation.

## Mobile

- 14px gutters, 44px minimum buttons/toggles, collapsible navigation.
- Tables and status tabs scroll inside their own containers. The page itself must not overflow.
- Forms stack actions on narrow screens; preserve legibility instead of shrinking all text.

## Implementation

`resources/css/filament/admin/theme.css` is the custom Tailwind/Filament theme, compiled with Vite. Panel configuration lives in `app/Providers/Filament/AdminPanelProvider.php`. Use normal Filament APIs before overriding view templates or internals.

## Verification evidence

Synthetic server-rendered orders were inspected in the browser at desktop width and inside a 390px viewport. Corrected excessive inherited header/schema spacing and lazy summary placeholder. Final desktop layout had readable records and no overlap. Mobile page measured 388px client/scroll width; tabs and table independently scrolled. UI fixture is separate from the release image and contains no production records. Actual component actions/login are covered by Livewire feature tests, not inferred from a static screenshot.
