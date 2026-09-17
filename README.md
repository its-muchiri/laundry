# laundry.co.ke

Scaffold for the laundry.co.ke marketplace platform. See `/planning/01-laundry-co-ke/` for the full PRD, user flows, database schema, API spec, and open questions this scaffold implements a starting skeleton of.

## Stack
PHP (no framework, PSR-4 autoloaded) + vanilla JS/CSS + relational SQL (MySQL/MariaDB), per `/planning/00-portfolio/shared-architecture.md`.

## Design system
Tokens in `public/assets/css/tokens.css` are ported from the real artcollect.co.ke system documented in `/planning/00-portfolio/artcollect-design-system.md`, with the **platform accent set to cobalt** (`--ac-cobalt`, blue) — this platform's assigned lane, chosen for its "clean/trust" association. Only the token architecture, the collage/pixel decorative primitives, and the motion/accessibility governance rules are adopted; the heavier graffiti and 3D/diorama treatments from artcollect are intentionally **not** ported here (see `artcollect-design-system.md` §10).

**Critical-flow rule (enforced, not just documented):** `public/assets/js/pages/checkout.js` and any booking-confirmation/payment/dispute page must never import `components/scrap.js` or any decorative module. See §7 of `artcollect-design-system.md` — payment and dispute screens carry zero decorative motion or heavy visual treatment.

## Structure

```
public/                 Web root — front controller, static assets
  index.php             Front controller: bootstraps Router, dispatches request
  assets/css/           tokens.css, reset.css, main.css
  assets/js/            main.js, components/, pages/
src/
  Config/               Database connection (PDO)
  Core/                 Router, Request, Response
  Controllers/          One controller per resource group (bookings, payments, reviews, disputes)
  Models/               Data-access classes
  Modules/              Placeholder for shared-module integration points (Escrow, KYC, Booking Engine —
                         see shared-architecture.md; in the "platform core library" deployment model these
                         would be `require`/autoloaded from a shared package rather than reimplemented here)
database/
  schema.sql            Shared core tables + this platform's extension tables
routes/
  api.php               Route table — mirrors planning/01-laundry-co-ke/api-endpoints.md
```

## Getting started

1. Copy `.env.example` to `.env` and fill in database + M-Pesa Daraja credentials.
2. Create the database and run `database/schema.sql` against it.
3. Point your web server's document root at `public/`, with all requests rewritten to `public/index.php` (see `public/.htaccess` for Apache; use an equivalent rewrite rule for nginx).
4. `composer install` if/when shared-module packages are added as dependencies (none required yet — this scaffold has zero third-party dependencies by design, consistent with the "framework-free" stack decision).

## What this scaffold is (and isn't)

This is a **starting skeleton**, not a finished implementation: the controllers contain the request/response wiring and the shape of each endpoint, not full business logic (matching algorithms, escrow release timing, M-Pesa signature verification, etc.). Every stub references the planning doc section that specifies what it should eventually do.

**Implemented in this scaffold:** bookings, availability slots, payments (stub), reviews, disputes, operator KYC onboarding, and the e-commerce store (`StoreController` — includes real transactional stock-decrement logic, not a stub). **Not yet wired:** subscriptions, loyalty points, and preferred-operator endpoints from `/planning/01-laundry-co-ke/api-endpoints.md` — add them following the same controller/route pattern already established.
