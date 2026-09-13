// --- Import ---
import { error415 } from "./modules/error.js";

// --- Function calling ---
const viewport = window.innerWidth;
if (viewport <= 768) {
  error415();
}
