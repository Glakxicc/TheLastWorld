// --- Import ---
import { showDesktopOnlyNotice } from "./modules/desktop-only.js";
import { showServerStatus } from "./modules/server-status.js";

// --- Init ---
if (window.matchMedia("(max-width: 768px)").matches) {
  showDesktopOnlyNotice();
} else {
  showServerStatus();
}
