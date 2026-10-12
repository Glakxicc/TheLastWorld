// Inventaire d'un joueur (panneau staff) : dernier état envoyé par le plugin TLWStats,
// disposé comme dans le jeu. Un clic sur une case affiche le détail de l'objet.

// --- Import ---
import { api } from "./api.js";
import { formatDateTime } from "./posts.js";
import { toast } from "./toast.js";

const ARMOR_SLOTS = ["Casque", "Plastron", "Jambières", "Bottes"];

let dialog;

export async function openInventoryModal(player, button) {
  if (button) button.disabled = true;
  try {
    const data = await api(`minecraft/inventory.php?playerId=${player.id}`);
    dialog ??= createDialog();
    render(data);
    dialog.showModal();
  } catch (err) {
    toast(err.message, "error");
  } finally {
    if (button) button.disabled = false;
  }
}

function createDialog() {
  const element = el(
    "dialog",
    { className: "post-modal inventory-modal" },
    el(
      "div",
      { className: "post-modal-body" },
      el("button", { type: "button", className: "post-modal-close", ariaLabel: "Fermer" }, "✕"),
      el("h3", { className: "post-modal-title" }),
      el("p", { className: "inventory-meta" }),
      el("div", { className: "inventory-content" }),
    ),
  );
  element.querySelector(".post-modal-close").addEventListener("click", () => element.close());
  // Clic sur le fond : ferme la fenêtre
  element.addEventListener("click", (event) => {
    if (event.target === element) element.close();
  });
  document.body.append(element);
  return element;
}

function render({ username, online, updatedAt, inventory }) {
  dialog.querySelector(".post-modal-title").textContent = `Inventaire de ${username}`;
  dialog.querySelector(".inventory-meta").textContent = [
    online ? "🟢 En jeu" : "⚫ Hors ligne",
    `État du ${formatDateTime(updatedAt)}`,
    inventory.health != null ? `❤ ${inventory.health} / 20` : "",
    inventory.food != null ? `🍗 ${inventory.food} / 20` : "",
    inventory.level != null ? `Niveau ${inventory.level}` : "",
  ].filter(Boolean).join(" · ");

  const detail = el("div", { className: "inventory-detail" }, el("p", { className: "hint" }, "Cliquez sur un objet pour voir son détail."));
  const show = (item, label) => detail.replaceChildren(itemDetail(item, label));

  const slots = (items, count, label, extra = {}) =>
    grid(items ?? [], count, (item, slot) => label(slot), show, extra);

  const armor = grid(
    // Le jeu envoie bottes → casque : on affiche du casque aux bottes
    (inventory.armor ?? []).map((item) => ({ ...item, slot: 3 - item.slot })),
    4,
    (item, slot) => ARMOR_SLOTS[slot],
    show,
    { className: "inventory-armor" },
  );
  const offhand = grid(inventory.offhand ?? [], 1, () => "Seconde main", show, { className: "inventory-offhand" });

  const main = inventory.main ?? [];
  const backpack = slots(
    main.filter((item) => item.slot >= 9).map((item) => ({ ...item, slot: item.slot - 9 })),
    27,
    (slot) => `Inventaire, case ${slot + 1}`,
  );
  const hotbar = slots(
    main.filter((item) => item.slot < 9),
    9,
    (slot) => `Barre d'action, case ${slot + 1}`,
    { held: inventory.heldSlot },
  );
  const enderChest = slots(inventory.enderChest, 27, (slot) => `Coffre de l'Ender, case ${slot + 1}`);

  dialog.querySelector(".inventory-content").replaceChildren(
    el(
      "div",
      { className: "inventory-player" },
      section("Équipement", el("div", { className: "inventory-equipment" }, armor, offhand)),
      section("Inventaire", backpack, hotbar),
    ),
    section("Coffre de l'Ender", enderChest),
    detail,
  );
}

function section(title, ...children) {
  return el("section", { className: "inventory-section" }, el("h4", {}, title), ...children);
}

/** Grille de `count` cases ; chaque objet va dans la case indiquée par son `slot`. */
function grid(items, count, label, show, { className = "", held = -1 } = {}) {
  const bySlot = new Map(items.map((item) => [item.slot, item]));
  const element = el("div", { className: `inventory-grid ${className}`.trim() });

  for (let slot = 0; slot < count; slot++) {
    const item = bySlot.get(slot);
    const where = label(item, slot);
    const cell = el("button", {
      type: "button",
      className: `inventory-slot${slot === held ? " held" : ""}`,
      title: item ? `${itemName(item)}${item.amount > 1 ? ` ×${item.amount}` : ""} — ${where}` : where,
      ariaLabel: item ? `${where} : ${itemName(item)}, ${item.amount}` : `${where} : vide`,
      disabled: !item,
    });
    if (item) {
      cell.style.setProperty("--item-hue", String(hue(item.type)));
      if (item.enchantments) cell.classList.add("enchanted");
      cell.append(el("span", { className: "inventory-item" }, initials(item)));
      if (item.amount > 1) cell.append(el("span", { className: "inventory-amount" }, String(item.amount)));
      if (item.maxDamage > 0 && item.damage > 0) {
        const bar = el("span", { className: "inventory-durability" });
        bar.style.setProperty("--durability", String(1 - item.damage / item.maxDamage));
        cell.append(bar);
      }
      cell.addEventListener("click", () => show(item, where));
    }
    element.append(cell);
  }
  return element;
}

function itemDetail(item, where) {
  const list = el("dl", { className: "application-details" });
  const add = (label, value) => list.append(el("dt", {}, label), el("dd", {}, value));

  add("Emplacement", where);
  add("Quantité", String(item.amount));
  add("Identifiant", item.type);
  if (item.maxDamage > 0) {
    add("Durabilité", `${item.maxDamage - item.damage} / ${item.maxDamage}`);
  } else if (item.damage) {
    // Sans durabilité, la valeur sert de variante (couleur de laine, type de bois…)
    add("Variante", String(item.damage));
  }
  if (item.enchantments) {
    add(
      "Enchantements",
      Object.entries(item.enchantments).map(([name, level]) => `${prettify(name)} ${level}`).join(", "),
    );
  }
  if (item.lore?.length) {
    add("Description", item.lore.join("\n"));
  }

  return el("div", {}, el("strong", {}, itemName(item)), list);
}

// --- Helpers ---

function itemName(item) {
  return item.name || prettify(item.type);
}

/** DIAMOND_SWORD → Diamond sword */
function prettify(type) {
  const text = type.replace(/_/g, " ").toLowerCase();
  return text.charAt(0).toUpperCase() + text.slice(1);
}

function initials(item) {
  const words = itemName(item).split(/\s+/).filter(Boolean);
  return (words.length > 1 ? words[0][0] + words[1][0] : itemName(item).slice(0, 2)).toUpperCase();
}

/** Teinte stable par type d'objet : deux objets identiques ont la même couleur. */
function hue(type) {
  let hash = 0;
  for (const char of type) hash = (hash * 31 + char.charCodeAt(0)) % 360;
  return hash;
}

/** Crée un élément ; les textes sont toujours insérés comme texte brut. */
function el(tag, props = {}, ...children) {
  const element = Object.assign(document.createElement(tag), props);
  element.append(...children.filter((child) => child !== "" && child != null));
  return element;
}
