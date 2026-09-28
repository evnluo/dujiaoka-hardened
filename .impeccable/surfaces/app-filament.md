---
version: 1
slug: "app-filament"
primary_target: "app/Filament"
related_targets: ["resources/css/filament","app/Providers/Filament/AdminPanelProvider.php"]
---

# Admin surface

Mode: Operate. Evan manages products, card stock, orders, payments and shop settings. Desktop-first density; mobile controls remain comfortably usable. No old Dcat visual or code compatibility required. User explicitly approved the design direction and requested implementation; no unresolved visual-choice interview remains.

## Direction contract

THESIS: A compact shop workbench, not a dashboard showcase. Useful records take priority over decorative summaries.

OWN-WORLD: Neutral surfaces, thin borders, modest radii, one legible system sans, blue action accent, semantic status colors. No large shadows, gradients or hero cards.

STORY: Find a record, understand payment and delivery state, perform the next safe action.

FIRST VIEWPORT: Compact sidebar and short toolbar; orders table fills the remaining width. Search, status filters and contextual actions stay close to the records.

FORM: Content → pricing → fulfillment is the product-editing path; publishing/category/order controls occupy a narrow supporting rail, stacked after the main work on smaller screens. Pair prices because they are compared; bound short numeric inputs. Keep customer-input requirements with fulfillment and integration metadata behind a disclosure. Product stock facts and contextual actions replace editable-looking stock snapshots. Ordinary Save/Cancel remain at the form end. Motion only signals state; no added decorative animation.

DETAIL: Payment and delivery are separate decisions, with amounts in a vertical breakdown and secondary record metadata disclosed afterward. Queue counts sit on status tabs. SMTP testing is inside the Mail context. Inventory content remains directly visible/editable for authorized operators, including sold and looping cards; manual completion notification stays default-off.

BOUNDARIES: Preserve storefront/payment contracts, stock concurrency rules, protected configuration/order fields and all product inputs/validation. This refinement preserves the visual identity rather than replacing it. Static synthetic renders prove geometry, not Livewire server actions; feature tests cover those. Production publication/deployment is a separate downstream card.

FINISH: Bounded desktop/mobile inspection, fix material issues, document the shipped system in DESIGN.md. No repeated polish loops.
