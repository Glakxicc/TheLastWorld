// --- Import ---
import { api, API_BASE } from "./modules/api.js";
import { toast } from "./modules/toast.js";

// --- Variable ---
const form = document.querySelector("#whitelist-form");
const submitBtn = document.querySelector("#send-form");
const toggles = document.querySelectorAll(".toggle");
const loginLink = document.querySelector("#login");

const LOGIN_MESSAGES = {
  "non-whitelist": "Ce compte Discord n'est pas encore whitelisté par le staff.",
  annulee: "Connexion annulée.",
  erreur: "La connexion avec Discord a échoué, réessayez.",
  "erreur-session": "Session expirée pendant la connexion : autorisez les cookies puis réessayez.",
  "erreur-discord": "Discord a refusé la connexion. Prévenez le staff si cela continue.",
  "erreur-serveur": "Le site rencontre un problème. Prévenez le staff si cela continue.",
  indisponible: "La connexion n'est pas encore disponible.",
};

// --- Connexion ---
loginLink.href = `${API_BASE}/api/auth/login.php`;

const loginResult = new URLSearchParams(window.location.search).get("connexion");
if (LOGIN_MESSAGES[loginResult]) {
  toast(LOGIN_MESSAGES[loginResult], "error", 4000);
  history.replaceState(null, "", window.location.pathname);
}

api("me.php")
  .then(({ loggedIn }) => {
    if (loggedIn) {
      loginLink.textContent = "Mon espace";
      loginLink.href = "./dashboard.html";
    }
  })
  .catch(() => {});

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
  if (!/^\d{17,20}$/.test(payload.discord_id)) {
    toast("L'identifiant Discord doit contenir 17 à 20 chiffres.", "error", 4000);
    return;
  }

  submitBtn.disabled = true;
  try {
    await api("formulaire.php", { method: "POST", body: payload });
    form.reset();
    toast("Informations envoyées au staff sur le Discord.", "success");
  } catch (err) {
    toast(err.message, "error");
  } finally {
    submitBtn.disabled = false;
  }
});
