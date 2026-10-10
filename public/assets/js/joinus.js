// --- Import ---
import { toast } from "./modules/toast.js";

// --- Config ---
// Live Server ne sait pas exécuter le PHP : on passe alors par `php -S localhost:8000`
const API_URL =
  window.location.port === "5500"
    ? "http://localhost:8000/api/formulaire.php"
    : "/api/formulaire.php";

// --- Variable ---
const form = document.querySelector("#whitelist-form");
const submitBtn = document.querySelector("#send-form");
const toggles = document.querySelectorAll(".toggle");
const loginBtn = document.querySelector("#login");

// --- EventListener ---
toggles.forEach((button) => {
  button.addEventListener("click", () => {
    const enabled = button.getAttribute("aria-pressed") !== "true";
    button.setAttribute("aria-pressed", String(enabled));
    toast(
      enabled ? "Option activée." : "Option désactivée.",
      enabled ? "success" : "error",
      1300,
    );
  });
});

loginBtn.addEventListener("click", () => {
  toast("Fonctionnalité pas encore disponible.");
});

form.addEventListener("submit", async (event) => {
  event.preventDefault();

  const payload = Object.fromEntries(
    [...new FormData(form)].map(([key, value]) => [key, value.trim()]),
  );
  toggles.forEach((button) => {
    payload[button.dataset.field] = button.getAttribute("aria-pressed") === "true";
  });

  const missing = [...form.elements].some(
    (input) => input.required && !input.value.trim(),
  );
  if (missing) {
    toast("Vous devez remplir tout le formulaire.", "error");
    return;
  }

  submitBtn.disabled = true;
  try {
    const response = await fetch(API_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
      throw new Error(data.error || "Erreur serveur.");
    }

    form.reset();
    toast("Informations envoyées au staff sur le Discord.", "success");
  } catch (err) {
    const message =
      err instanceof TypeError ? "Impossible de contacter le serveur." : err.message;
    toast(message, "error");
  } finally {
    submitBtn.disabled = false;
  }
});
