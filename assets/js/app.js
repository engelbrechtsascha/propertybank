// FINATUM PropertyBank — start-up, sign-in and the router.
//
//   #/listings     the listings: annunciator, search, table (start page)
//   #/cities       landmarks and metro stations per city
//   #/sources      what a refresh reads; connection test; recent refreshes
//   #/users        accounts and settings (administrators)

import { el, clear, put, toast } from "./util.js";
import { api, state } from "./api.js";
import { initModal, openModal, closeModal } from "./dialog.js";
import { store } from "./store.js";
import { renderListings } from "./listings.js";
import { renderCities } from "./cities.js";
import { renderSources } from "./sources.js";
import { renderUsers } from "./admin.js";
import { watchPasswordFields } from "./password.js";

const $ = (id) => document.getElementById(id);
const view = () => $("view");

/* ------------------------------------------------------------- router */

let routing = false;
async function route() {
  if (!store.user || routing) return;
  routing = true;
  closeModal();
  const section = location.hash.replace(/^#\/?/, "").split(/[/?]/)[0] || "listings";
  for (const a of document.querySelectorAll("nav.tabs a, .topbar a.brand-name")) {
    if (a.dataset.tab === section) a.setAttribute("aria-current", "page"); else a.removeAttribute("aria-current");
  }
  const v = view();
  try {
    if (section === "listings") await renderListings(v);
    else if (section === "cities") await renderCities(v);
    else if (section === "sources") await renderSources(v);
    else if (section === "users" && store.user.role === "admin") await renderUsers(v);
    else { routing = false; location.hash = "#/listings"; return; }
  } catch (e) {
    put(v, el("div", { class: "empty", text: e.message }));
  } finally {
    routing = false;
  }
  window.scrollTo({ top: 0 });
}

/* ------------------------------------------------- sign-in / register */

function showSignin(message, notice) {
  $("app").hidden = true;
  $("signin").hidden = false;
  $("registerForm").hidden = true;
  $("signinForm").hidden = false;
  const err = $("signinError");
  err.hidden = !message;
  err.textContent = message || "";
  const ok = $("signinNotice");
  ok.hidden = !notice;
  ok.textContent = notice || "";
  const su = store.me && store.me.signup;
  $("toRegisterLine").hidden = !(su && su.registration_enabled);
  $("signinEmail").focus();
}

function showRegister() {
  const su = (store.me && store.me.signup) || {};
  $("signinForm").hidden = true;
  $("registerForm").hidden = false;
  $("registerError").hidden = true;
  $("regPwLabel").textContent = "Password (at least " + ((store.me && store.me.min_password_length) || 12) + " characters)";
  $("registerSub").textContent = (su.registration_mode === "domain" ? "For addresses at " + su.registration_domains.join(", ") + "." : "")
    + (su.needs_approval ? " An administrator approves new accounts." : "");
  $("regName").focus();
}

async function showApp(user) {
  state.user = user;
  store.user = user;
  $("userName").textContent = user.name;
  $("userEmail").textContent = user.email;
  $("userRole").textContent = user.role === "admin" ? "admin" : "";
  $("menuUsers").hidden = user.role !== "admin";
  $("signin").hidden = true;
  $("app").hidden = false;
  if (!location.hash || location.hash === "#" || location.hash === "#/") location.hash = "#/listings";
  await route();
}

$("signinForm").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const btn = $("signinSubmit");
  btn.disabled = true;
  try {
    const r = await api("auth/login", { method: "POST", body: { email: $("signinEmail").value, password: $("signinPassword").value } });
    state.csrf = r.csrf;
    store.prefs = r.prefs || {};
    $("signinPassword").value = "";
    await showApp(r.user);
  } catch (e) {
    showSignin(e.message);
  } finally {
    btn.disabled = false;
  }
});

$("registerForm").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const err = $("registerError");
  err.hidden = true;
  if ($("regPassword").value !== $("regPassword2").value) {
    err.textContent = "The two passwords do not match.";
    err.hidden = false;
    return;
  }
  const btn = $("registerSubmit");
  btn.disabled = true;
  try {
    const r = await api("auth/register", { method: "POST", body: { name: $("regName").value, email: $("regEmail").value, password: $("regPassword").value } });
    $("regPassword").value = ""; $("regPassword2").value = "";
    if (r.pending) {
      showSignin("", r.message);
      return;
    }
    state.csrf = r.csrf;
    store.prefs = {};
    await showApp(r.user);
    toast("Welcome!");
  } catch (e) {
    err.textContent = e.message;
    err.hidden = false;
  } finally {
    btn.disabled = false;
  }
});

$("toRegister").addEventListener("click", showRegister);
$("toSignin").addEventListener("click", () => showSignin());

state.onSignedOut = (message) => {
  store.reset();
  closeModal();
  showSignin(message || "Your session has ended. Please sign in again.");
};

/* ------------------------------------------------------- account menu */

$("accountMenu").addEventListener("click", async (ev) => {
  const b = ev.target.closest("button[data-action]");
  if (!b) return;
  $("accountMenu").open = false;
  const a = b.dataset.action;
  if (a === "logout") {
    try { await api("auth/logout", { method: "POST" }); } catch { /* signed out anyway */ }
    state.csrf = null;
    store.reset();
    clear(view());
    showSignin();
  } else if (a === "users") {
    location.hash = "#/users";
  } else if (a === "password") {
    changePassword();
  } else if (a === "close") {
    closeAccount();
  } else if (a === "about") {
    about();
  }
});
document.addEventListener("click", (ev) => {
  const m = $("accountMenu");
  if (m.open && !m.contains(ev.target)) m.open = false;
});

function pwField(label, auto, help) {
  const input = el("input", { type: "password", autocomplete: auto });
  return { input, node: el("label", { class: "field full" }, el("span", { class: "label", text: label }), input, help ? el("span", { class: "help", text: help }) : null) };
}

function changePassword() {
  const m = openModal({ title: "Change password", size: "narrow" });
  const cur = pwField("Current password", "current-password");
  const p1 = pwField("New password", "new-password", "At least " + ((store.me && store.me.min_password_length) || 12) + " characters. Your other devices are signed out.");
  const p2 = pwField("Repeat new password", "new-password");
  const err = el("div", { class: "form-error-box", hidden: true });
  m.body.append(err, el("div", { class: "form-grid" }, cur.node, p1.node, p2.node));
  const save = el("button", { type: "button", class: "btn primary", text: "Change password" });
  m.foot.append(el("span", { class: "spacer" }), el("button", { type: "button", class: "btn", text: "Cancel", onclick: () => m.close() }), save);
  save.addEventListener("click", async () => {
    err.hidden = true;
    if (p1.input.value !== p2.input.value) { err.textContent = "The new passwords differ."; err.hidden = false; return; }
    try {
      await api("auth/password", { method: "POST", body: { current_password: cur.input.value, new_password: p1.input.value } });
      m.close();
      toast("Password changed.");
    } catch (e) { err.textContent = e.message; err.hidden = false; }
  });
  cur.input.focus();
}

function closeAccount() {
  const m = openModal({ title: "Close my account", size: "narrow" });
  const pw = pwField("Your password, to confirm", "current-password");
  const err = el("div", { class: "form-error-box", hidden: true });
  m.body.append(err,
    el("p", { text: "This deletes your account. The listings stay. It cannot be undone." }),
    el("div", { class: "form-grid" }, pw.node));
  const go = el("button", { type: "button", class: "btn danger", text: "Delete my account" });
  m.foot.append(el("span", { class: "spacer" }), el("button", { type: "button", class: "btn", text: "Cancel", onclick: () => m.close() }), go);
  go.addEventListener("click", async () => {
    try {
      await api("auth/account", { method: "DELETE", body: { password: pw.input.value } });
      m.close();
      state.csrf = null;
      store.reset();
      clear(view());
      showSignin("", "Your account has been closed and its data deleted.");
    } catch (e) { err.textContent = e.message; err.hidden = false; }
  });
  pw.input.focus();
}

/* -------------------------------------------------------------- about */

function about() {
  const m = openModal({ title: "About the data", eyebrow: "FINATUM PropertyBank " + ((store.me && store.me.version) || ""), size: "wide" });
  m.body.append(el("div", { class: "about", html: ABOUT_HTML }));
  m.foot.append(el("span", { class: "spacer" }), el("button", { type: "button", class: "btn primary", text: "Close", onclick: () => m.close() }));
}

const ABOUT_HTML =
  "<p>PropertyBank keeps the key data of each listing and links to the listing for everything else. The listing on the site stays the authority.</p>" +
  "<h3>Where the data come from</h3><ul>" +
  "<li><b>OLX.ua</b> — the search API behind OLX's own pages: title, description, floor, floors, area, rooms, year, price and currency, posting date, city and district. " +
  "The street and house number come from OLX's location service, where the seller entered one.</li>" +
  "<li><b>Ceiling height</b> is not an OLX field: it is read from the description (\"висота стелі 3,2 м\", \"потолки 3.1\"). So is the year of construction when the field is empty.</li>" +
  "<li><b>Exchange rates</b>: the ECB's euro reference rate for EUR/USD, the National Bank of Ukraine's for the hryvnia, fetched at every refresh. " +
  "The price as listed is shown in bold; the other currency is converted at that rate (≈).</li></ul>" +
  "<h3>Location and distances</h3><ul>" +
  "<li>An address — the seller's own, else one found in the title or description — is located with OpenStreetMap's Nominatim and accepted only inside the listing's city and district. " +
  "Without one, OLX's own map point is used when OLX says it is within about a kilometre (≈ 1 km); otherwise there are no distances.</li>" +
  "<li>Distances are straight lines over the earth's surface (great circle), in metres — not walking distances.</li>" +
  "<li>Landmarks and metro stations are set per city on the Cities page; Kyiv starts with the Golden Gate, the Maidan and all 52 stations of the Kyiv Metro.</li></ul>" +
  "<h3>Into English</h3><p>Names are transliterated, not translated, by the official Ukrainian system of 2010 (the one on Kyiv's street signs); " +
  "the kind of street is put into English: Михайлівський пров. 9 → 9 Mykhailivskyi Lane. Listings in Russian are transliterated by the Russian rules.</p>" +
  "<h3>Refreshing</h3><p>A refresh reads every listing of every active search, stores new and changed ones, marks the ones no longer on OLX as removed, and locates the new ones. " +
  "It runs on the server in steps while this page is open. OLX answers at most 1,000 listings per search, so a larger district is read in price bands.</p>" +
  "<p>Map data © OpenStreetMap contributors, available under the Open Database Licence.</p>";

/* -------------------------------------------------------------- start */

initModal();
watchPasswordFields();
window.addEventListener("hashchange", route);

(async function start() {
  try {
    const me = await api("auth/me");
    store.me = me;
    document.title = "PropertyBank · FINATUM";
    if (me.user) {
      state.csrf = me.csrf;
      store.prefs = me.prefs || {};
      await showApp(me.user);
    } else {
      showSignin();
    }
  } catch (e) {
    showSignin(e.status === 503 ? e.message : "The server could not be reached. " + e.message);
  }
})();
