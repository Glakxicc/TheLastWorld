// --- Import ---
import { toast } from "./modules/toast.js";

// --- EventListener ---
document.querySelectorAll(".see-more").forEach((link) => {
  link.addEventListener("click", (event) => {
    event.preventDefault();
    toast("Fonctionnalité pas encore disponible.");
  });
});
