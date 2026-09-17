/**
 * Booking Calendar / Slot Picker — shared component (single-slot mode for
 * laundry.co.ke; see design-system.md for the date-range mode used by
 * construction.co.ke/event.co.ke/solar.co.ke's site-survey booking).
 *
 * @param {{ slots: { id: string, label: string, available: boolean }[], onSelect: (slotId: string) => void }} props
 * @returns {HTMLElement}
 */
export function createSlotPicker({ slots, onSelect }) {
  const grid = document.createElement("div");
  grid.className = "slot-picker";
  grid.setAttribute("role", "listbox");
  grid.setAttribute("aria-label", "Available pickup slots");

  let selectedId = null;

  slots.forEach((slot) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "slot-picker__slot";
    button.textContent = slot.label;
    button.setAttribute("role", "option");
    button.setAttribute("aria-disabled", String(!slot.available));
    button.setAttribute("aria-selected", "false");
    button.disabled = !slot.available;

    button.addEventListener("click", () => {
      grid.querySelectorAll('[aria-selected="true"]').forEach((el) => el.setAttribute("aria-selected", "false"));
      button.setAttribute("aria-selected", "true");
      selectedId = slot.id;
      onSelect(selectedId);
    });

    grid.appendChild(button);
  });

  return grid;
}
