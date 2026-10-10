// --- Import ---
import { showDesktopOnlyNotice } from "./modules/desktop-only.js";

// --- Init ---
if (window.matchMedia("(max-width: 768px)").matches) {
  showDesktopOnlyNotice();
}
