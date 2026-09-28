# Evan's Shop

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Evan manages a small digital-goods shop. Customers browse products, pay, receive purchased goods, and look up orders.

## Product Purpose

Sell digital goods with reliable payment verification and stock delivery; make shop administration direct and understandable.

## Operating Context

The shop is low-volume and not mission-critical (explicit user statement). The live site is shop.oknice.ca. The application retains the Dujiaoka storefront, database, inventory and order history, with a supported Laravel/Filament administration panel. It runs in Docker with MariaDB and Redis; shop settings are stored durably in the database.

## Capabilities and Constraints

Dcat has been replaced by the Filament core suite on supported PHP/Laravel. Broad internal refactoring is approved; old admin UX and code compatibility are not requirements. Preserve existing products, card stock, orders and settings. Payment/stock safety and data privacy remain requirements. Publish source-only images; never include production secrets or records. Implementation and live release are separate gates: a local UI commit is not deployment evidence.

## Brand Commitments

Retain Evan's Shop identity. User-approved admin direction: grounded, simple, compact, informative and detailed. Reject excess dead space and decorative demo-dashboard styling. User explicitly requested Impeccable guidance.

## Evidence on Hand

Existing app/Models, app/Service, app/Filament, public assets and resources/views establish current functionality. tests/security.php and tests/database-security.php cover hardened payment and transaction invariants. Development fixtures must be synthetic.

## Product Principles

- Prioritize daily operations over decorative dashboards.
- Keep related information visible and clearly distinguish payment from delivery.
- Organize product content, pricing, fulfillment and publishing around separate decisions, not arbitrary equal-column grids. Compactness serves that hierarchy, not the reverse.
- Authorized administrators can view and edit sold/looping card content without changing sales or historical delivery. Manual completion notifications are explicitly opt-in, default off.
- Use one cohesive application rather than separate frontend/backend services.
- Prefer proportionate verification and a bounded visual pass; preserve strict payment and inventory safeguards.
