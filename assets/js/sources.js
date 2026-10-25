// FINATUM PropertyBank — sources: what a refresh reads, the last refreshes,
// and whether the server can reach the sites at all.
//
//   #/sources

import { el, put, icon, toast } from "./util.js";
import { api } from "./api.js";
import { store } from "./store.js";
import { dangerButton } from "./dialog.js";
import { fmtDateTime, fmtNum } from "./format.js";

export async function renderSources(view) {
  put(view, el("div", { class: "loading", text: "Loading…" }));
  let data;
  try { data = await api("searches"); } catch (e) { put(view, el("div", { class: "empty", text: e.message })); return; }
  const admin = store.user && store.user.role === "admin";

  const diagBox = el("div");
  const diagBtn = el("button", { type: "button", class: "btn" }, icon("search"), "Test the connections");
  diagBtn.addEventListener("click", async () => {
    diagBtn.disabled = true;
    put(diagBox, el("div", { class: "loading", text: "Asking OLX, the geocoder, the ECB and the NBU from the server…" }));
    try {
      const r = await api("diag");
      put(diagBox, el("ul", { class: "sources" }, r.checks.map((c) => el("li", {},
        el("span", { class: "st" + (c.ok ? " ok" : ""), text: c.ok ? "ok" : "failed" }), el("b", { text: c.name }), " — ", el("span", { class: "msg", text: c.detail })))));
    } catch (e) { put(diagBox, el("div", { class: "form-error-box", text: e.message })); } finally { diagBtn.disabled = false; }
  });

  const rows = data.searches.map((s) => el("tr", { class: s.active ? null : "inactive" },
    el("td", {}, el("b", { text: s.label }), el("span", { class: "sub", text: "OLX · " + Object.entries(s.params).map(([k, v]) => k + " " + v).join(" · ") })),
    el("td", { class: "num", text: fmtNum(s.listings) }),
    el("td", { text: s.last_run_at ? fmtDateTime(s.last_run_at) : "never" }),
    el("td", { text: s.active ? "on" : "off" }),
    el("td", {}, admin ? [
      el("button", { type: "button", class: "btn small", onclick: () => editSearch(s) }, "Edit"),
      dangerButton("Remove", async () => { try { await api("searches/" + s.id, { method: "DELETE" }); toast("Search removed; its listings are kept."); renderSources(view); } catch (e) { toast(e.message, true); } }),
    ] : null)));

  function editSearch(s) {
    put(form, searchForm(s, () => renderSources(view)));
    form.scrollIntoView({ behavior: "smooth" });
  }
  const form = el("div", {}, admin ? searchForm(null, () => renderSources(view)) : null);

  const runs = data.runs.map((r) => el("tr", {},
    el("td", { text: fmtDateTime(r.started_at) }),
    el("td", { text: r.status }),
    el("td", { class: "num", text: fmtNum(r.stats.new || 0) }),
    el("td", { class: "num", text: fmtNum(r.stats.changed || 0) }),
    el("td", { class: "num", text: fmtNum(r.stats.removed || 0) }),
    el("td", { class: "num", text: fmtNum(r.stats.located || 0) }),
    el("td", { class: "msg", text: r.message || "" })));

  put(view,
    el("div", { class: "view-head" }, el("div", {},
      el("div", { class: "eyebrow", text: "Configuration" }),
      el("h1", { class: "view-title", text: "Sources" }),
      el("p", { class: "view-sub", text: "What a refresh reads. For now: OLX.ua, flats for sale in Kyiv's Shevchenkivskyi district. Other districts, cities and sites can be added." }))),
    el("div", { class: "table-wrap", style: { maxHeight: "none" } }, el("table", { class: "grid tight" },
      el("thead", {}, el("tr", {}, el("th", { text: "Search" }), el("th", { class: "num", text: "Listings" }), el("th", { text: "Last read" }), el("th", { text: "Active" }), el("th", { text: "" }))),
      el("tbody", {}, rows))),
    form,
    admin ? el("div", { class: "panel" },
      el("h3", { text: "Can the server reach the sites?" }),
      el("p", { class: "explain", text: "The refresh runs on the server at WINT.GLOBAL, not in this browser. If a site blocks the server's requests, this shows it." }),
      el("div", { class: "row-actions" }, diagBtn), diagBox) : null,
    el("h2", { class: "section", text: "Recent refreshes" }),
    el("div", { class: "table-wrap", style: { maxHeight: "none" } }, el("table", { class: "grid tight" },
      el("thead", {}, el("tr", {}, ["Started", "Status", "New", "Changed", "Removed", "Located", "Note"].map((h, i) => el("th", { class: i >= 2 && i <= 5 ? "num" : null, text: h })))),
      el("tbody", {}, runs.length ? runs : el("tr", {}, el("td", { colspan: 7, class: "empty-row", text: "No refresh yet." }))))));
}

function searchForm(s, done) {
  const p = (s && s.params) || { category_id: 1758, region_id: 25, city_id: 268 };
  const f = (k, ph) => el("input", { type: "text", inputmode: "numeric", value: p[k] ?? "", placeholder: ph });
  const label = el("input", { type: "text", value: s ? s.label : "", placeholder: "e.g. Kyiv · Pecherskyi · flats for sale" });
  const cat = f("category_id", "1758"), reg = f("region_id", "25"), city = f("city_id", "268"), dist = f("district_id", "e.g. 19");
  const active = el("select", {}, el("option", { value: "1", selected: !s || s.active }, "On"), el("option", { value: "0", selected: s && !s.active }, "Off"));
  const save = el("button", { type: "button", class: "btn primary" }, icon("save"), s ? "Save search" : "Add search");
  save.addEventListener("click", async () => {
    try {
      await api(s ? "searches/" + s.id : "searches", { method: s ? "PUT" : "POST", body: {
        label: label.value, active: Number(active.value),
        params: { category_id: cat.value, region_id: reg.value, city_id: city.value, district_id: dist.value } } });
      toast(s ? "Search saved." : "Search added — the next refresh reads it.");
      done();
    } catch (e) { toast(e.message, true); }
  });
  return el("div", { class: "panel" },
    el("h3", { text: s ? "Edit “" + s.label + "”" : "Add an OLX search" }),
    el("p", { class: "explain" }, "The numbers OLX uses. Category 1758 is flats for sale, region 25 and city 268 are Kyiv. The district's number is in the address of an OLX search filtered by district: ",
      el("code", { text: "search[district_id]=19" }), " is Shevchenkivskyi."),
    el("div", { class: "fields-row" },
      el("label", { class: "wide" }, el("span", { text: "Label" }), label),
      el("label", {}, el("span", { text: "Category id" }), cat),
      el("label", {}, el("span", { text: "Region id" }), reg),
      el("label", {}, el("span", { text: "City id" }), city),
      el("label", {}, el("span", { text: "District id" }), dist),
      el("label", {}, el("span", { text: "Active" }), active)),
    el("div", { class: "row-actions" }, save, s ? el("button", { type: "button", class: "btn", onclick: done }, "Cancel") : null));
}
