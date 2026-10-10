// --- Import ---
import { openPostModal } from "./post-modal.js";

const dateFormat = new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" });

export function formatDate(isoDate) {
  return dateFormat.format(new Date(isoDate));
}

/** Remplace le contenu de `list` par les publications (texte brut, jamais de HTML). */
export function renderPosts(list, posts) {
  list.replaceChildren(
    ...posts.map((post) => {
      const item = document.createElement("article");
      item.className = "news-item";

      const title = document.createElement("h3");
      title.textContent = post.title;

      const date = document.createElement("time");
      date.dateTime = post.created_at;
      date.textContent = formatDate(post.created_at);

      const content = document.createElement("p");
      content.textContent = post.content;

      const seeMore = document.createElement("a");
      seeMore.href = "#";
      seeMore.className = "see-more";
      seeMore.textContent = "Voir plus..";
      seeMore.addEventListener("click", (event) => {
        event.preventDefault();
        openPostModal(
          {
            title: post.title,
            date: formatDate(post.created_at),
            dateTime: post.created_at,
            content: post.content,
            personal: post.personal,
          },
          item,
        );
      });

      if (post.personal) {
        const badge = document.createElement("span");
        badge.className = "post-badge";
        badge.textContent = "Message personnel";
        item.append(badge);
      }
      item.append(title, date, content, seeMore);
      return item;
    }),
  );
}
