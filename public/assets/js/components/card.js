/**
 * Card — shared component per planning/00-portfolio/design-system.md.
 * A plain factory function returning a DOM node, since this stack has no
 * component framework. Meta fields are platform-specific (see
 * design-system.md's per-platform skinning notes) — pass whatever fields
 * this platform's card needs via `meta`.
 *
 * @param {{ title: string, imageUrl?: string, meta: string[], onSelect?: () => void }} props
 * @returns {HTMLElement}
 */
export function createCard({ title, imageUrl, meta = [], onSelect }) {
  const card = document.createElement("article");
  card.className = "card";

  if (imageUrl) {
    const img = document.createElement("img");
    img.src = imageUrl;
    img.alt = ""; // decorative by default — pass real alt text via a dedicated prop if the image is meaningful
    card.appendChild(img);
  }

  const heading = document.createElement("h3");
  heading.textContent = title;
  card.appendChild(heading);

  if (meta.length) {
    const metaRow = document.createElement("div");
    metaRow.className = "card__meta";
    metaRow.textContent = meta.join(" · ");
    card.appendChild(metaRow);
  }

  if (onSelect) {
    card.setAttribute("role", "button");
    card.setAttribute("tabindex", "0");
    card.addEventListener("click", onSelect);
    card.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        onSelect();
      }
    });
  }

  return card;
}
