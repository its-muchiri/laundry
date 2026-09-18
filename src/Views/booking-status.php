<?php
/** @var array|null $booking */
/** @var int $bookingId */
/** @var string|null $dbError */
use Laundry\Core\View;
?>
<h1>Booking #<?= $bookingId ?></h1>

<?php if ($dbError): ?>
  <p class="card__meta"><?= View::e($dbError) ?></p>
<?php elseif (!$booking): ?>
  <p class="card__meta">No booking found with this ID.</p>
<?php else: ?>
  <p class="card__meta">
    <?= View::e($booking['service_tier']) ?> · <?= View::e($booking['pickup_address']) ?>
  </p>
  <div id="status-badge-container" style="margin-top: var(--ac-space-2);"></div>
  <div id="status-timeline-container" style="max-width: 40rem; margin-top: var(--ac-space-4);"></div>

  <div id="payment-section" style="max-width: 28rem; margin-top: var(--ac-space-6);"></div>
  <div id="receipt-section" style="max-width: 28rem; margin-top: var(--ac-space-4);"></div>

  <script type="module">
    import { createStatusTimeline, LAUNDRY_BOOKING_STEPS } from "/assets/js/components/status-timeline.js";
    import { createStatusBadge } from "/assets/js/components/status-badge.js";

    const timelineContainer = document.getElementById("status-timeline-container");
    const badgeContainer = document.getElementById("status-badge-container");
    const paymentSection = document.getElementById("payment-section");
    const receiptSection = document.getElementById("receipt-section");
    const bookingId = <?= $bookingId ?>;
    const BADGE_TONE = {
      requested: "neutral", matched: "accent", picked_up: "accent", washing: "warning",
      ready: "accent", out_for_delivery: "accent", delivered: "success", cancelled: "danger", disputed: "danger",
    };

    function stepIndexFor(status) {
      const index = LAUNDRY_BOOKING_STEPS.findIndex((s) => s.toLowerCase().replace(/ /g, "_") === status);
      return index === -1 ? 0 : index;
    }

    function renderPaymentSection(booking) {
      const amount = booking.final_price ?? booking.estimated_price;

      if (booking.payment && booking.payment.status === "completed") {
        paymentSection.innerHTML = `<p class="card__meta">Paid via M-Pesa — KES ${Number(booking.payment.amount).toFixed(2)} (ref ${booking.payment.external_reference ?? "—"}).</p>`;
        return;
      }
      if (booking.payment && booking.payment.status === "pending") {
        paymentSection.innerHTML = `<p class="card__meta">M-Pesa prompt sent — check your phone and enter your PIN. This page updates automatically once it's confirmed.</p>`;
        return;
      }
      if (booking.payment_timing === "pay_on_delivery") {
        paymentSection.innerHTML = `<p class="card__meta">Pay on delivery — settle KES ${Number(amount).toFixed(2)} with the operator at drop-off.</p>`;
        return;
      }
      if (["cancelled", "delivered"].includes(booking.status)) {
        paymentSection.innerHTML = "";
        return;
      }

      paymentSection.innerHTML = `
        <form id="pay-form" style="display:flex; flex-direction:column; gap: var(--ac-space-2);">
          <label>
            M-Pesa phone number
            <input type="tel" name="phone" placeholder="07XXXXXXXX" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
          </label>
          <button type="submit" class="btn btn--primary">Pay KES ${Number(amount).toFixed(2)} with M-Pesa</button>
          <p id="pay-status" class="card__meta"></p>
        </form>
      `;

      document.getElementById("pay-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const payStatus = document.getElementById("pay-status");
        const phone = new FormData(event.target).get("phone");
        payStatus.textContent = "Sending M-Pesa prompt…";
        try {
          const res = await fetch("/api/v1/payments/mpesa/stk-push", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ booking_id: bookingId, phone }),
          });
          const data = await res.json();
          if (!res.ok) {
            payStatus.textContent = data.error || "Could not start payment.";
            return;
          }
          payStatus.textContent = data.customer_message || "Check your phone and enter your M-Pesa PIN.";
        } catch (e) {
          payStatus.textContent = "Network error: " + e.message;
        }
      });
    }

    function renderReceiptSection(booking) {
      if (booking.status !== "out_for_delivery") {
        receiptSection.innerHTML = "";
        return;
      }

      receiptSection.innerHTML = `
        <button type="button" class="btn btn--primary" id="confirm-receipt-btn">Confirm delivery received</button>
        <p id="receipt-status" class="card__meta" style="margin-top: var(--ac-space-2);"></p>
      `;
      document.getElementById("confirm-receipt-btn").addEventListener("click", async () => {
        const receiptStatus = document.getElementById("receipt-status");
        receiptStatus.textContent = "Confirming…";
        try {
          const res = await fetch(`/api/v1/bookings/${bookingId}/confirm-receipt`, { method: "POST" });
          const data = await res.json();
          if (!res.ok) {
            receiptStatus.textContent = data.error || "Could not confirm delivery.";
            return;
          }
          receiptStatus.textContent = "Delivery confirmed — thank you!";
          refresh();
        } catch (e) {
          receiptStatus.textContent = "Network error: " + e.message;
        }
      });
    }

    async function refresh() {
      try {
        const res = await fetch(`/api/v1/bookings/${bookingId}`);
        if (!res.ok) return;
        const booking = await res.json();

        badgeContainer.innerHTML = "";
        badgeContainer.appendChild(createStatusBadge({ label: booking.status.replace(/_/g, " "), tone: BADGE_TONE[booking.status] ?? "neutral" }));

        timelineContainer.innerHTML = "";
        timelineContainer.appendChild(createStatusTimeline({ steps: LAUNDRY_BOOKING_STEPS, currentIndex: stepIndexFor(booking.status) }));

        renderPaymentSection(booking);
        renderReceiptSection(booking);
      } catch {
        // Polling failure is non-fatal — just retry on the next interval.
      }
    }

    refresh();
    setInterval(refresh, 5000);
  </script>
<?php endif; ?>
