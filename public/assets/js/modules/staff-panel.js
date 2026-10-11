// Panneau staff de « Mon espace » : les mêmes actions que le bot Discord.

// --- Import ---
import { api } from "./api.js";
import { formatPlaytime } from "./minecraft-card.js";
import { formatDate } from "./posts.js";
import { toast } from "./toast.js";

const APPLICATION_FIELDS = {
  discord: "Discord",
  discord_id: "Identifiant Discord",
  age_irl: "Âge IRL",
  first_name: "Prénom",
  last_name: "Nom",
  age_character: "Âge RP",
  rp_born: "Lieu de naissance",
  rp_experience: "Expérience RP",
  rp_story: "Histoire",
};

const numberFormat = new Intl.NumberFormat("fr-FR");

let panel;
let state;
// Tri du tableau des statistiques : colonne et sens (-1 = décroissant)
let statsSort = { key: "playtimeSeconds", direction: -1 };

export async function initStaffPanel(element) {
  panel = element;
  panel.hidden = false;
  setupTabs();
  setupForms();

  try {
    render(await api("staff.php"));
  } catch (err) {
    toast(err.message, "error");
  }
}

// --- Actions ---

async function run(action, payload = {}, button) {
  if (button) button.disabled = true;
  try {
    const result = await api("staff.php", { method: "POST", body: { action, ...payload } });
    toast(result.message, "success");
    render(result);
    return true;
  } catch (err) {
    toast(err.message, "error");
    return false;
  } finally {
    if (button) button.disabled = false;
  }
}

function setupTabs() {
  const tabs = panel.querySelectorAll("[role=tab]");
  tabs.forEach((tab) => {
    tab.addEventListener("click", () => {
      tabs.forEach((other) => {
        const selected = other === tab;
        other.setAttribute("aria-selected", String(selected));
        panel.querySelector(`#${other.getAttribute("aria-controls")}`).hidden = !selected;
      });
    });
  });
}

function setupForms() {
  const whitelistForm = panel.querySelector("#staff-whitelist-form");
  whitelistForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const input = whitelistForm.elements.player;
    if (!input.value.trim()) return;
    if (await run("whitelist.add", { player: input.value }, event.submitter)) {
      whitelistForm.reset();
    }
  });

  const postForm = panel.querySelector("#staff-post-form");
  const category = postForm.elements.category;
  const targetBox = panel.querySelector("#staff-post-target");
  category.addEventListener("change", () => {
    targetBox.hidden = category.value !== "membres";
  });
  postForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const { title, content, targetPlayerId } = postForm.elements;
    if (!title.value.trim() || !content.value.trim()) {
      toast("Le titre et le contenu sont requis.", "error");
      return;
    }
    const payload = {
      category: category.value,
      title: title.value,
      content: content.value,
      targetPlayerId: category.value === "membres" ? Number(targetPlayerId.value) : 0,
    };
    if (await run("post.create", payload, event.submitter)) {
      postForm.reset();
      targetBox.hidden = true;
    }
  });

  // Filtre la liste des joueurs whitelistés à chaque frappe
  panel.querySelector("#staff-player-search").addEventListener("input", () => {
    if (state) renderPlayers();
  });

  panel.querySelector("#staff-stats-search").addEventListener("input", () => {
    if (state) renderStats();
  });

  // Clic sur un en-tête : trie par cette colonne, un second clic inverse le sens
  panel.querySelectorAll("[data-sort]").forEach((button) => {
    button.addEventListener("click", () => {
      const key = button.dataset.sort;
      statsSort = statsSort.key === key
        ? { key, direction: -statsSort.direction }
        : { key, direction: key === "username" ? 1 : -1 };
      if (state) renderStats();
    });
  });

  panel.querySelectorAll("[data-status]").forEach((button) => {
    button.addEventListener("click", () => run("status.set", { status: button.dataset.status }, button));
  });
}

// --- Affichage ---

function render(data) {
  state = data;
  renderApplications();
  renderPlayers();
  renderStats();
  renderPosts();
  renderStatus();
}

function renderApplications() {
  const list = panel.querySelector("#staff-applications-list");
  panel.querySelector("#staff-applications-count").textContent = state.applications.length || "";

  if (!state.applications.length) {
    list.replaceChildren(el("p", { className: "hint" }, "Aucune candidature en attente."));
    return;
  }

  list.replaceChildren(
    ...state.applications.map((application) => {
      const details = el("dl", { className: "application-details" });
      for (const [key, label] of Object.entries(APPLICATION_FIELDS)) {
        details.append(el("dt", {}, label), el("dd", {}, application.data[key] ?? ""));
      }
      details.append(
        el("dt", {}, "RP Rebelle ?"),
        el("dd", {}, application.data.illegal ? "Oui" : "Non"),
        el("dt", {}, "Demande staff ?"),
        el("dd", {}, application.data.staff ? "Oui" : "Non"),
      );

      const accept = el("button", { type: "button", className: "accept" }, "Accepter");
      accept.addEventListener("click", () =>
        run("application.review", { id: application.id, decision: "accept" }, accept),
      );
      const refuse = el("button", { type: "button", className: "refuse" }, "Refuser");
      refuse.addEventListener("click", () => {
        if (confirm(`Refuser la candidature de ${application.username} ?`)) {
          run("application.review", { id: application.id, decision: "refuse" }, refuse);
        }
      });

      return el(
        "article",
        { className: "staff-item" },
        el(
          "details",
          {},
          el(
            "summary",
            {},
            el("strong", {}, application.username),
            ` — ${application.data.first_name} ${application.data.last_name}`,
            el("small", {}, ` · ${formatDate(application.createdAt)}`),
          ),
          details,
        ),
        el("div", { className: "staff-actions" }, accept, refuse),
      );
    }),
  );
}

function renderPlayers() {
  const list = panel.querySelector("#staff-players-list");
  panel.querySelector("#staff-players-count").textContent = state.players.length || "";

  // Liste des destinataires possibles d'une annonce personnelle
  const select = panel.querySelector("#staff-post-form").elements.targetPlayerId;
  const selected = select.value;
  select.replaceChildren(
    el("option", { value: "0" }, "Tous les membres"),
    ...state.players.map((player) => el("option", { value: String(player.id) }, playerLabel(player))),
  );
  select.value = [...select.options].some((option) => option.value === selected) ? selected : "0";

  if (!state.players.length) {
    list.replaceChildren(el("p", { className: "hint" }, "Aucun joueur whitelisté."));
    return;
  }

  const query = normalize(panel.querySelector("#staff-player-search").value.trim());
  const players = query
    ? state.players.filter((player) => searchText(player).includes(query))
    : state.players;

  const results = panel.querySelector("#staff-players-results");
  results.hidden = !query;
  results.textContent = `${players.length} joueur${players.length > 1 ? "s" : ""} sur ${state.players.length}`;

  if (!players.length) {
    list.replaceChildren(el("p", { className: "hint" }, "Aucun joueur ne correspond à cette recherche."));
    return;
  }

  list.replaceChildren(
    ...players.map((player) => {
      const remove = el("button", { type: "button", className: "refuse" }, "Retirer");
      remove.addEventListener("click", () => {
        if (confirm(`Retirer l'accès au site à ${player.username} ?`)) {
          run("whitelist.remove", { playerId: player.id }, remove);
        }
      });

      return el(
        "article",
        { className: "staff-item player-item" },
        el(
          "details",
          {},
          el(
            "summary",
            {},
            el("strong", {}, playerLabel(player)),
            el(
              "small",
              {},
              player.connected ? " · déjà connecté" : " · jamais connecté",
              player.whitelistedAt ? ` · depuis le ${formatDate(player.whitelistedAt)}` : "",
            ),
          ),
          playerDetails(player),
        ),
        remove,
      );
    }),
  );
}

/** Fiche complète d'un joueur, affichée quand on déplie sa ligne. */
function playerDetails(player) {
  const { minecraft, stats, sheet } = player;
  const date = (value) => (value ? formatDate(value) : "—");

  const discord = infoList({
    Pseudo: player.username,
    "Nom affiché": player.displayName || "—",
    Identifiant: player.discordId || "Pas encore connecté au site",
    "Whitelisté le": date(player.whitelistedAt),
    "Whitelisté par": player.whitelistedBy || "—",
    "Dernière connexion au site": date(player.lastLoginAt),
  });

  const skin = minecraft.skinUrl
    ? el("a", { href: minecraft.skinUrl, target: "_blank", rel: "noopener noreferrer" }, "Voir le skin")
    : "—";
  const mcInfo = infoList({
    Pseudo: minecraft.username || "Compte non lié",
    UUID: minecraft.uuid || "—",
    "Lié le": date(minecraft.linkedAt),
    "Skin du personnage": skin,
  });
  let unlink = "";
  if (minecraft.uuid) {
    unlink = el("button", { type: "button", className: "refuse unlink" }, "Délier le compte Minecraft");
    unlink.addEventListener("click", () => {
      if (confirm(`Délier le compte Minecraft ${minecraft.username} de ${player.username} ? Il ne pourra plus rejoindre le serveur.`)) {
        run("minecraft.unlink", { playerId: player.id }, unlink);
      }
    });
  }
  const mcStats = stats
    ? infoList({
        "En jeu": stats.online ? "🟢 Oui" : "⚫ Non",
        "Temps de jeu": formatPlaytime(stats.playtimeSeconds),
        "Dernière connexion": stats.online ? "Maintenant" : date(stats.lastSeen),
        Morts: String(stats.deaths),
        "Monstres tués": String(stats.mobKills),
        "Joueurs tués": String(stats.playerKills),
      })
    : el("p", { className: "hint" }, "Aucune statistique reçue du serveur.");
  const head = minecraft.uuid
    ? el("img", { className: "mc-head", src: `https://mc-heads.net/avatar/${minecraft.uuid}/48`, alt: "" })
    : "";

  const character = infoList({
    Prénom: sheet.first_name || "—",
    Nom: sheet.last_name || "—",
    Âge: sheet.age_character || "—",
    "Lieu de naissance": sheet.rp_born || "—",
    "Expérience RP": sheet.rp_experience || "—",
    Histoire: sheet.rp_story || "—",
  });

  return el(
    "div",
    { className: "player-details" },
    el("section", {}, el("h4", {}, "Discord"), discord),
    el("section", {}, el("h4", {}, "Minecraft"), head, mcInfo, mcStats, unlink),
    el("section", { className: "player-details-wide" }, el("h4", {}, "Fiche personnage"), character),
  );
}

/** Liste « libellé : valeur » (texte brut ou élément). */
function infoList(entries) {
  const list = el("dl", { className: "application-details" });
  for (const [label, value] of Object.entries(entries)) {
    list.append(el("dt", {}, label), el("dd", {}, value));
  }
  return list;
}

function renderStats() {
  const all = state.serverStats;
  const online = all.filter((stats) => stats.online);
  const total = (key) => all.reduce((sum, stats) => sum + stats[key], 0);

  panel.querySelector("#staff-stats-count").textContent = online.length ? `${online.length} 🟢` : "";
  panel.querySelector("#staff-stats-summary").replaceChildren(
    ...Object.entries({
      "En jeu": numberFormat.format(online.length),
      "Joueurs vus": numberFormat.format(all.length),
      "Temps de jeu total": formatPlaytime(total("playtimeSeconds")),
      Morts: numberFormat.format(total("deaths")),
      "Monstres tués": numberFormat.format(total("mobKills")),
      "Joueurs tués": numberFormat.format(total("playerKills")),
    }).map(([label, value]) => el("div", {}, el("dt", {}, label), el("dd", {}, value))),
  );

  const query = normalize(panel.querySelector("#staff-stats-search").value.trim());
  const rows = (query ? all.filter((stats) => statsSearchText(stats).includes(query)) : [...all])
    .sort((a, b) => statsSort.direction * compareStats(a, b, statsSort.key));

  const lastUpdate = all.reduce((latest, stats) => (stats.updatedAt > latest ? stats.updatedAt : latest), "");
  panel.querySelector("#staff-stats-hint").textContent = !all.length
    ? "Aucune statistique reçue : vérifiez que le plugin TLWStats tourne sur le serveur."
    : [
        query ? `${rows.length} joueur${rows.length > 1 ? "s" : ""} sur ${all.length}` : "",
        `Dernier envoi du serveur : ${formatDate(lastUpdate)}`,
      ].filter(Boolean).join(" · ");

  panel.querySelectorAll("[data-sort]").forEach((button) => {
    const active = button.dataset.sort === statsSort.key;
    button.closest("th").setAttribute(
      "aria-sort",
      active ? (statsSort.direction > 0 ? "ascending" : "descending") : "none",
    );
  });

  panel.querySelector("#staff-stats-list").replaceChildren(
    ...rows.map((stats) => {
      const { account } = stats;
      const linked = account
        ? [account.displayName || account.username, account.character].filter(Boolean).join(" — ")
          + (account.whitelisted ? "" : " (plus whitelisté)")
        : "Aucun compte du site";

      return el(
        "tr",
        {},
        el(
          "td",
          { className: "stats-player" },
          el("img", { className: "mc-head", src: `https://mc-heads.net/avatar/${stats.uuid}/32`, alt: "", loading: "lazy" }),
          el(
            "div",
            {},
            el("strong", {}, `${stats.online ? "🟢 " : ""}${stats.username}`),
            el("small", {}, linked),
          ),
        ),
        el("td", {}, formatPlaytime(stats.playtimeSeconds)),
        el("td", {}, stats.online ? "En jeu" : stats.lastSeen ? formatDate(stats.lastSeen) : "—"),
        el("td", {}, numberFormat.format(stats.deaths)),
        el("td", {}, numberFormat.format(stats.mobKills)),
        el("td", {}, numberFormat.format(stats.playerKills)),
      );
    }),
  );
}

function compareStats(a, b, key) {
  if (key === "username") {
    return a.username.localeCompare(b.username, "fr", { sensitivity: "base" });
  }
  if (key === "lastSeen") {
    // Les joueurs en jeu sont les plus récents
    const time = (stats) => (stats.online ? Infinity : Date.parse(stats.lastSeen ?? "") || 0);
    return time(a) - time(b);
  }
  return a[key] - b[key];
}

function renderPosts() {
  const list = panel.querySelector("#staff-posts-list");

  if (!state.posts.length) {
    list.replaceChildren(el("p", { className: "hint" }, "Aucune publication."));
    return;
  }

  list.replaceChildren(
    ...state.posts.map((post) => {
      const remove = el("button", { type: "button", className: "refuse" }, "Supprimer");
      remove.addEventListener("click", () => {
        if (confirm(`Supprimer « ${post.title} » ?`)) {
          run("post.delete", { id: post.id }, remove);
        }
      });

      return el(
        "article",
        { className: "staff-item staff-row" },
        el(
          "div",
          {},
          el("span", { className: "post-tag" }, state.categories[post.category]),
          el("strong", {}, ` ${post.title}`),
          el(
            "small",
            {},
            post.target ? ` · pour ${post.target}` : "",
            ` · ${formatDate(post.createdAt)}`,
          ),
        ),
        remove,
      );
    }),
  );
}

function renderStatus() {
  panel.querySelector("#staff-status-current").textContent = state.statuses[state.status];
  panel.querySelectorAll("[data-status]").forEach((button) => {
    button.disabled = button.dataset.status === state.status;
  });
}

// --- Helpers ---

/** Tout ce sur quoi la recherche porte, sans accents ni majuscules. */
function searchText(player) {
  return normalize(
    [
      player.username,
      player.displayName,
      player.discordId,
      player.minecraft.username,
      player.sheet.first_name,
      player.sheet.last_name,
    ].join(" "),
  );
}

function statsSearchText(stats) {
  const { account } = stats;
  return normalize(
    [stats.username, stats.uuid, account?.username, account?.displayName, account?.character].join(" "),
  );
}

function normalize(text) {
  return (text ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase();
}

function playerLabel(player) {
  const name = player.displayName && player.displayName !== player.username
    ? `${player.displayName} (${player.username})`
    : player.username;
  return player.character ? `${name} — ${player.character}` : name;
}

/** Crée un élément ; les textes sont toujours insérés comme texte brut. */
function el(tag, props = {}, ...children) {
  const element = Object.assign(document.createElement(tag), props);
  element.append(...children.filter((child) => child !== "" && child != null));
  return element;
}
