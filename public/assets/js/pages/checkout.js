/**
 * Checkout / payment confirmation page logic.
 *
 * ENFORCED RULE (per artcollect-design-system.md §7, "structurally locked
 * out of decoration" — and shared-architecture.md's trust-tiering
 * discussion): this file, and any booking-confirmation, escrow-release, or
 * dispute-resolution page, must NEVER import components/scrap.js or any
 * other decorative module. Only plain, calm UI components (card, modal,
 * status-timeline, plain buttons) are permitted here.
 *
 * If a future PR imports a decorative module into a file under pages/
 * that handles payment/escrow/dispute, that is a direct violation of this
 * platform's adopted design governance — reject it in review rather than
 * adding a lighter version of the decoration.
 */
import { createStatusTimeline, LAUNDRY_BOOKING_STEPS } from "../components/status-timeline.js";

// import { createTornEdge } from "../components/scrap.js"; // <- NEVER do this here.

export function renderCheckoutStatus(container, currentIndex) {
  container.classList.add("critical-flow");
  container.appendChild(createStatusTimeline({ steps: LAUNDRY_BOOKING_STEPS, currentIndex }));
}
