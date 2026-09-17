# Tests

No test runner is wired up yet in this scaffold. Recommended starting point once real business logic lands (matching, pricing recalculation, escrow release, M-Pesa callback idempotency): PHPUnit for `src/`, and a small assertion-based runner (or Vitest via a Node dev-dependency) for the vanilla JS components in `public/assets/js/`.

Priority areas to test first, mirroring artcollect.co.ke's own verification checklist (artcollect-design-system.md references `docs/11_maximalist_redesign_plan.md`'s "logical errors" table):

- `machine_capacity` matching logic once implemented (capacity at zero, exact-boundary cases)
- M-Pesa callback idempotency (`payment_callbacks_log` uniqueness on `checkout_request_id`)
- Price recalculation when `actual_weight_kg` differs from the estimate
- `ANNOTATION_TONES` contrast pairings in `scrap.js`, if this platform extends that list beyond what's pre-verified in artcollect.co.ke's own contrast test
