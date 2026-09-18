# MVP Status — Laundry (laundry.co.ke)

STATE: IN_PROGRESS

## Planning references
- Product spec: ../planning/01-laundry-co-ke/prd.md
- Data model: ../planning/01-laundry-co-ke/database-schema.md
- API contract: ../planning/01-laundry-co-ke/api-endpoints.md
- User flows: ../planning/01-laundry-co-ke/user-flows.md
- Open questions (stakeholder-pending decisions): ../planning/01-laundry-co-ke/open-questions.md
- Shared modules (auth, payments/escrow, booking engine, KYC, reviews): ../planning/00-portfolio/shared-architecture.md
- Authoritative MVP feature cut & build order: ../planning/00-portfolio/build-sequencing-roadmap.md

## What "MVP complete" means for this project
Per build-sequencing-roadmap.md, laundry.co.ke is first in the build order (lowest risk, simplest booking model — the proving ground for the shared platform core). Its MVP is: customer booking with slot selection, single-operator matching, M-Pesa payment, a basic e-commerce store (detergent/supplies), and review submission — built on shared modules 1–4 (identity/auth, payments core, booking engine v1, escrow & commission engine v1). Trust tier is mostly Tier 1/2 (see shared-architecture.md's trust-tiering table). Subscriptions, loyalty points, and the operator earnings dashboard are V2 — don't block DONE on them even though controllers for some already exist.

- Customer can sign up / log in
- Customer can book a pickup: address, date/time slot, garment count/type, service tier (wash & fold / dry clean / express)
- System matches the booking to the nearest available operator by service area + capacity (see `machine_capacity` in database-schema.md)
- Customer pays via M-Pesa STK Push at booking confirmation; operator is paid out via escrow after delivery confirmation, per shared-architecture.md's Escrow & Commission Engine and M-Pesa callback idempotency requirements
- Customer sees a live order status timeline (Requested → Picked Up → Washing → Ready → Out for Delivery → Delivered)
- Customer can submit a review after delivery
- Customer can buy detergent/supplies/packaging from the store (see prd.md's E-Commerce Store Scope)
- Admin/dispatcher can view all bookings and override status
- Every image slot (homepage hero, service/how-it-works imagery, store product photos for detergent/supplies) shows a real, topically relevant photo sourced from Unsplash — not a placeholder box or broken image
- App builds and runs with zero errors, works on mobile width
- Core flow (sign up → book → pay → track → review) covered by a smoke test

## Checklist
Controllers already exist for most of this (src/Controllers/*) — verify against the spec above and the planning docs rather than assuming they're complete, and rather than rebuilding from scratch.

- [x] Identity/auth: signup, login, session (verify OnboardingController.php)
- [ ] Booking creation + operator matching by area/capacity (verify BookingController.php, Models/LaundryBooking.php against database-schema.md)
- [ ] M-Pesa payment + escrow/commission release to operator (verify PaymentController.php against shared-architecture.md's M-Pesa Daraja + idempotency section)
- [ ] Order status timeline with live updates (SSE or polling per shared-architecture.md's real-time strategy)
- [ ] Review submission (verify ReviewController.php)
- [ ] Store: browse/purchase detergent & supplies (verify StoreController.php against prd.md's E-Commerce Store Scope)
- [ ] Admin/dispatcher booking list + status override (verify OperatorController.php)
- [ ] Dispute raise/triage path for damaged/lost garments (verify DisputeController.php) — this is prd.md's stated core risk for this platform
- [ ] Error handling for the core flow (bad address, no operator available, failed payment)
- [ ] Real Unsplash photography (verified resolving URLs) for hero/service imagery on home.php and product photos in the store — fabric/laundromat/pickup-delivery subject matter, no placeholders or broken images
- [ ] Smoke test / manual run-through of the full core flow passes
- [ ] Remove stubs, TODOs, placeholder data
- [ ] Cross-check against open-questions.md — where it conflicts with an assumption made here, note the assumption taken and continue (don't stop to ask)

Not MVP per the roadmap — don't block DONE on these even if partially built (SubscriptionController.php and LoyaltyController.php already exist): subscription plans, loyalty points, operator earnings dashboard, multi-operator marketplace, programmatic SEO pages.

## Known issues / open questions
- Commission rate, pay-on-delivery toggle, and mutual (customer↔operator) review support are explicitly unresolved in open-questions.md — needs stakeholder input, not an engineering blocker. Pick a reasonable default, log it here, and continue.
- **Concurrent edits observed on disk this pass**: while working, `src/Controllers/OperatorController.php` and several other controllers (PaymentController, PageController, LoyaltyController, SubscriptionController) changed on disk mid-session in ways this pass didn't make — mostly SQL double-quote→single-quote normalization (MySQL accepts `"x"` as a string literal; Postgres treats it as an identifier), plus a `Database::driver() === 'pgsql'` branch added to `OperatorController::prefer()`. This strongly suggests another process/session is concurrently migrating the DB layer toward Postgres (consistent with Vercel Marketplace no longer offering a managed MySQL — see this session's Vercel knowledge-update notes), but `src/Config/Database.php` itself was never actually updated with Postgres DSN/driver-selection logic, so `Database::driver()` was undefined and any call to `/api/v1/operators/{id}/prefer` would fatal-error. Added a minimal `Database::driver()` stub (always returns `'mysql'` — matches the DSN, which is still hardcoded `mysql:`) so that route doesn't crash, without attempting to design the Postgres migration myself mid-flight on someone else's in-progress work. **If a future pass sees this repo still MySQL-only with scattered `Database::driver() === 'pgsql'` branches, either finish the Postgres migration properly (DSN switch + a real driver-detection implementation) or strip the dead branches — don't leave it half-done.**
- Booking creation (`POST /api/v1/bookings`) doesn't accept ISO 8601 datetimes with a `Z` suffix (`2026-09-18T09:00:00Z`) — MySQL rejects them; only `Y-m-d H:i:s` works today. `public/assets/js/pages/checkout.js`/`book.php`'s inline script should either normalize on the client or BookingController::create should parse/reformat server-side. Filed here rather than fixed this pass since it falls under the still-open "Error handling for the core flow" checklist item.
- `laundry_bookings.pickup_lat`/`pickup_lng` are `NOT NULL` but nothing in the booking form UI collects them yet (book.php only has a free-text address field) — geocoding the address (or a map picker) is needed before booking creation is usable end-to-end from the actual page, not just via direct API calls. Also part of the still-open error-handling / booking-creation checklist items.

## Changelog
- 2026-09-17: Initial checklist created (assumed generic scope, not sourced from planning/)
- 2026-09-18: Rewritten against planning/01-laundry-co-ke and shared-architecture.md; checklist now reflects the roadmap's authoritative MVP cut and points at existing controllers to verify rather than assuming a blank slate
- 2026-09-17: Built the identity/auth module end-to-end — this was the actual blocker for every other checklist item (checkout.js's own comments confirmed `customer_id` always resolved to null because no auth middleware existed). Added `src/Core/Auth.php` (session-cookie auth: `attempt`/`login`/`logout`/`currentUser`/`requireUser` guard), `src/Controllers/AuthController.php` (signup/login/logout, both server-rendered forms at `/signup` `/login` `/logout` and JSON at `/api/v1/auth/*`), `src/Views/signup.php` + `login.php`, and wired `Auth::requireUser($request)` guards into every controller action that needs a logged-in user (BookingController::create/index/confirmReceipt/cancel, ReviewController::store, DisputeController::store/resolve, StoreController::createOrder, OnboardingController::submit, OperatorController::setCapacity/getCapacity/setServiceArea/prefer). `public/index.php` now starts the session and attaches `$request->user` before dispatch; `layout.php` nav shows login state. Customers get `status = 'active'` immediately at signup (Tier 1 trust per shared-architecture.md); operators stay `pending_verification` until KYC (OnboardingController, unchanged). Along the way fixed two real bugs surfaced by testing against a live local MariaDB: (1) `Request::fromGlobals()` only ever parsed JSON bodies, so native HTML form POSTs (my new signup/login forms) always saw an empty body — now falls back to `$_POST` for non-JSON content types; (2) `Auth::attempt()`'s login-lookup query reused the same named placeholder (`:identifier`) twice, which real (non-emulated) MySQL prepared statements reject (`SQLSTATE[HY093]`) — split into `:phone`/`:email`. Verified the full signup → login → session-cookie → authenticated booking-creation (`customer_id` correctly resolved) → logout flow against a live local MariaDB with `php -S`, not just `php -l`.
