/*!
 * FINATUM Language Teacher — "show password".
 *
 * ONE COPY, IMPORTED BY BOTH FRONT ENDS. Every password field gets the usual
 * eye button at its right-hand end: pressed, it shows what was typed; pressed
 * again, it hides it. It watches the page, so a field that appears later — the
 * registration form, "change my password" — gets one too, without every form
 * having to remember it.
 */

const EYE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>' +
  '<circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/>' +
  '<path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6A17.4 17.4 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/>' +
  '<path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

function setState(btn, input, shown) {
  input.type = shown ? "text" : "password";
  btn.innerHTML = shown ? EYE_OFF : EYE;
  btn.setAttribute("aria-pressed", shown ? "true" : "false");
  btn.setAttribute("aria-label", shown ? "Hide password" : "Show password");
  btn.title = shown ? "Hide password" : "Show password";
}

/** Gives one password field its button. Safe to call twice. */
export function addPasswordToggle(input) {
  if (!input || input.dataset.pwToggle) return;
  input.dataset.pwToggle = "1";
  const wrap = document.createElement("span");
  wrap.className = "pw-wrap";
  input.parentNode.insertBefore(wrap, input);
  wrap.appendChild(input);
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = "pw-toggle";
  wrap.appendChild(btn);
  setState(btn, input, false);
  // Keep the focus (and the phone's keyboard) in the field.
  btn.addEventListener("pointerdown", (e) => e.preventDefault());
  btn.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    const end = input.value.length;
    setState(btn, input, input.type === "password");
    input.focus();
    try { input.setSelectionRange(end, end); } catch { /* not every type allows it */ }
  });
  // A form that is sent or reset hides the password again.
  if (input.form) {
    input.form.addEventListener("submit", () => setState(btn, input, false));
    input.form.addEventListener("reset", () => setState(btn, input, false));
  }
}

/** Every password field in the page, now and later. */
export function watchPasswordFields(root = document.body) {
  const scan = (node) => {
    if (!node || node.nodeType !== 1) return;
    if (node.matches('input[type="password"]')) addPasswordToggle(node);
    node.querySelectorAll('input[type="password"]').forEach(addPasswordToggle);
  };
  scan(root);
  new MutationObserver((list) => list.forEach((m) => m.addedNodes.forEach(scan)))
    .observe(root, { childList: true, subtree: true });
}
