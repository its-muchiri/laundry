/**
 * Entry point — wires up whichever shared components a given page needs.
 * This scaffold ships the components; page-specific composition (which
 * components a given route uses, and API calls to routes/api.php) is left
 * for feature implementation, following the pattern in pages/checkout.js.
 */
import { onReducedMotionChange } from "./components/reduced-motion.js";

onReducedMotionChange((reduced) => {
  document.documentElement.dataset.reducedMotion = String(reduced);
});
