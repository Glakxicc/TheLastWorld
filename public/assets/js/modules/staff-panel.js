// Panneau staff de « Mon espace » : les mêmes actions que le bot Discord.

// --- Import ---
import { api } from "./api.js";
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

let panel;
let state;

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

  panel.querySelectorAll("[data-status]").forEach((button) => {
    button.addEventListener("click", () => run("status.set", { status: button.dataset.status }, button));
  });
}

// --- Affichage ---

function render(data) {
  state = data;
  renderApplications();
  renderPlayers();
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

  list.replaceChildren(
    ...state.players.map((player) => {
      const remove = el("button", { type: "button", className: "refuse" }, "Retirer");
      remove.addEventListener("click", () => {
        if (confirm(`Retirer l'accès au site à ${player.username} ?`)) {
          run("whitelist.remove", { playerId: player.id }, remove);
        }
      });

      return el(
        "article",
        { className: "staff-item staff-row" },
        el(
          "div",
          {},
          el("strong", {}, playerLabel(player)),
          el(
            "small",
            {},
            player.connected ? " · déjà connecté" : " · jamais connecté",
            player.whitelistedAt ? ` · depuis le ${formatDate(player.whitelistedAt)}` : "",
          ),
        ),
        remove,
      );
    }),
  );
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
