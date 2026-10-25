// FINATUM PropertyBank — the one modal dialog every form opens in (the Backoffice's).

import { el, icon, clear } from "./util.js";

const dlg = () => document.getElementById("modal");
let onClose = null;

/**
 * Opens the dialog. Returns { body, foot, close, card }.
 * `size`: "narrow" | "wide" | "xwide" | undefined; `extra`: a node shown beside the close button;
 * `subtitle`: nodes shown under the title; `draggable`: the dialog can be moved by its header.
 */
export function openModal({ title, eyebrow, size, onclose, extra, subtitle, draggable } = {}) {
  const d = dlg();
  clear(d);
  const body = el("div", { class: "modal-body" });
  const foot = el("div", { class: "modal-foot" });
  const card = el("div", { class: "modal-card" + (size ? " " + size : "") },
    el("div", { class: "modal-head" },
      el("div", { style: { flex: "1", minWidth: "0" } },
        eyebrow ? el("div", { class: "eyebrow", text: eyebrow }) : null,
        el("h2", { id: "modalTitle", text: title || "" }),
        subtitle || null),
      extra || null,
      el("button", { type: "button", class: "icon-btn", "aria-label": "Close", onclick: () => close() }, icon("close"))),
    body, foot);
  d.append(card);
  if (draggable) makeDraggable(card, card.querySelector(".modal-head"));
  onClose = onclose || null;
  if (!d.open) d.showModal();
  function close() {
    if (d.open) d.close();
  }
  return { body, foot, close, card };
}

/**
 * Lets the dialog be moved by dragging its header (not on a phone, where it
 * is a sheet at the bottom of the screen). It stays within the window.
 */
function makeDraggable(card, handle) {
  handle.classList.add("drag-handle");
  handle.title = "Drag to move";
  handle.addEventListener("pointerdown", (ev) => {
    if (ev.button !== 0 || ev.target.closest("button, a, input, select") || window.matchMedia("(max-width:560px)").matches) return;
    const r = card.getBoundingClientRect();
    const dx = ev.clientX - r.left, dy = ev.clientY - r.top;
    // From centred-by-transform to plain coordinates, once.
    card.style.transform = "none";
    card.style.left = r.left + "px";
    card.style.top = r.top + "px";
    handle.setPointerCapture(ev.pointerId);
    card.classList.add("dragging");
    const move = (e) => {
      const x = Math.min(Math.max(e.clientX - dx, 8 - r.width + 120), window.innerWidth - 120);
      const y = Math.min(Math.max(e.clientY - dy, 0), window.innerHeight - 48);
      card.style.left = x + "px";
      card.style.top = y + "px";
    };
    const up = () => {
      card.classList.remove("dragging");
      handle.removeEventListener("pointermove", move);
      handle.removeEventListener("pointerup", up);
      handle.removeEventListener("pointercancel", up);
    };
    handle.addEventListener("pointermove", move);
    handle.addEventListener("pointerup", up);
    handle.addEventListener("pointercancel", up);
    ev.preventDefault();
  });
}

export function closeModal() {
  const d = dlg();
  if (d.open) d.close();
}

export function initModal() {
  const d = dlg();
  d.addEventListener("close", () => { clear(d); const f = onClose; onClose = null; if (f) f(); });
  // A click on the dimmed backdrop closes, a click inside the card does not.
  d.addEventListener("mousedown", (ev) => { if (ev.target === d) d.close(); });
}

/**
 * Asks inside the open dialog — "this also deletes …", "there is a gap …" —
 * and resolves true (go ahead) or false. The dialog's own buttons wait
 * meanwhile.
 * @param {{body: HTMLElement, foot: HTMLElement}} m  the dialog from openModal
 */
export function confirmInModal(m, { title, message, items = [], ok = "Continue", danger = false }) {
  return new Promise((resolve) => {
    const footButtons = [...m.foot.querySelectorAll("button")].filter((b) => !b.disabled);
    footButtons.forEach((b) => { b.disabled = true; });
    const yes = el("button", { type: "button", class: "btn " + (danger ? "danger" : "primary") }, ok);
    const no = el("button", { type: "button", class: "btn" }, "Cancel");
    const box = el("div", { class: "confirm-box" + (danger ? " danger" : ""), role: "alertdialog", "aria-live": "assertive" },
      el("strong", { text: title }),
      el("p", { text: message }),
      items.length ? el("ul", {}, items.map((i) => el("li", { class: /^\s/.test(i) ? "sub" : null, text: i.trim() }))) : null,
      el("div", { class: "confirm-actions" }, no, yes));
    const done = (answer) => {
      box.remove();
      footButtons.forEach((b) => { b.disabled = false; });
      resolve(answer);
    };
    yes.addEventListener("click", () => done(true));
    no.addEventListener("click", () => done(false));
    m.body.prepend(box);
    box.scrollIntoView({ block: "nearest" });
    yes.focus();
  });
}

/** A delete button that has to be pressed twice. */
export function dangerButton(label, action) {
  let armed = false;
  let timer = null;
  const b = el("button", { type: "button", class: "danger-ghost" }, icon("trash"), el("span", { text: label }));
  b.addEventListener("click", () => {
    if (!armed) {
      armed = true;
      b.classList.add("armed");
      b.lastChild.textContent = "Click again to " + label.toLowerCase();
      timer = setTimeout(() => { armed = false; b.classList.remove("armed"); b.lastChild.textContent = label; }, 4000);
      return;
    }
    clearTimeout(timer);
    action();
  });
  return b;
}
