/**
 * Status Timeline — shared component. Step labels are platform-specific
 * (see design-system.md); this platform's lifecycle is defined in
 * planning/01-laundry-co-ke/database-schema.md's laundry_bookings.status enum.
 *
 * @param {{ steps: string[], currentIndex: number }} props
 * @returns {HTMLElement}
 */
export function createStatusTimeline({ steps, currentIndex }) {
  const list = document.createElement("ol");
  list.className = "status-timeline";

  steps.forEach((label, index) => {
    const item = document.createElement("li");
    item.className = "status-timeline__step" + (index <= currentIndex ? " status-timeline__step--done" : "");
    item.textContent = label;
    list.appendChild(item);
  });

  return list;
}

export const LAUNDRY_BOOKING_STEPS = [
  "Requested",
  "Matched",
  "Picked up",
  "Washing",
  "Ready",
  "Out for delivery",
  "Delivered",
];
