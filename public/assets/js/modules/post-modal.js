// Fenêtre qui s'agrandit depuis la publication cliquée (« Voir plus.. »)

const ZOOM_DURATION = 280;
const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

let dialog;
let origin;

export function openPostModal({ title, date, dateTime, content, personal }, sourceElement) {
  dialog ??= createDialog();
  origin = sourceElement;

  dialog.querySelector(".post-modal-title").textContent = title;
  const time = dialog.querySelector("time");
  time.dateTime = dateTime;
  time.textContent = date;
  dialog.querySelector(".post-badge").hidden = !personal;
  dialog.querySelector(".post-modal-content").textContent = content;

  dialog.showModal();
  dialog.querySelector(".post-modal-body").scrollTop = 0;
  zoom(false);
}

function closePostModal() {
  zoom(true).then(() => dialog.close());
}

/** Anime la fenêtre depuis (ou vers) la carte d'origine. */
function zoom(reverse) {
  if (reduceMotion.matches || !origin?.isConnected) {
    return Promise.resolve();
  }

  const from = origin.getBoundingClientRect();
  const to = dialog.getBoundingClientRect();
  const dx = from.left + from.width / 2 - (to.left + to.width / 2);
  const dy = from.top + from.height / 2 - (to.top + to.height / 2);
  const scale = Math.max(from.width / to.width, 0.2);

  const frames = [
    { transform: `translate(${dx}px, ${dy}px) scale(${scale})`, opacity: 0 },
    { transform: "none", opacity: 1 },
  ];
  const animation = dialog.animate(reverse ? frames.reverse() : frames, {
    duration: ZOOM_DURATION,
    easing: "cubic-bezier(0.2, 0.8, 0.2, 1)",
  });
  return animation.finished.catch(() => {});
}

function createDialog() {
  const element = document.createElement("dialog");
  element.className = "post-modal";
  element.innerHTML = `
    <div class="post-modal-body">
      <button type="button" class="post-modal-close" aria-label="Fermer">✕</button>
      <span class="post-badge" hidden>Message personnel</span>
      <h3 class="post-modal-title"></h3>
      <time></time>
      <p class="post-modal-content"></p>
    </div>
  `;

  element.querySelector(".post-modal-close").addEventListener("click", closePostModal);
  // Clic en dehors du contenu (sur le fond assombri)
  element.addEventListener("click", (event) => {
    if (event.target === element) closePostModal();
  });
  // Échap : on garde l'animation de fermeture
  element.addEventListener("cancel", (event) => {
    event.preventDefault();
    closePostModal();
  });

  document.body.append(element);
  return element;
}
