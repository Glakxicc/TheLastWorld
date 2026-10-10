export const COLORS = {
  success: "#25d940",
  error: "#ff4242",
  info: "#3b6dff",
};

export function toast(text, type = "info", duration = 2500) {
  Toastify({
    text,
    duration,
    style: { background: COLORS[type] },
  }).showToast();
}
