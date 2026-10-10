export function showDesktopOnlyNotice() {
  const notice = document.createElement("main");
  notice.className = "desktop-only";
  notice.innerHTML = `
    <h1>Site non disponible sur mobile</h1>
    <p>Ce site est conçu pour être visité sur un ordinateur.</p>
  `;
  document.body.replaceChildren(notice);
}
