/**
 * Shared reduced-motion check — every animated component in this codebase
 * should call this once, centrally, rather than re-implementing the media
 * query check per component. Ported from artcollect.co.ke's
 * usePrefersReducedMotion hook (see artcollect-design-system.md §4/§7) as a
 * plain function, since this stack has no framework/hook system.
 */
export function prefersReducedMotion() {
  return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

/**
 * Registers a callback that re-fires whenever the user's reduced-motion
 * preference changes at the OS level (not just at page load).
 * @param {(reduced: boolean) => void} callback
 */
export function onReducedMotionChange(callback) {
  const mql = window.matchMedia("(prefers-reduced-motion: reduce)");
  callback(mql.matches);
  mql.addEventListener("change", (event) => callback(event.matches));
}
