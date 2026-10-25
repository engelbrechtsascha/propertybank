// FINATUM PropertyBank — the annunciator and the refresh.
//
// The annunciator says when the data were last refreshed, how many listings
// there are and at which exchange rate, and carries the Refresh button. A
// refresh runs on the server in steps of about 20 seconds; this page asks for
// one step after another and shows how far it has got. Leaving the page
// pauses it; coming back offers to continue.

import { el, put, icon, toast } from "./util.js";
import { api } from "./api.js";
import { store } from "./store.js";
import { fmtNum, fmtDateTime, fmtAgo, hoursBetween, fmtDate } from "./format.js";

let driving = false;
let currentHost = null;      // the annunciator on screen; the driver redraws whichever is current
let stopRequested = false;
const listeners = new Set();

/** Called when a refresh has finished, so the listings can be reloaded. */
export function onRefreshed(fn) { listeners.add(fn); return () => listeners.delete(fn); }

export function renderAnnunciator(host = currentHost) {
  if (!host) return;
  currentHost = host;
  const s = store.status;
  if (!s) { put(host); return; }
  const run = s.running;
  const last = s.last;

  if (run) {
    const pct = progressOf(run);
    const label = run.phase === "list" ? `${fmtNum(run.list.done)} of ${fmtNum(run.list.total)} listings read`
      : run.phase === "enrich" ? `${fmtNum(run.enrich.done)} of ${fmtNum(run.enrich.total)} new listings located`
      : run.phase === "translate" ? `${fmtNum(run.translate.done)} of ${fmtNum(run.translate.total)} listings translated into English`
      : run.phase_label;
    put(host, el("div", { class: "annunciator running", role: "status", "aria-live": "polite" },
      el("div", { class: "ann-main" },
        el("div", { class: "ann-title" }, icon("refresh", "spin"), driving ? "Refreshing — " + run.phase_label : "A refresh was interrupted"),
        el("div", { class: "ann-sub", text: driving ? label + ". Keep this page open; locating takes about a second per listing."
          : "Started " + fmtDateTime(run.started_at) + " — " + label + "." }),
        el("div", { class: "ann-bar" }, el("span", { style: { width: pct + "%" } })),
        run.message ? el("div", { class: "ann-msg", text: run.message }) : null),
      el("div", { class: "ann-actions" },
        driving
          ? el("button", { type: "button", class: "btn", onclick: () => { stopRequested = true; } }, icon("pause"), "Pause")
          : el("button", { type: "button", class: "btn primary", onclick: () => drive() }, icon("play"), "Continue"),
        el("button", { type: "button", class: "btn", onclick: () => cancel() }, "Stop"))));
    return;
  }

  const age = last ? hoursBetween(last.finished_at, s.now) : Infinity;
  const fx = s.fx;
  put(host, el("div", { class: "annunciator" + (age > 24 ? " stale" : "") },
    el("div", { class: "ann-main" },
      el("div", { class: "ann-title" },
        last ? ["Data last refreshed ", el("b", { text: fmtDateTime(last.finished_at) }), el("span", { class: "ann-ago", text: " · " + fmtAgo(last.finished_at, s.now) })]
          : "Not refreshed yet — press Refresh to read the listings from OLX"),
      el("div", { class: "ann-sub" },
        fmtNum(s.counts.active) + " listings",
        s.counts.pending ? " (" + fmtNum(s.counts.pending) + " not located yet)" : "",
        fx ? " · 1 EUR = " + fx.eurusd.toFixed(4) + " USD (" + fx.source + ", " + fmtDate(fx.rate_date || fx.fetched_at) + ")" : "",
        last && last.message ? el("span", { class: "ann-msg-inline", text: " · Last run: " + last.message }) : null)),
    el("div", { class: "ann-actions" },
      el("button", { type: "button", class: "btn primary", onclick: () => start() }, icon("refresh"), "Refresh"))));
}

function progressOf(run) {
  // Reading, locating and translating each take a part of the bar.
  const f = (x) => (x && x.total ? x.done / x.total : 0);
  const p = run.phase === "fx" || run.phase === "plan" ? 2 : run.phase === "list" ? 3 + 25 * f(run.list)
    : run.phase === "enrich" ? 28 + 40 * f(run.enrich) : run.phase === "translate" ? 68 + 30 * f(run.translate) : 99;
  return Math.max(2, Math.min(100, Math.round(p)));
}

async function start() {
  try {
    const r = await api("refresh/start", { method: "POST" });
    store.status = { ...store.status, running: r.run };
    drive();
  } catch (e) { toast(e.message, true); }
}

async function cancel() {
  stopRequested = true;
  try {
    const r = await api("refresh/cancel", { method: "POST" });
    store.status = r.status;
    toast("Refresh stopped.");
  } catch (e) { toast(e.message, true); }
  renderAnnunciator();
}

async function drive() {
  if (driving) return;
  driving = true;
  stopRequested = false;
  let errors = 0;
  renderAnnunciator();
  try {
    while (!stopRequested) {
      let r;
      try {
        r = await api("refresh/step", { method: "POST", body: { run: store.status.running && store.status.running.id } });
        errors = 0;
      } catch (e) {
        if (++errors >= 3) throw e;
        await new Promise((res) => setTimeout(res, 3000));
        continue;
      }
      if (r.run && r.run.status === "running") {
        store.status = { ...store.status, running: r.run };
        renderAnnunciator();
        continue;
      }
      // Finished, failed or stopped elsewhere.
      if (r.status) store.status = r.status;
      if (r.run && r.run.status === "failed") toast(r.run.message, true);
      else if (r.run && r.run.status === "done") toast("Refresh complete: " + r.run.message);
      for (const fn of listeners) await fn();
      break;
    }
  } catch (e) {
    toast("The refresh paused: " + e.message, true);
  } finally {
    driving = false;
    renderAnnunciator();
  }
}
