// FINATUM PropertyBank — the listings: the annunciator, the search, the table.
//
//   #/listings          every listing that matches the filters, sortable by
//                       any column but the link; 100 to a page
//
// The filters and the sort are remembered on this device. A click on a row
// opens everything stored about the listing with a large map; a click on the
// link opens the listing itself on OLX, in a window of its own.

import { el, put, icon, memory, debounce, toast } from "./util.js";
import { store } from "./store.js";
import { api } from "./api.js";
import { openModal } from "./dialog.js";
import { renderAnnunciator, onRefreshed } from "./refresh.js";
import { schematicMap } from "./map.js";
import { fmtNum, fmtM, fmtMoney, fmtDate, fmtDateTime, plural } from "./format.js";

const PAGE = 100;

const QUALITY = {
  exact: ["exact", "Address entered by the seller on OLX"],
  text: ["from text", "Address found in the title or description"],
  street: ["street", "Street only — distances to a point on the street"],
  approx: ["≈ 1 km", "No address — OLX's map point, within about 1 km"],
  none: ["no location", "No usable address — no distances"],
};

const DEFAULT_FILTERS = { city: "", district: "", street: "", lm: {}, floorMin: "", floorMax: "", floorsMin: "", floorsMax: "",
  areaMin: "", areaMax: "", priceMin: "", priceMax: "", priceCur: "USD", ppmMin: "", ppmMax: "", ppmCur: "USD", removed: false,
  changed: false, separate: false, marked: false };

/** Filters as they start: Kyiv and its Shevchenkivskyi district. */
function defaultFilters() {
  const kyiv = store.cities.find((c) => c.name_en === "Kyiv") || store.cities[0];
  return { ...DEFAULT_FILTERS, city: kyiv ? String(kyiv.id) : "", district: "Shevchenkivskyi", v: 2 };
}

/** A flat is marked when any of its ads is. */
function isMarked(f) {
  return (f._ads || [f]).some((a) => store.marked.has(a.src + ":" + a.sid));
}

/** Marks or unmarks a flat (all its ads) on the server. */
async function toggleMark(f) {
  const on = !isMarked(f);
  const r = await api("marks", { method: "POST", body: { ads: (f._ads || [f]).map((a) => a.src + ":" + a.sid), on } });
  store.marked = new Set(r.marked);
  return on;
}

function markButton(f, onChange) {
  const b = el("button", { type: "button", class: "mark-btn" + (isMarked(f) ? " on" : ""), "aria-pressed": isMarked(f) ? "true" : "false",
    title: isMarked(f) ? "Marked as of interest — click to unmark" : "Mark as of interest" }, isMarked(f) ? "★" : "☆");
  b.addEventListener("click", async (ev) => {
    ev.stopPropagation();
    b.disabled = true;
    try {
      const on = await toggleMark(f);
      b.textContent = on ? "★" : "☆";
      b.classList.toggle("on", on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
      b.title = on ? "Marked as of interest — click to unmark" : "Mark as of interest";
      if (onChange) onChange(on);
    } catch (e) { toast(e.message, true); } finally { b.disabled = false; }
  });
  return b;
}

/**
 * One row per flat: the ads the server grouped as the same flat (same building,
 * floor, floorspace — see listings_dedupe) become one entry, shown with its
 * main ad (renewed most recently) and carrying all of them in _ads. With
 * "Show each ad separately" every ad is its own row.
 */
function flats() {
  const visible = store.listings.filter((l) => filters.removed || l.active);
  if (filters.separate) return visible.map((l) => ({ ...l, _ads: [l], _flag: l.flag }));
  const groups = new Map();
  for (const l of visible) {
    const g = groups.get(l.group);
    if (g) g.push(l); else groups.set(l.group, [l]);
  }
  const out = [];
  for (const ads of groups.values()) {
    ads.sort((a, b) => String(b.site_refreshed || b.posted || "").localeCompare(String(a.site_refreshed || a.posted || "")) || a.id - b.id);
    const main = ads.find((a) => a.id === a.group) || ads[0];
    const flags = ads.map((a) => a.flag);
    const flag = flags.every((f) => f === "new") ? "new" : flags.some((f) => f) ? "updated" : null;
    out.push({ ...main, _ads: ads, _flag: flag });
  }
  return out;
}

/** Price per square metre in a currency, or null. */
function ppm(l, cur) {
  const p = cur === "EUR" ? l.eur : l.usd;
  return p !== null && l.area ? p / l.area : null;
}

let filters = { ...DEFAULT_FILTERS, ...memory.get("filters", {}) };
let sort = memory.get("sort", { key: "posted", dir: -1 });
let page = 0;
let unsubscribe = null;
let redraw = () => {};
/** The listing last opened: its row stays marked, so you see where you were. */
let selectedId = memory.get("selected", null);

/* ------------------------------------------------------------ columns */

/** Landmarks by key, across the cities in the data: one distance column each. */
function landmarkColumns() {
  const seen = new Map();
  for (const c of store.cities) for (const lm of c.landmarks || []) if (!seen.has(lm.key)) seen.set(lm.key, lm.name);
  return [...seen.entries()].map(([key, name]) => ({ key, name }));
}

function columns() {
  const cols = [
    { key: "mark", label: "★", title: "Marked as of interest", get: (l) => (isMarked(l) ? 0 : 1), cls: "c-mark-cell",
      cell: (l) => markButton(l, () => { if (filters.marked) redraw(); }) },
    { key: "city", label: "City", get: (l) => l.city, cell: (l) => l.city || "—" },
    { key: "district", label: "District", get: (l) => l.district, cell: (l) => l.district || "—" },
    { key: "address", label: "Address", get: (l) => l.address || null, cls: "addr",
      cell: (l) => [l._flag ? el("span", { class: "flag flag-" + l._flag, text: l._flag }) : null,
        el("span", { class: "a-en", text: l.address || "—" }), l.address_o ? el("span", { class: "sub", text: l.address_o }) : null,
        l._ads && l._ads.length > 1 ? el("span", { class: "ads-badge", title: "The same flat in " + l._ads.length + " OLX ads — open it to see them all", text: l._ads.length + " ads" }) : null,
        el("span", { class: "q q-" + l.q, title: QUALITY[l.q][1], text: QUALITY[l.q][0] })] },
    { key: "floor", label: "Floor", num: true, get: (l) => l.floor, cell: (l) => l.floor === null ? "—" : l.floor + (l.floors !== null ? "/" + l.floors : "") },
    { key: "area", label: "m²", num: true, get: (l) => l.area, cell: (l) => fmtNum(l.area, l.area % 1 ? 1 : 0) },
    { key: "ceiling", label: "Ceiling", num: true, get: (l) => l.ceiling, cell: (l) => l.ceiling === null ? "—" : fmtNum(l.ceiling, 2) + " m" },
    { key: "year", label: "Built", num: true, get: (l) => l.year, cell: (l) => l.year === null ? "—" : String(l.year) },
    { key: "usd", label: "Price USD", num: true, get: (l) => l.usd, cell: (l) => priceCell(l, "USD") },
    { key: "eur", label: "Price EUR", num: true, get: (l) => l.eur, cell: (l) => priceCell(l, "EUR") },
    { key: "ppm_usd", label: "USD/m²", num: true, title: "Price per square metre in USD", get: (l) => ppm(l, "USD"), cell: (l) => ppmCell(l, "USD") },
    { key: "ppm_eur", label: "EUR/m²", num: true, title: "Price per square metre in EUR", get: (l) => ppm(l, "EUR"), cell: (l) => ppmCell(l, "EUR") },
  ];
  for (const lm of landmarkColumns()) {
    cols.push({ key: "lm:" + lm.key, label: lm.name, num: true, title: "Distance to " + lm.name + " in metres, as the crow flies",
      get: (l) => (l.geo && l.geo.lm && l.geo.lm[lm.key] !== undefined ? l.geo.lm[lm.key] : null), cell: (l) => fmtM(l.geo && l.geo.lm ? l.geo.lm[lm.key] : null) });
  }
  for (const i of [0, 1]) {
    cols.push({ key: "st" + i, label: "Metro " + (i + 1), num: true, title: (i ? "Second" : "Nearest") + " metro station, as the crow flies",
      get: (l) => (l.geo && l.geo.st[i] ? l.geo.st[i].m : null),
      cell: (l) => { const s = l.geo && l.geo.st[i]; return s ? [el("span", { class: "st-dot", style: { background: s.c || "#64748B" } }), fmtM(s.m), el("span", { class: "sub", text: s.n })] : "—"; } });
  }
  cols.push(
    { key: "map", label: "Map", cell: (l) => el("span", { class: "thumb-wrap", title: "Open the map" }, schematicMap(l, store.city(l.city_id), { w: 92, h: 56, labels: false })) },
    // As OLX shows it ("Опубліковано …"): the last time the seller renewed the
    // listing; the first posting below it when that was on another day.
    { key: "posted", label: "Posted", num: true, title: "The date OLX shows: when the listing was last renewed. Below it, when it was first posted.",
      get: (l) => l.site_refreshed || l.posted,
      cell: (l) => [fmtDate(l.site_refreshed || l.posted),
        l.site_refreshed && l.posted && l.site_refreshed.slice(0, 10) !== l.posted.slice(0, 10) ? el("span", { class: "sub", text: "first " + fmtDate(l.posted) }) : null,
        !l.active ? el("span", { class: "sub gone", text: "removed" }) : null] },
    { key: "link", label: "Listing", cell: (l) => el("a", { href: l.url, class: "olx-link", target: "pb-listing", rel: "noopener",
      title: "Open the listing on OLX in its own window", onclick: (ev) => { ev.stopPropagation(); ev.preventDefault(); openListing(l.url); } }, icon("link"), "OLX") },
  );
  return cols;
}

function priceCell(l, cur) {
  const v = cur === "USD" ? l.usd : l.eur;
  if (v === null) return "—";
  const listed = l.pc === cur;
  if (listed) return [el("b", { class: "price-listed", title: "As listed" }, fmtMoney(v, cur)),
    l.prev_pv !== null && l.prev_pv !== undefined && l.prev_pc === cur ? el("span", { class: "sub was", text: "was " + fmtMoney(l.prev_pv, cur) }) : null];
  const note = l.pc === "UAH" ? "Listed in hryvnia: " + fmtMoney(l.pv, "UAH") + "; converted at the last refresh's rate" : "Converted at the last refresh's rate";
  return el("span", { class: "price-conv", title: note }, "≈ " + fmtMoney(v, cur));
}

function ppmCell(l, cur) {
  const v = ppm(l, cur);
  if (v === null) return "—";
  return el("span", { class: l.pc === cur ? null : "price-conv" }, (l.pc === cur ? "" : "≈ ") + fmtMoney(v, cur));
}

/** The listing on OLX, in a window of its own (one window, reused). */
export function openListing(url) {
  const w = window.open(url, "pb-listing", "popup=yes,width=1180,height=900");
  if (w) { try { w.opener = null; } catch { /* cross-origin already */ } w.focus(); }
  else window.open(url, "_blank", "noopener");     // a blocked pop-up: a new tab instead
}

/* ------------------------------------------------------------ filters */

function num(v) {
  if (v === "" || v === null || v === undefined) return null;
  const n = Number(String(v).replace(/[\s,]/g, ""));
  return Number.isFinite(n) ? n : null;
}

function matches(l) {
  if (!filters.removed && !l.active) return false;
  if (filters.changed && !l._flag) return false;
  if (filters.marked && !isMarked(l)) return false;
  if (filters.city && String(l.city_id) !== String(filters.city)) return false;
  if (filters.district && l.district !== filters.district) return false;
  if (filters.street) {
    const q = filters.street.trim().toLowerCase();
    const hay = [l.street, l.street_o, l.address, l.address_o].join(" ").toLowerCase();
    if (!hay.includes(q)) return false;
  }
  for (const [key, max] of Object.entries(filters.lm || {})) {
    const m = num(max);
    if (m === null) continue;
    const d = l.geo && l.geo.lm ? l.geo.lm[key] : undefined;
    if (d === undefined || d === null || d > m) return false;
  }
  /** Outside [min, max]? An empty value is outside as soon as a limit is set. */
  const out = (v, min, max) => { const a = num(min), b = num(max); return (a !== null || b !== null) && (v === null || v === undefined || (a !== null && v < a) || (b !== null && v > b)); };
  if (out(l.floor, filters.floorMin, filters.floorMax)) return false;
  if (out(l.floors, filters.floorsMin, filters.floorsMax)) return false;
  if (out(l.area, filters.areaMin, filters.areaMax)) return false;
  if (out(filters.priceCur === "EUR" ? l.eur : l.usd, filters.priceMin, filters.priceMax)) return false;
  if (out(ppm(l, filters.ppmCur), filters.ppmMin, filters.ppmMax)) return false;
  return true;
}

function sorted(list, cols) {
  const col = cols.find((c) => c.key === sort.key && c.get) || cols.find((c) => c.key === "posted");
  const dir = sort.dir;
  return list.slice().sort((a, b) => {
    const x = col.get(a), y = col.get(b);
    if (x === null || x === undefined || x === "") return (y === null || y === undefined || y === "") ? 0 : 1;   // empty always last
    if (y === null || y === undefined || y === "") return -1;
    if (typeof x === "number" && typeof y === "number") return (x - y) * dir;
    return String(x).localeCompare(String(y), "en") * dir;
  });
}

function save() {
  memory.set("filters", filters);
  memory.set("sort", sort);
}

/* --------------------------------------------------------------- page */

export async function renderListings(view) {
  if (!store.listings) {
    put(view, el("div", { class: "loading", text: "Loading listings…" }));
    await store.load(filters.removed);
  }
  // Kyiv · Shevchenkivskyi as the starting point (once, for filters saved before 0.3.1).
  if (filters.v !== 2) {
    const d = defaultFilters();
    filters = { ...filters, city: d.city, district: d.district, v: 2 };
    save();
  }
  if (unsubscribe) unsubscribe();
  unsubscribe = onRefreshed(async () => { await store.load(); if (document.body.contains(view) && location.hash.startsWith("#/listings")) { drawFilters(); draw(); } });

  const ann = el("div", { class: "ann-host" });
  const panel = el("div", { class: "panel filters" });
  const results = el("div", { class: "results" });

  put(view,
    el("div", { class: "view-head" }, el("div", {},
      el("div", { class: "eyebrow", text: "OLX.ua · flats for sale" }),
      el("h1", { class: "view-title", text: "Listings" }))),
    ann, panel, results);

  renderAnnunciator(ann);
  drawFilters();
  draw();
  redraw = draw;

  function drawFilters() {
    const cityOpts = [el("option", { value: "" }, "All cities")].concat(store.cities.map((c) => el("option", { value: String(c.id), selected: String(c.id) === String(filters.city) }, c.name_en)));
    const pool = store.listings.filter((l) => !filters.city || String(l.city_id) === String(filters.city));
    const districts = [...new Set(pool.map((l) => l.district).concat(filters.district ? [filters.district] : []).filter(Boolean))].sort();
    const distOpts = [el("option", { value: "" }, "All districts")].concat(districts.map((d) => el("option", { value: d, selected: d === filters.district }, d)));

    const inp = (key, attrs) => {
      const i = el("input", { type: "text", inputmode: "numeric", value: filters[key] ?? "", ...attrs });
      i.addEventListener("input", debounce(() => { filters[key] = i.value; page = 0; save(); draw(); }, 200));
      return i;
    };
    const city = el("select", {}, cityOpts);
    city.addEventListener("change", () => { filters.city = city.value; filters.district = ""; page = 0; save(); drawFilters(); draw(); });
    const district = el("select", {}, distOpts);
    district.addEventListener("change", () => { filters.district = district.value; page = 0; save(); draw(); });
    const street = el("input", { type: "search", value: filters.street, placeholder: "e.g. Yaroslaviv or Ярославів" });
    street.addEventListener("input", debounce(() => { filters.street = street.value; page = 0; save(); draw(); }, 200));
    const curSel = (key, label) => {
      const c = el("select", { "aria-label": label }, ["USD", "EUR"].map((x) => el("option", { value: x, selected: filters[key] === x }, x)));
      c.addEventListener("change", () => { filters[key] = c.value; page = 0; save(); draw(); });
      return c;
    };
    const markedOnly = el("input", { type: "checkbox", checked: !!filters.marked });
    markedOnly.addEventListener("change", () => { filters.marked = markedOnly.checked; page = 0; save(); draw(); });
    const changed = el("input", { type: "checkbox", checked: !!filters.changed });
    changed.addEventListener("change", () => { filters.changed = changed.checked; page = 0; save(); draw(); });
    const separate = el("input", { type: "checkbox", checked: !!filters.separate });
    separate.addEventListener("change", () => { filters.separate = separate.checked; page = 0; save(); draw(); });
    const removed = el("input", { type: "checkbox", checked: !!filters.removed });
    removed.addEventListener("change", async () => {
      filters.removed = removed.checked; page = 0; save();
      if (filters.removed && !store.withRemoved) { await store.load(true); }
      draw();
    });

    const lmCity = filters.city ? store.city(Number(filters.city)) : null;
    const lms = lmCity ? lmCity.landmarks : landmarkColumns();
    const lmFields = lms.map((lm) => {
      const i = el("input", { type: "text", inputmode: "numeric", value: (filters.lm || {})[lm.key] ?? "", placeholder: "metres" });
      i.addEventListener("input", debounce(() => { filters.lm = { ...(filters.lm || {}), [lm.key]: i.value }; page = 0; save(); draw(); }, 200));
      return el("label", {}, el("span", { text: lm.name + " (m)", title: "Maximum distance to " + lm.name + " in metres" }), i);
    });

    /** A framed group of fields with a caption. */
    const group = (caption, cls, ...fields) => el("fieldset", { class: "fgroup " + (cls || "") }, el("legend", { text: caption }), el("div", { class: "fgroup-fields" }, fields));
    const range = (minKey, maxKey, unit) => [
      el("label", {}, el("span", { text: "Min" + (unit ? " (" + unit + ")" : "") }), inp(minKey, { placeholder: "min" })),
      el("label", {}, el("span", { text: "Max" + (unit ? " (" + unit + ")" : "") }), inp(maxKey, { placeholder: "max" })),
    ];

    put(panel,
      el("div", { class: "fgroups" },
        group("Location", "g-loc",
          el("label", {}, el("span", { text: "City" }), city),
          el("label", {}, el("span", { text: "District" }), district),
          el("label", { class: "wide" }, el("span", { text: "Street" }), street)),
        lmFields.length ? group("Max distance", "g-dist", lmFields) : null,
        group("Floor", "", range("floorMin", "floorMax")),
        group("Building floors", "", range("floorsMin", "floorsMax")),
        group("Size", "", range("areaMin", "areaMax", "m²")),
        group("Price", "", range("priceMin", "priceMax"), el("label", { class: "cur" }, el("span", { text: "Currency" }), curSel("priceCur", "Currency of the price limits"))),
        group("Price per m²", "", range("ppmMin", "ppmMax"), el("label", { class: "cur" }, el("span", { text: "Currency" }), curSel("ppmCur", "Currency of the price per m² limits")))),
      el("div", { class: "row-actions" },
        el("label", { class: "check" }, markedOnly, el("span", { text: "Only marked ★" })),
        el("label", { class: "check" }, changed, el("span", { text: "Only new and updated (last refresh)" })),
        el("label", { class: "check" }, separate, el("span", { text: "Show each ad separately" })),
        el("label", { class: "check" }, removed, el("span", { text: "Include listings no longer on OLX" })),
        el("span", { class: "spacer" }),
        el("button", { type: "button", class: "btn small", title: "Back to Kyiv · Shevchenkivskyi, nothing else set",
          onclick: () => { filters = defaultFilters(); page = 0; save(); drawFilters(); draw(); } }, icon("reset"), "Clear filters")));
  }

  function draw() {
    const cols = columns();
    const everything = flats();
    const all = everything.filter(matches);
    const list = sorted(all, cols);
    const pages = Math.max(1, Math.ceil(list.length / PAGE));
    page = Math.min(page, pages - 1);
    const rows = list.slice(page * PAGE, page * PAGE + PAGE);
    const distLimited = Object.values(filters.lm || {}).some((v) => num(v) !== null);

    const head = el("tr", {}, cols.map((c) => {
      if (!c.get) return el("th", { class: c.num ? "num" : null, text: c.label });
      const on = sort.key === c.key;
      return el("th", { class: (c.num ? "num " : "") + (on ? "sorted" : ""), title: c.title || null, "aria-sort": on ? (sort.dir > 0 ? "ascending" : "descending") : null },
        el("button", { type: "button", class: "th-sort", onclick: () => {
          sort = on ? { key: c.key, dir: -sort.dir } : { key: c.key, dir: c.num ? 1 : 1 };
          save(); draw();
        } }, c.label, el("span", { class: "sort", text: on ? (sort.dir > 0 ? "▲" : "▼") : "↕" })));
    }));
    const body = rows.length ? rows.map((l) => el("tr", { class: [l.active ? "" : "inactive", l.id === selectedId ? "selected" : ""].join(" ").trim() || null,
      dataset: { id: String(l.id) }, onclick: (ev) => { select(l.id, ev.currentTarget); detail(l); } },
      cols.map((c) => el("td", { class: [c.num ? "num" : "", c.cls || "", "c-" + c.key.replace(":", "-")].join(" ").trim() }, c.cell(l)))))
      : [el("tr", {}, el("td", { class: "empty-row", colspan: cols.length, text: store.listings.length ? "No listing matches these filters." : "No listings yet — press Refresh above." }))];

    const pager = (scroll) => el("div", { class: "pager" },
      el("button", { type: "button", class: "btn small", disabled: page === 0, onclick: () => { page--; draw(); if (scroll) results.scrollIntoView(); } }, "‹ Previous"),
      el("span", { class: "faint", text: "Page " + (page + 1) + " of " + pages }),
      el("button", { type: "button", class: "btn small", disabled: page >= pages - 1, onclick: () => { page++; draw(); if (scroll) results.scrollIntoView(); } }, "Next ›"));

    put(results,
      dashboard(list, everything),
      el("div", { class: "toolbar" },
        distLimited ? el("span", { class: "faint", text: "Listings without a location are left out while a distance limit is set." }) : null,
        el("span", { class: "grow" }), pages > 1 ? pager(false) : null),
      el("div", { class: "table-wrap listings-wrap" }, el("table", { class: "grid listings" }, el("thead", {}, head), el("tbody", {}, body))),
      pages > 1 ? el("div", { class: "toolbar bottom" }, el("span", { class: "grow" }), pager(true)) : null);
  }
}

/** Marks a row as the selected one (and remembers it on this device). */
function select(id, row) {
  selectedId = id;
  memory.set("selected", id);
  const tbody = row && row.parentElement;
  if (tbody) for (const r of tbody.querySelectorAll("tr.selected")) r.classList.remove("selected");
  if (row) row.classList.add("selected");
}

/* ---------------------------------------------------------- dashboard */

/** Ranges of what the filters let through: one tile each, as in PilotLog. */
function dashboard(list, everything) {
  const range = (vals) => {
    const v = vals.filter((x) => x !== null && x !== undefined && Number.isFinite(x));
    return v.length ? [Math.min(...v), Math.max(...v)] : null;
  };
  const money = (x, cur) => {
    const sym = cur === "EUR" ? "€" : "$";
    if (x >= 1e6) return sym + (Math.round(x / 1e4) / 100).toLocaleString("en-GB") + "m";
    if (x >= 1e4) return sym + (Math.round(x / 100) / 10).toLocaleString("en-GB") + "k";
    return sym + fmtNum(x, 0);
  };
  const show = (r, f) => (r ? (r[0] === r[1] ? f(r[0]) : f(r[0]) + " – " + f(r[1])) : "—");
  const tile = (value, label, cls) => el("div", { class: "dtile" + (cls ? " " + cls : "") }, el("b", { text: value }), el("span", { text: label }));
  const ads = list.reduce((n, f) => n + (f._ads ? f._ads.length : 1), 0);
  const allAds = everything.reduce((n, f) => n + (f._ads ? f._ads.length : 1), 0);
  const city = filters.city ? store.city(Number(filters.city)) : null;
  const lms = (city ? city.landmarks : landmarkColumns()).slice(0, 2);
  return el("div", { class: "dash" },
    tile(fmtNum(list.length) + " / " + fmtNum(everything.length), (filters.separate ? "ads" : "flats") + " shown" + (filters.separate ? "" : " · " + fmtNum(ads) + " of " + fmtNum(allAds) + " ads"), "lead"),
    tile(show(range(list.map((l) => l.area)), (x) => fmtNum(x, 0)), "m²"),
    tile(show(range(list.map((l) => l.usd)), (x) => money(x, "USD")), "price USD"),
    tile(show(range(list.map((l) => l.eur)), (x) => money(x, "EUR")), "price EUR"),
    tile(show(range(list.map((l) => ppm(l, "USD"))), (x) => "$" + fmtNum(x, 0)), "USD / m²"),
    tile(show(range(list.map((l) => ppm(l, "EUR"))), (x) => "€" + fmtNum(x, 0)), "EUR / m²"),
    lms.map((lm) => tile(show(range(list.map((l) => (l.geo && l.geo.lm ? l.geo.lm[lm.key] : null))), (x) => fmtNum(x, 0)) + " m", "to " + lm.name)));
}

/* ------------------------------------------------------------- detail */

function detail(l) {
  const city = store.city(l.city_id);
  // Header: the address, and under it the listing's title in English with the original in small print.
  const sub = el("div", { class: "modal-sub" },
    el("div", { class: "title-en", text: l.title_en || l.title }),
    l.title_en ? el("div", { class: "title-orig", text: l.title }) : null);
  const m = openModal({ title: l.address || "Address unknown", eyebrow: [l.city, l.district].filter(Boolean).join(" · "), size: "wide",
    subtitle: sub, draggable: true, extra: markButton(l, () => redraw()) });
  const row = (k, v) => el("tr", {}, el("th", { text: k }), el("td", {}, v));
  // Distances under the map: the landmarks in one row, the two stations in the next.
  const tile = (label, value, sub, dot) => el("div", { class: "dist" },
    el("div", { class: "dist-l" }, dot ? el("span", { class: "st-dot", style: { background: dot } }) : null, label),
    el("b", { text: value }), sub ? el("div", { class: "dist-s", text: sub }) : null);
  const lmTiles = (city ? city.landmarks : []).slice(0, 4).map((lm) => tile(lm.name, fmtM(l.geo && l.geo.lm ? l.geo.lm[lm.key] : null)));
  const stTiles = [0, 1].map((i) => { const s = l.geo && l.geo.st[i]; return tile("Metro " + (i + 1), s ? fmtM(s.m) : "—", s ? s.n + (s.l ? " (" + s.l + ")" : "") : null, s ? s.c || "#64748B" : null); });
  const price = l.pv === null ? "—" : fmtMoney(l.pv, l.pc) + " as listed" +
    (l.pc !== "USD" && l.usd !== null ? " · ≈ " + fmtMoney(l.usd, "USD") : "") + (l.pc !== "EUR" && l.eur !== null ? " · ≈ " + fmtMoney(l.eur, "EUR") : "") +
    (l.prev_pv !== null && l.prev_pv !== undefined ? " · was " + fmtMoney(l.prev_pv, l.prev_pc) + " before the last refresh" : "");

  // The same flat in several ads: all of them, with their prices and links.
  const ads = l._ads && l._ads.length > 1 ? el("div", { class: "ads" },
    el("h3", {}, "Advertised " + l._ads.length + " times on OLX",
      el("span", { class: "faint", text: "the same building, floor and floorspace — different sellers often ask different prices" })),
    el("table", { class: "grid tight ads-table" },
      el("thead", {}, el("tr", {}, el("th", { text: "" }), el("th", { text: "Title" }), el("th", { class: "num", text: "Price" }),
        el("th", { class: "num", text: "m²" }), el("th", { text: "Posted" }), el("th", { text: "" }))),
      el("tbody", {}, l._ads.map((a) => el("tr", { class: a.id === l.id ? "this-ad" : null },
        el("td", {}, a.flag ? el("span", { class: "flag flag-" + a.flag, text: a.flag }) : null),
        el("td", { class: "ad-title", text: a.title_en || a.title }),
        el("td", { class: "num" }, a.pv === null ? "—" : fmtMoney(a.pv, a.pc)),
        el("td", { class: "num", text: fmtNum(a.area, a.area % 1 ? 1 : 0) }),
        el("td", { text: fmtDate(a.site_refreshed || a.posted) }),
        el("td", {}, el("a", { href: a.url, class: "olx-link", onclick: (ev) => { ev.preventDefault(); openListing(a.url); } }, icon("link"), "OLX"))))))) : null;

  const descr = el("div", { class: "descr" }, el("h3", {}, "Description"), el("div", { class: "loading", text: "Loading the description…" }));
  m.body.append(el("div", { class: "detail" },
    el("table", { class: "kv" }, el("tbody", {},
      row("City", l.city + (l.city_o && l.city_o !== l.city ? " (" + l.city_o + ")" : "")),
      row("District", l.district ? l.district + " (" + l.district_o + ")" : "—"),
      row("Address", [l.address || "—", l.address_o ? el("span", { class: "sub", text: l.address_o }) : null]),
      row("Location", [el("span", { class: "q q-" + l.q, text: QUALITY[l.q][0] }), el("span", { class: "loc-text", text: QUALITY[l.q][1] })]),
      row("Floor", l.floor === null ? "—" : l.floor + (l.floors !== null ? " of " + l.floors : "")),
      row("Floorspace", l.area === null ? "—" : fmtNum(l.area, l.area % 1 ? 1 : 0) + " m²" + (l.rooms ? " · " + plural(l.rooms, "room") : "")),
      row("Ceiling height", l.ceiling === null ? "not stated" : fmtNum(l.ceiling, 2) + " m"),
      row("Year of construction", l.year === null ? "not stated" : String(l.year)),
      row("Price", price),
      row("Price per m²", l.area && l.usd !== null ? fmtMoney(ppm(l, "USD"), "USD") + " · " + fmtMoney(ppm(l, "EUR"), "EUR") : "—"),
      row("Posted on OLX", fmtDateTime(l.site_refreshed || l.posted) + (l.site_refreshed && l.posted && l.site_refreshed.slice(0, 10) !== l.posted.slice(0, 10) ? " (renewed) · first posted " + fmtDate(l.posted) : "")),
      row("In PropertyBank", "since " + fmtDate(l.first_seen) + (l.active ? "" : " · no longer on OLX since " + fmtDate(l.last_seen))))),
    el("div", { class: "detail-map" }, schematicMap(l, city, { w: 520, h: 380, labels: true }),
      el("div", { class: "dists" }, lmTiles),
      el("div", { class: "dists" }, stTiles),
      el("p", { class: "faint small", text: "North up. Distances in metres as the crow flies." })),
    ads, descr));
  loadDescription(l, descr);
  m.foot.append(
    el("span", { class: "spacer" }),
    el("button", { type: "button", class: "btn", onclick: () => m.close() }, "Close"),
    el("button", { type: "button", class: "btn primary", onclick: () => openListing(l.url) }, icon("link"), "Open on OLX"));
}

/** The title and description in English (translated on the server the first time), with the original beneath. */
async function loadDescription(l, host) {
  let d;
  try {
    d = await api("listings/" + l.id + "/description");
  } catch (e) {
    put(host, el("h3", {}, "Description"), el("p", { class: "form-error-box", text: e.message }));
    return;
  }
  const en = d.description_en;
  const original = el("details", {}, el("summary", { text: en ? "Original (" + (d.from || "Ukrainian/Russian") + ")" : "Original" }),
    el("div", { class: "descr-text", text: d.description || "—" }));
  if (!en) original.open = true;
  put(host,
    el("h3", {}, "Description",
      en ? el("span", { class: "faint", text: "translated by " + (d.service || "machine translation") + (d.translated_at ? " · " + fmtDate(d.translated_at) : "") })
        : el("span", { class: "faint", text: d.note || "" })),
    en ? el("div", { class: "descr-text", text: en }) : null,
    original);
}
