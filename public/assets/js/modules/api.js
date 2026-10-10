// Live Server ne sait pas exécuter le PHP : on passe alors par `php -S localhost:8000`
export const API_BASE = window.location.port === "5500" ? "http://localhost:8000" : "";

export class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

export async function api(path, { method = "GET", body } = {}) {
  let response;
  try {
    response = await fetch(`${API_BASE}/api/${path}`, {
      method,
      credentials: "include",
      headers: body ? { "Content-Type": "application/json" } : {},
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError("Impossible de contacter le serveur.", 0);
  }

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new ApiError(data.error || "Erreur serveur.", response.status);
  }
  return data;
}
