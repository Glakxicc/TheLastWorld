// --- Import ---
import { api } from "./api.js";

// Affiche le statut choisi par le staff (/statut sur Discord) dans les éléments [data-server-status]
export async function showServerStatus() {
  const targets = document.querySelectorAll("[data-server-status]");
  if (!targets.length) return;

  try {
    const { status, label } = await api("status.php");
    targets.forEach((target) => {
      target.textContent = `Serveur ${label}`;
      target.dataset.serverStatus = status;
      target.hidden = false;
    });
  } catch {
    // Statut indisponible : on n'affiche rien
  }
}
