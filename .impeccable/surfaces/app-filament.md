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

FORM: User-pinned compact operations interface, approved before implementation. Signature interaction: filtered records lead into a detailed order view without losing list context. Motion only signals state.

FINISH: Bounded desktop/mobile inspection, fix material issues, document the shipped system in DESIGN.md. No repeated polish loops.
