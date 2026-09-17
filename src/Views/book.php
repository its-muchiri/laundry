<?php /** @var array|null $currentUser */ ?>
<h1>Book a pickup</h1>

<?php if (empty($currentUser)): ?>
  <p class="card__meta">
    <a href="/login">Log in</a> or <a href="/signup">sign up</a> first to book a pickup.
  </p>
<?php endif; ?>

<form id="booking-form" style="max-width: 32rem; display:flex; flex-direction:column; gap: var(--ac-space-4);">
  <label>
    Pickup address
    <input type="text" name="pickup_address" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <label>
    Service tier
    <select name="service_tier" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
      <option value="wash_fold">Wash & Fold</option>
      <option value="dry_clean">Dry Clean</option>
      <option value="express">Express</option>
    </select>
  </label>

  <div>
    <button type="button" class="btn btn--secondary" id="find-slots-btn">Find available slots</button>
    <div id="slot-picker-container" style="margin-top: var(--ac-space-3);"></div>
  </div>

  <button type="submit" class="btn btn--primary">Confirm booking</button>
</form>

<p id="booking-result" class="card__meta" style="margin-top: var(--ac-space-4);"></p>

<script type="module">
  import { createSlotPicker } from "/assets/js/components/booking-calendar.js";

  let selectedSlot = null;

  document.getElementById("find-slots-btn").addEventListener("click", async () => {
    const container = document.getElementById("slot-picker-container");
    container.textContent = "Loading…";
    try {
      const res = await fetch("/api/v1/availability/slots");
      const data = await res.json();
      container.innerHTML = "";
      if (!data.slots || data.slots.length === 0) {
        // Honest reflection of the current backend state — see
        // BookingController::availableSlots()'s TODO: the machine_capacity
        // matching query isn't implemented yet, so this is always empty
        // against a real database right now.
        container.textContent = "No slots available — availability matching isn't implemented yet (see src/Controllers/BookingController.php::availableSlots).";
        return;
      }
      container.appendChild(createSlotPicker({ slots: data.slots, onSelect: (id) => { selectedSlot = id; } }));
    } catch (e) {
      container.textContent = "Error loading slots: " + e.message;
    }
  });

  document.getElementById("booking-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const formData = new FormData(event.target);
    const resultEl = document.getElementById("booking-result");

    try {
      const res = await fetch("/api/v1/bookings", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          pickup_address: formData.get("pickup_address"),
          service_tier: formData.get("service_tier"),
          scheduled_pickup_slot_start: new Date().toISOString(),
          scheduled_pickup_slot_end: new Date(Date.now() + 3600_000).toISOString(),
        }),
      });
      const data = await res.json();

      if (res.status === 401) {
        resultEl.innerHTML = 'You need to <a href="/login">log in</a> first to book a pickup.';
        return;
      }

      if (!res.ok) {
        resultEl.textContent = "Booking failed: " + (data.error || "unknown error");
        return;
      }

      resultEl.innerHTML = `Booking #${data.id} created (status: ${data.status}). <a href="/bookings/${data.id}">Track it</a>`;
    } catch (e) {
      resultEl.textContent = "Network error: " + e.message;
    }
  });
</script>
