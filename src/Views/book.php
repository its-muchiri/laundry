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
    <input type="text" name="pickup_address" required placeholder="e.g. Kilimani, Nairobi" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <div>
    <button type="button" class="btn btn--secondary" id="use-location-btn">Use my current location</button>
    <p id="location-status" class="card__meta" style="margin-top: var(--ac-space-2);">Location not set yet — required to match you to a nearby operator.</p>
  </div>

  <label>
    Service tier
    <select name="service_tier" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
      <option value="wash_fold">Wash & Fold</option>
      <option value="dry_clean">Dry Clean</option>
      <option value="express">Express</option>
    </select>
  </label>

  <label>
    Estimated number of garments
    <input type="number" name="estimated_item_count" min="1" value="5" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <label>
    Special instructions (optional)
    <textarea name="special_instructions" rows="2" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);"></textarea>
  </label>

  <div>
    <button type="button" class="btn btn--secondary" id="find-slots-btn" disabled>Find available slots</button>
    <div id="slot-picker-container" style="margin-top: var(--ac-space-3);"></div>
  </div>

  <button type="submit" class="btn btn--primary">Confirm booking</button>
</form>

<p id="booking-result" class="card__meta" style="margin-top: var(--ac-space-4);"></p>

<script type="module">
  import { createSlotPicker } from "/assets/js/components/booking-calendar.js";

  let selectedSlot = null; // { start: ISOString, end: ISOString }
  let pickupLat = null;
  let pickupLng = null;

  const locationStatus = document.getElementById("location-status");
  const findSlotsBtn = document.getElementById("find-slots-btn");

  document.getElementById("use-location-btn").addEventListener("click", () => {
    if (!navigator.geolocation) {
      locationStatus.textContent = "Your browser doesn't support geolocation — enter your address and try a different device, or contact support.";
      return;
    }
    locationStatus.textContent = "Requesting location…";
    navigator.geolocation.getCurrentPosition(
      (position) => {
        pickupLat = position.coords.latitude;
        pickupLng = position.coords.longitude;
        locationStatus.textContent = `Location set (${pickupLat.toFixed(4)}, ${pickupLng.toFixed(4)})`;
        findSlotsBtn.disabled = false;
      },
      (error) => {
        locationStatus.textContent = "Couldn't get your location: " + error.message + ". Allow location access and try again.";
      }
    );
  });

  findSlotsBtn.addEventListener("click", async () => {
    const container = document.getElementById("slot-picker-container");
    if (pickupLat === null || pickupLng === null) {
      container.textContent = "Set your location first.";
      return;
    }
    container.textContent = "Loading…";
    try {
      const res = await fetch(`/api/v1/availability/slots?lat=${pickupLat}&lng=${pickupLng}`);
      const data = await res.json();
      container.innerHTML = "";
      if (!res.ok) {
        container.textContent = "Error loading slots: " + (data.error || "unknown error");
        return;
      }
      if (!data.slots || data.slots.length === 0) {
        container.textContent = "No operators currently cover this address. Try a different time, or check back soon.";
        return;
      }
      container.appendChild(createSlotPicker({
        slots: data.slots,
        onSelect: (id) => {
          const [start, end] = id.split("|");
          selectedSlot = { start, end };
        },
      }));
    } catch (e) {
      container.textContent = "Network error: " + e.message;
    }
  });

  document.getElementById("booking-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const formData = new FormData(event.target);
    const resultEl = document.getElementById("booking-result");

    if (pickupLat === null || pickupLng === null) {
      resultEl.textContent = "Set your location before confirming.";
      return;
    }
    if (!selectedSlot) {
      resultEl.textContent = "Choose a pickup slot before confirming.";
      return;
    }

    try {
      const res = await fetch("/api/v1/bookings", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          pickup_address: formData.get("pickup_address"),
          pickup_lat: pickupLat,
          pickup_lng: pickupLng,
          service_tier: formData.get("service_tier"),
          estimated_item_count: Number(formData.get("estimated_item_count")) || 5,
          special_instructions: formData.get("special_instructions"),
          scheduled_pickup_slot_start: selectedSlot.start,
          scheduled_pickup_slot_end: selectedSlot.end,
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

      const statusNote = data.status === "matched"
        ? "matched to an operator"
        : (data.message || "saved, awaiting an available operator");
      resultEl.innerHTML = `Booking #${data.id} created (${statusNote}). <a href="/bookings/${data.id}">Track it</a>`;
    } catch (e) {
      resultEl.textContent = "Network error: " + e.message;
    }
  });
</script>
