// FINATUM PropertyBank — accounts and settings (administrators only).
//
//   #/users    who may register (nobody, to begin with), the geocoder, the
//              pace towards OLX, and every account: approve, change role,
//              set a password, delete. The Pricer's and MarketData's page.

import { el, icon, clear, put, add, toast, downloadText } from "./util.js";
import { api, state } from "./api.js";
import { openModal, dangerButton } from "./dialog.js";
import { fmtDateTime } from "./format.js";

export async function renderUsers(view) {
  put(view, el("div", { class: "loading", text: "Loading accounts…" }));
  let data;
  try {
    data = await api("users");
  } catch (e) {
    put(view, el("div", { class: "empty", text: e.message }));
    return;
  }
  const s = data.settings;
  const cap = data.capacity;

  const mode = el("select", {}, [["off", "Nobody — I create accounts"], ["domain", "Only our email domains"], ["open", "Anyone"]]
    .map(([v, l]) => el("option", { value: v, selected: s.registration_mode === v }, l)));
  const domains = el("input", { type: "text", value: s.registration_domains });
  const approval = el("select", {}, [["1", "An administrator approves first"], ["0", "Sign in immediately"]]
    .map(([v, l]) => el("option", { value: v, selected: s.registration_approval === v }, l)));
  const max = el("input", { type: "number", min: "1", max: "1000", value: s.max_users });
  const geoUrl = el("input", { type: "text", value: s.geocoder_url });
  const geoContact = el("input", { type: "email", value: s.geocoder_contact, placeholder: "optional" });
  const delay = el("input", { type: "number", min: "0", max: "5000", value: s.olx_delay_ms });
  const domRow = el("label", {}, el("span", { text: "Allowed email domains" }), domains);
  // Translation: the key is never shown, only whether one is set and its last four characters.
  const translator = el("select", {}, [["", "Off"], ["deepl", "DeepL"], ["google", "Google Translate (Cloud)"]]
    .map(([v, l]) => el("option", { value: v, selected: (s.translator || "") === v }, l)));
  const trKey = el("input", { type: "password", autocomplete: "off", spellcheck: "false", "aria-label": "Translation API key",
    placeholder: s.translator_key_set === "1" ? "set (" + (s.translator_key_hint || "in config") + ") — paste a new one to replace it" : "paste the API key" });
  let trClear = false;
  const trClearBtn = s.translator_key_set === "1" && s.translator_key_from_config !== "1"
    ? el("button", { type: "button", class: "btn small", onclick: () => { trClear = true; trKey.value = ""; trKey.placeholder = "will be removed when you save"; } }, "Remove") : null;
  const syncDom = () => { domRow.hidden = mode.value !== "domain"; };
  mode.addEventListener("change", syncDom);
  syncDom();
  const saveSettings = el("button", { type: "button", class: "btn primary" }, icon("save"), "Save settings");
  saveSettings.addEventListener("click", async () => {
    try {
      await api("settings", { method: "PUT", body: {
        registration_mode: mode.value, registration_domains: domains.value, registration_approval: Number(approval.value),
        max_users: Number(max.value), geocoder_url: geoUrl.value.trim(), geocoder_contact: geoContact.value.trim(), olx_delay_ms: Number(delay.value),
        translator: translator.value, translator_key: trKey.value.trim(), translator_key_clear: trClear ? 1 : 0,
      } });
      toast("Settings saved.");
      renderUsers(view);
    } catch (e) { toast(e.message, true); }
  });

  const rows = data.users.map((u) => {
    const pending = Number(u.active) !== 1;
    const me = state.user && state.user.id === u.id;
    const role = el("select", { "aria-label": "Role of " + u.name, disabled: me },
      ["user", "admin"].map((r) => el("option", { value: r, selected: u.role === r }, r === "admin" ? "Administrator" : "User")));
    role.addEventListener("change", () => update(u, { role: role.value }, "Role changed."));
    return el("tr", { class: pending ? "pending" : null },
      el("td", {}, el("div", { text: u.name }), el("span", { class: "sub", text: u.email })),
      el("td", {}, role),
      el("td", {}, pending ? el("span", { class: "badge wait", text: "waiting" }) : el("span", { class: "badge", text: u.source === "self" ? "registered" : "created" })),
      el("td", { text: u.last_login_at ? fmtDateTime(u.last_login_at) : "never" }),
      el("td", {},
        pending ? el("button", { type: "button", class: "btn small primary", onclick: () => update(u, { active: 1 }, u.name + " can now sign in.") }, "Approve") : null,
        el("button", { type: "button", class: "btn small", onclick: () => setPassword(u) }, "Set password"),
        me ? null : dangerButton("Delete", async () => {
          try { await api("users/" + u.id, { method: "DELETE" }); toast("Account deleted."); renderUsers(view); } catch (e) { toast(e.message, true); }
        })));
  });

  async function update(u, patch, message) {
    try {
      await api("users/" + u.id, { method: "PUT", body: { name: u.name, role: u.role, active: Number(u.active), ...patch } });
      toast(message);
      renderUsers(view);
    } catch (e) { toast(e.message, true); renderUsers(view); }
  }

  function setPassword(u) {
    const m = openModal({ title: "Set a password for " + u.name, size: "narrow" });
    const pw = el("input", { type: "password", autocomplete: "new-password" });
    const mine = el("input", { type: "password", autocomplete: "current-password" });
    const err = el("div", { class: "form-error-box", hidden: true });
    m.body.append(err, el("div", { class: "form-grid" },
      el("label", { class: "field full" }, el("span", { class: "label", text: "New password for " + u.email }), pw, el("span", { class: "help", text: "At least 12 characters. Every device of that account is signed out." })),
      el("label", { class: "field full" }, el("span", { class: "label", text: "Your own password, to confirm" }), mine)));
    const ok = el("button", { type: "button", class: "btn primary", text: "Set password" });
    ok.addEventListener("click", async () => {
      try {
        await api("users/" + u.id, { method: "PUT", body: { name: u.name, role: u.role, active: Number(u.active), password: pw.value, admin_password: mine.value } });
        m.close();
        toast("Password set.");
      } catch (e) { err.textContent = e.message; err.hidden = false; }
    });
    m.foot.append(el("span", { class: "spacer" }), el("button", { type: "button", class: "btn", text: "Cancel", onclick: () => m.close() }), ok);
    pw.focus();
  }

  const nName = el("input", { type: "text" }), nEmail = el("input", { type: "email" }), nPw = el("input", { type: "password", autocomplete: "new-password" });
  const nRole = el("select", {}, el("option", { value: "user" }, "User"), el("option", { value: "admin" }, "Administrator"));
  const create = el("button", { type: "button", class: "btn primary" }, icon("plus"), "Create account");
  create.addEventListener("click", async () => {
    try {
      await api("users", { method: "POST", body: { name: nName.value, email: nEmail.value, password: nPw.value, role: nRole.value } });
      toast("Account created.");
      renderUsers(view);
    } catch (e) { toast(e.message, true); }
  });

  put(view, 
    el("div", { class: "view-head" }, el("div", {},
      el("div", { class: "eyebrow", text: "Administration" }),
      el("h1", { class: "view-title", text: "Accounts and settings" }))),
    el("div", { class: "panel" },
      el("h3", { text: "Who may use PropertyBank" }),
      el("p", { class: "explain" }, el("span", { class: "cap", text: cap.used + " of " + cap.limit + " accounts in use." }), " PropertyBank starts as a one-person application. Every account sees the same listings; filters are remembered per device."),
      el("div", { class: "fields-row" },
        el("label", {}, el("span", { text: "Who may register" }), mode), domRow,
        el("label", {}, el("span", { text: "New accounts" }), approval),
        el("label", {}, el("span", { text: "Maximum accounts" }), max)),
      el("div", { class: "row-actions" }, saveSettings)),
    el("div", { class: "panel" },
      el("h3", { text: "Reading and locating" }),
      el("p", { class: "explain", text: "Addresses are located with OpenStreetMap's Nominatim, which allows one lookup a second and asks for a contact. A pause between requests to OLX keeps PropertyBank a polite visitor." }),
      el("div", { class: "fields-row" },
        el("label", { class: "wide" }, el("span", { text: "Geocoder (Nominatim) address" }), geoUrl),
        el("label", {}, el("span", { text: "Your contact for the geocoder" }), geoContact),
        el("label", {}, el("span", { text: "Pause between OLX requests (ms)" }), delay)),
      el("div", { class: "row-actions" }, el("button", { type: "button", class: "btn primary", onclick: () => saveSettings.click() }, icon("save"), "Save settings"))),
    el("div", { class: "panel" },
      el("h3", { text: "Translation" }),
      el("p", { class: "explain" },
        "Titles and descriptions are stored in English. Two ways to get them there: ",
        el("b", { text: "a translation service" }), " (DeepL or Google, with an API key — each refresh then translates new and changed listings itself), or ",
        el("b", { text: "a file" }), ": export the listings not yet in English, have them translated (for example by Claude), and import the file back."),
      el("div", { class: "fields-row" },
        el("label", {}, el("span", { text: "Service" }), translator),
        el("label", { class: "wide" }, el("span", { text: "API key" }), trKey),
        trClearBtn ? el("div", { style: { display: "flex", alignItems: "flex-end" } }, trClearBtn) : null),
      el("div", { class: "row-actions" }, el("button", { type: "button", class: "btn primary", onclick: () => saveSettings.click() }, icon("save"), "Save service")),
      el("h4", { class: "mini-h", text: "Translate with a file" }),
      fileTranslation()),
    el("div", { class: "panel" },
      el("h3", { text: "Create an account" }),
      el("div", { class: "fields-row" },
        el("label", {}, el("span", { text: "Name" }), nName), el("label", {}, el("span", { text: "Email address" }), nEmail),
        el("label", {}, el("span", { text: "Password" }), nPw), el("label", {}, el("span", { text: "Role" }), nRole)),
      el("div", { class: "row-actions" }, create)),
    el("div", { class: "table-wrap", style: { maxHeight: "none" } }, el("table", { class: "grid" },
      el("thead", {}, el("tr", {}, el("th", { text: "Person" }), el("th", { text: "Role" }), el("th", { text: "Status" }),
        el("th", { text: "Last sign-in" }), el("th", { text: "" }))),
      el("tbody", {}, rows))));
}

/** Export the listings not yet in English to a file; import the translated file. */
function fileTranslation() {
  const all = el("input", { type: "checkbox" });
  const exp = el("button", { type: "button", class: "btn" }, icon("download"), "Export listings not yet in English");
  const status = el("div", { class: "form-note" });
  exp.addEventListener("click", async () => {
    exp.disabled = true;
    try {
      const d = await api("translations/export", { params: { all: all.checked ? "1" : "" } });
      if (!d.listings.length) { toast("Every listing is already in English."); return; }
      const name = "propertybank-translations-" + d.exported_at.slice(0, 10) + ".json";
      downloadText(name, JSON.stringify(d, null, 1), "application/json");
      const chars = d.listings.reduce((n, x) => n + x.title.length + x.description.length, 0);
      status.textContent = "Exported " + d.listings.length + " listings (" + Math.round(chars / 1000) + " thousand characters) to " + name + ".";
    } catch (e) { toast(e.message, true); } finally { exp.disabled = false; }
  });

  const file = el("input", { type: "file", accept: ".json,application/json" });
  const by = el("input", { type: "text", value: "Claude" });
  const imp = el("button", { type: "button", class: "btn primary" }, icon("upload"), "Import translations");
  imp.addEventListener("click", async () => {
    const f = file.files && file.files[0];
    if (!f) { toast("Choose the translated file first.", true); return; }
    let data;
    try { data = JSON.parse(await f.text()); } catch { toast("That file is not JSON.", true); return; }
    if (!data || data.format !== "propertybank-translations" || !Array.isArray(data.listings)) {
      toast("That is not a PropertyBank translations file.", true); return;
    }
    imp.disabled = true;
    const total = { stored: 0, changed: 0, unknown: 0, empty: 0 };
    try {
      for (let i = 0; i < data.listings.length; i += 200) {
        status.textContent = "Importing " + Math.min(i + 200, data.listings.length) + " of " + data.listings.length + "…";
        const r = await api("translations/import", { method: "POST", body: { listings: data.listings.slice(i, i + 200), translated_by: by.value.trim() } });
        for (const k of Object.keys(total)) total[k] += r[k] || 0;
      }
      status.textContent = total.stored + " translations stored" +
        (total.changed ? "; " + total.changed + " skipped because the text changed on OLX since the export (export again)" : "") +
        (total.empty ? "; " + total.empty + " without a translation" : "") +
        (total.unknown ? "; " + total.unknown + " no longer in the database" : "") + ".";
      toast("Translations imported.");
    } catch (e) { toast(e.message, true); } finally { imp.disabled = false; }
  });

  return el("div", {},
    el("div", { class: "row-actions" }, exp, el("label", { class: "check" }, all, el("span", { text: "all listings, also those already translated" }))),
    el("div", { class: "fields-row", style: { marginTop: "12px" } },
      el("label", { class: "wide" }, el("span", { text: "Translated file" }), file),
      el("label", {}, el("span", { text: "Translated by" }), by)),
    el("div", { class: "row-actions" }, imp),
    status);
}
