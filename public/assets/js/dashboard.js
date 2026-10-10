// --- Import ---
import { api } from "./modules/api.js";
import { renderChecklist, renderMinecraft } from "./modules/minecraft-card.js";
import { formatDate, renderPosts } from "./modules/posts.js";
import { initStaffPanel } from "./modules/staff-panel.js";
import { toast } from "./modules/toast.js";

// --- Variable ---
const avatar = document.querySelector("#player-avatar");
const displayName = document.querySelector("#player-name");
const username = document.querySelector("#player-username");
const rankBadge = document.querySelector("#player-rank");
const whitelistInfo = document.querySelector("#whitelist-info");
const logoutBtn = document.querySelector("#logout");
const characterForm = document.querySelector("#character-form");
const saveBtn = document.querySelector("#save-character");
const profileForm = document.querySelector("#profile-form");
const saveProfileBtn = document.querySelector("#save-profile");
const serverAddress = document.querySelector("#server-address");
const copyAddressBtn = document.querySelector("#copy-address");
const newsList = document.querySelector("#members-news");
const staffPanel = document.querySelector("#staff-panel");

// --- Init ---
init();

async function init() {
  let me;
  try {
    me = await api("me.php");
  } catch (err) {
    toast(err.message, "error");
    return;
  }

  if (!me.loggedIn) {
    window.location.replace("./joinus.html");
    return;
  }

  renderPlayer(me.player);
  renderRank(me.rank);
  document.body.classList.remove("loading");
  loadServerInfo();
  loadNews();
  if (me.isStaff) {
    initStaffPanel(staffPanel);
  }
}

function renderPlayer(player) {
  displayName.textContent = player.displayName;
  username.textContent = `@${player.username}`;
  if (player.avatarUrl) {
    avatar.src = player.avatarUrl;
    avatar.hidden = false;
  }
  whitelistInfo.textContent = player.whitelistedAt
    ? `✅ Whitelisté depuis le ${formatDate(player.whitelistedAt)}`
    : "✅ Whitelisté";

  for (const [key, value] of Object.entries(player.character)) {
    if (characterForm.elements[key]) {
      characterForm.elements[key].value = value;
    }
  }
  renderMinecraft(player);
}

/** Rôle le plus haut du joueur sur le serveur Discord, dans la couleur du rôle. */
function renderRank(rank) {
  rankBadge.hidden = !rank;
  if (!rank) return;
  rankBadge.textContent = rank.name;
  if (rank.color) {
    rankBadge.style.setProperty("--rank-color", rank.color);
  }
}

async function loadServerInfo() {
  try {
    const { address } = await api("status.php");
    serverAddress.textContent = address || "Bientôt disponible";
    copyAddressBtn.hidden = !address;
  } catch {
    serverAddress.textContent = "Indisponible";
  }
}

async function loadNews() {
  try {
    const { posts } = await api("posts.php?categories=membres");
    if (posts.length) {
      renderPosts(newsList, posts);
    }
  } catch {
    // On garde le message par défaut
  }
}

// --- EventListener ---
copyAddressBtn.addEventListener("click", async () => {
  try {
    await navigator.clipboard.writeText(serverAddress.textContent);
    toast("Adresse copiée.", "success", 1300);
  } catch {
    toast("Impossible de copier l'adresse.", "error");
  }
});

logoutBtn.addEventListener("click", async () => {
  try {
    await api("auth/logout.php", { method: "POST", body: {} });
  } finally {
    window.location.href = "./joinus.html";
  }
});

characterForm.addEventListener("submit", async (event) => {
  event.preventDefault();

  const payload = Object.fromEntries(
    [...new FormData(characterForm)].map(([key, value]) => [key, value.trim()]),
  );
  if (Object.values(payload).some((value) => !value)) {
    toast("Tous les champs de la fiche sont requis.", "error");
    return;
  }

  saveBtn.disabled = true;
  try {
    const { player } = await api("character.php", { method: "POST", body: payload });
    renderChecklist(player);
    toast("Fiche personnage enregistrée.", "success");
  } catch (err) {
    if (err.status === 401) {
      window.location.replace("./joinus.html");
      return;
    }
    toast(err.message, "error");
  } finally {
    saveBtn.disabled = false;
  }
});

profileForm.addEventListener("submit", async (event) => {
  event.preventDefault();

  const payload = {
    mc_username: profileForm.elements.mc_username.value.trim(),
    skin_url: profileForm.elements.skin_url.value.trim(),
  };

  saveProfileBtn.disabled = true;
  try {
    const { player } = await api("profile.php", { method: "POST", body: payload });
    renderMinecraft(player);
    toast("Informations enregistrées.", "success");
  } catch (err) {
    if (err.status === 401) {
      window.location.replace("./joinus.html");
      return;
    }
    toast(err.message, "error");
  } finally {
    saveProfileBtn.disabled = false;
  }
});
