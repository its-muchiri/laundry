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

  <script type="module">
    import { createStatusTimeline, LAUNDRY_BOOKING_STEPS } from "/assets/js/components/status-timeline.js";
    import { createStatusBadge } from "/assets/js/components/status-badge.js";

    const timelineContainer = document.getElementById("status-timeline-container");
    const badgeContainer = document.getElementById("status-badge-container");
    const bookingId = <?= $bookingId ?>;
    const BADGE_TONE = {
      requested: "neutral", matched: "accent", picked_up: "accent", washing: "warning",
      ready: "accent", out_for_delivery: "accent", delivered: "success", cancelled: "danger", disputed: "danger",
    };

    function stepIndexFor(status) {
      const index = LAUNDRY_BOOKING_STEPS.findIndex((s) => s.toLowerCase().replace(/ /g, "_") === status);
      return index === -1 ? 0 : index;
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
      } catch {
        // Polling failure is non-fatal — just retry on the next interval.
      }
    }

    refresh();
    setInterval(refresh, 5000);
  </script>
<?php endif; ?>
