// FINATUM PropertyBank — cities: the landmarks distances are measured to,
// and the metro stations.
//
//   #/cities     per city: its landmarks (up to six; the first two appear on
//                the schematic map), and its stations as editable lines.
//                Saving recomputes the distances of every listing of that
//                city at once.

import { el, put, icon, toast } from "./util.js";
import { api } from "./api.js";
import { store } from "./store.js";
import { fmtDateTime, fmtNum } from "./format.js";

export async function renderCities(view) {
  put(view, el("div", { class: "loading", text: "Loading cities…" }));
  let cities;
  try { cities = (await api("cities")).cities; } catch (e) { put(view, el("div", { class: "empty", text: e.message })); return; }
  const admin = store.user && store.user.role === "admin";

  put(view,
    el("div", { class: "view-head" }, el("div", {},
      el("div", { class: "eyebrow", text: "Configuration" }),
      el("h1", { class: "view-title", text: "Cities" }),
      el("p", { class: "view-sub", text: "The landmarks each listing's distance is measured to, and the metro stations. The first two landmarks are drawn on the schematic map. Distances are straight lines (great circle), in metres." }))),
    cities.map((c) => cityPanel(c, admin, view)),
    admin ? newCityPanel(view) : null);
}

function cityPanel(city, admin, view) {
  let landmarks = city.landmarks.map((l) => ({ ...l }));
  const lmBody = el("tbody");
  const stText = el("textarea", { rows: 10, spellcheck: "false", class: "mono", readonly: !admin },
    city.stations.map((s) => [s.name, s.name_orig || "", s.line || "", s.colour || "", s.lat, s.lon].join("; ")).join("\n"));

  function drawLandmarks() {
    put(lmBody, landmarks.map((l, i) => {
      const name = el("input", { type: "text", value: l.name, readonly: !admin, "aria-label": "Landmark name" });
      const lat = el("input", { type: "text", value: l.lat, readonly: !admin, "aria-label": "Latitude", inputmode: "decimal" });
      const lon = el("input", { type: "text", value: l.lon, readonly: !admin, "aria-label": "Longitude", inputmode: "decimal" });
      name.addEventListener("input", () => { l.name = name.value; });
      lat.addEventListener("input", () => { l.lat = lat.value; });
      lon.addEventListener("input", () => { l.lon = lon.value; });
      return el("tr", {},
        el("td", { class: "faint", text: i < 2 ? "on map" : "" }),
        el("td", {}, name), el("td", {}, lat), el("td", {}, lon),
        el("td", {}, admin ? el("button", { type: "button", class: "btn small", onclick: () => { landmarks.splice(i, 1); drawLandmarks(); } }, "Remove") : null));
    }));
  }
  drawLandmarks();

  const lookFor = el("input", { type: "text", placeholder: "e.g. Софійський собор, Київ" });
  const find = el("button", { type: "button", class: "btn small" }, icon("search"), "Find and add");
  find.addEventListener("click", async () => {
    const qText = lookFor.value.trim();
    if (!qText) return;
    find.disabled = true;
    try {
      const r = await api("geocode", { params: { q: qText } });
      if (!r.result) { toast("Nothing found for “" + qText + "”.", true); return; }
      landmarks.push({ key: "", name: qText.split(",")[0], lat: r.result.lat.toFixed(7), lon: r.result.lon.toFixed(7) });
      drawLandmarks();
      lookFor.value = "";
      toast("Found — check the name, then save.");
    } catch (e) { toast(e.message, true); } finally { find.disabled = false; }
  });

  const save = el("button", { type: "button", class: "btn primary" }, icon("save"), "Save and recompute distances");
  save.addEventListener("click", async () => {
    const stations = [];
    for (const [i, line] of stText.value.split("\n").entries()) {
      if (!line.trim()) continue;
      const p = line.split(";").map((x) => x.trim());
      if (p.length < 6) { toast("Station line " + (i + 1) + " needs six fields: name; original name; line; colour; latitude; longitude.", true); return; }
      stations.push({ name: p[0], name_orig: p[1], line: p[2], colour: p[3], lat: Number(p[4]), lon: Number(p[5]) });
    }
    save.disabled = true;
    try {
      const r = await api("cities/" + city.id, { method: "PUT", body: { landmarks: landmarks.map((l) => ({ ...l, lat: Number(l.lat), lon: Number(l.lon) })), stations } });
      toast("Saved. Distances of " + fmtNum(r.recomputed) + " listings recomputed.");
      store.listings = null;          // reload on the next visit to Listings
      renderCities(view);
    } catch (e) { toast(e.message, true); } finally { save.disabled = false; }
  });

  return el("div", { class: "panel" },
    el("h3", { text: city.name_en + (city.name_orig ? " · " + city.name_orig : "") }),
    el("p", { class: "explain", text: "OLX city id " + (city.olx_city_id ?? "—") + " · " + city.stations.length + " stations · changed " + fmtDateTime(city.updated_at) }),
    el("h4", { class: "mini-h", text: "Landmarks" }),
    el("div", { class: "table-wrap", style: { maxHeight: "none" } }, el("table", { class: "grid tight lm-table" },
      el("thead", {}, el("tr", {}, el("th", { text: "" }), el("th", { text: "Name" }), el("th", { text: "Latitude" }), el("th", { text: "Longitude" }), el("th", { text: "" }))),
      lmBody)),
    admin ? el("div", { class: "row-actions" }, el("label", { class: "grow-label" }, el("span", { text: "Add a landmark by name" }), lookFor), find,
      el("button", { type: "button", class: "btn small", onclick: () => { landmarks.push({ key: "", name: "", lat: "", lon: "" }); drawLandmarks(); } }, icon("plus"), "Add by coordinates")) : null,
    el("h4", { class: "mini-h", text: "Metro stations — one a line: name; original name; line; colour; latitude; longitude" }),
    stText,
    admin ? el("div", { class: "row-actions" }, save) : null);
}

function newCityPanel(view) {
  const name = el("input", { type: "text", placeholder: "e.g. Lviv" });
  const orig = el("input", { type: "text", placeholder: "e.g. Львів" });
  const olx = el("input", { type: "text", inputmode: "numeric", placeholder: "the city_id OLX uses" });
  const add = el("button", { type: "button", class: "btn" }, icon("plus"), "Add city");
  add.addEventListener("click", async () => {
    try {
      await api("cities", { method: "POST", body: { name_en: name.value, name_orig: orig.value, olx_city_id: olx.value } });
      toast("City added. Give it landmarks and stations, and a search on the Sources page.");
      renderCities(view);
    } catch (e) { toast(e.message, true); }
  });
  return el("div", { class: "panel" },
    el("h3", { text: "Another city" }),
    el("p", { class: "explain", text: "For when PropertyBank reads more than Kyiv. Listings are tied to a city by OLX's city id." }),
    el("div", { class: "fields-row" },
      el("label", {}, el("span", { text: "Name in English" }), name),
      el("label", {}, el("span", { text: "Original name" }), orig),
      el("label", {}, el("span", { text: "OLX city id" }), olx)),
    el("div", { class: "row-actions" }, add));
}
