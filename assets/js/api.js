// FINATUM PropertyBank — talking to api/index.php. Same contract as the Backoffice,
// the Planer and the Language Teacher: JSON in and out, a session cookie, and
// the CSRF token in a header on every change.

const base = document.body.dataset.base || "./";
export const state = { csrf: null, user: null, onSignedOut: null };

export class ApiError extends Error {
  constructor(status, body) {
    super((body && body.message) || "Request failed (" + status + ").");
    this.status = status;
    this.body = body || {};
  }
}

function url(path, params) {
  const [p, q] = path.split("?");
  const sp = new URLSearchParams(q || "");
  sp.set("p", p);
  for (const [k, v] of Object.entries(params || {})) {
    if (v !== undefined && v !== null && v !== "") sp.set(k, v);
  }
  return base + "api/index.php?" + sp.toString();
}

export async function api(path, { method = "GET", body, params } = {}) {
  const init = { method, headers: { Accept: "application/json" }, credentials: "same-origin" };
  if (method !== "GET") {
    if (state.csrf) init.headers["X-CSRF-Token"] = state.csrf;
    init.headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(body || {});
  }
  let res;
  try {
    res = await fetch(url(path, params), init);
  } catch {
    throw new ApiError(0, { message: "The server could not be reached. Check the connection and try again." });
  }
  let data = null;
  try { data = await res.json(); } catch { data = null; }
  if (!res.ok) {
    if ((res.status === 401 || res.status === 419) && !path.startsWith("auth/") && state.onSignedOut) {
      state.onSignedOut(data && data.message);
    }
    throw new ApiError(res.status, data);
  }
  return data;
}

export const appBase = base;
