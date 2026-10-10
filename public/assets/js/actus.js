// --- Import ---
import { api } from "./modules/api.js";
import { renderPosts } from "./modules/posts.js";

// --- Init ---
const lists = {
  actus: document.querySelector("#actus-list"),
  devlog: document.querySelector("#devlog-list"),
};

api("posts.php?categories=actus,devlog")
  .then(({ posts }) => {
    for (const [category, list] of Object.entries(lists)) {
      const categoryPosts = posts.filter((post) => post.category === category);
      if (categoryPosts.length) {
        renderPosts(list, categoryPosts);
      }
    }
  })
  .catch(() => {
    // Serveur indisponible : on garde le contenu par défaut
  });
