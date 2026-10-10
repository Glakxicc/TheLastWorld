// Partie « Mon compte Minecraft », statistiques et « À compléter » du bloc Serveur.

// --- Import ---
import { formatDate } from "./posts.js";

const numberFormat = new Intl.NumberFormat("fr-FR");

export function renderMinecraft(player) {
  const { minecraft, stats } = player;

  const head = document.querySelector("#mc-head");
  const name = document.querySelector("#mc-name");
  const online = document.querySelector("#mc-online");
  const hint = document.querySelector("#stats-hint");

  name.textContent = minecraft.username || "Non renseigné";
  head.hidden = !minecraft.uuid;
  if (minecraft.uuid) {
    head.src = `https://mc-heads.net/avatar/${minecraft.uuid}/64`;
  }

  online.hidden = !stats;
  if (stats) {
    online.textContent = stats.online ? "🟢 En jeu" : "⚫ Hors ligne";
  }

  setStat("#stat-playtime", stats && formatPlaytime(stats.playtimeSeconds));
  setStat("#stat-last-seen", stats && (stats.online ? "Maintenant" : stats.lastSeen && formatDate(stats.lastSeen)));
  setStat("#stat-deaths", stats && numberFormat.format(stats.deaths));
  setStat("#stat-mob-kills", stats && numberFormat.format(stats.mobKills));
  setStat("#stat-player-kills", stats && numberFormat.format(stats.playerKills));

  hint.textContent = !minecraft.username
    ? "Renseignez votre pseudo Minecraft pour voir vos statistiques."
    : stats
      ? `Mis à jour le ${formatDate(stats.updatedAt)}.`
      : `Pas encore de statistiques : connectez-vous au serveur avec ${minecraft.username}.`;

  const form = document.querySelector("#profile-form");
  form.elements.mc_username.value = minecraft.username;
  form.elements.skin_url.value = minecraft.skinUrl;

  renderChecklist(player);
}

export function renderChecklist(player) {
  const characterComplete = Object.values(player.character).every((value) => value !== "");
  setChecked("#check-minecraft", Boolean(player.minecraft.username));
  setChecked("#check-character", characterComplete);
  setChecked("#check-skin", Boolean(player.minecraft.skinUrl));
}

function setStat(selector, value) {
  document.querySelector(selector).textContent = value || "—";
}

function setChecked(selector, done) {
  document.querySelector(selector).classList.toggle("done", done);
}

export function formatPlaytime(seconds) {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  return hours ? `${numberFormat.format(hours)} h ${minutes} min` : `${minutes} min`;
}
