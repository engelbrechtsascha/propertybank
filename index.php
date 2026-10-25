<?php
/**
 * FINATUM PropertyBank — the page itself (finatum.de/propertybank).
 *
 * PHP rather than HTML for the same reason as in the Pricer and MarketData: it
 * writes an absolute base path into the asset links, so the application works
 * whether it is opened as /propertybank or /propertybank/.
 */
declare(strict_types=1);

$dir  = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$base = htmlspecialchars($dir === '' ? '/' : $dir . '/', ENT_QUOTES);
require_once __DIR__ . '/api/lib/version.php';
$ver  = PB_VERSION;
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>PropertyBank · FINATUM</title>
<link rel="stylesheet" href="<?= $base ?>assets/app.css?v=<?= $ver ?>">
<!-- Theme: applied before the first paint. The stored choice is shared with
     the other FINATUM tools (same key, same origin). -->
<script>
(function () {
  var root = document.documentElement;
  try {
    var saved = localStorage.getItem("sc-theme");
    if (saved === "light" || saved === "dark") { root.setAttribute("data-theme", saved); }
  } catch (e) { /* private mode, blocked storage: fall back to the system theme */ }
  function effective() {
    var set = root.getAttribute("data-theme");
    if (set === "light" || set === "dark") { return set; }
    return (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) ? "dark" : "light";
  }
  document.addEventListener("click", function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest("[data-theme-toggle]") : null;
    if (!btn) { return; }
    var next = effective() === "dark" ? "light" : "dark";
    root.setAttribute("data-theme", next);
    try { localStorage.setItem("sc-theme", next); } catch (e) {}
  });
})();
</script>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 320 353'%3E%3ClinearGradient id='g' gradientUnits='userSpaceOnUse' x1='0' y1='0' x2='320' y2='353'%3E%3Cstop offset='0' stop-color='%23F04E29'/%3E%3Cstop offset='1' stop-color='%23F7B735'/%3E%3C/linearGradient%3E%3Cpolygon fill='url(%23g)' points='0,78.13 70.9,119.05 312.91,24.57 238.05,0'/%3E%3Cpolygon fill='url(%23g)' points='87.81,128.82 150.67,165.1 310.7,89.66 246.78,64.2'/%3E%3Cpolygon fill='url(%23g)' points='166.74,174.39 238.05,215.56 317.39,169.74 246.14,135.53'/%3E%3Cpolygon fill='url(%23g)' points='24.67,195.89 89.94,243.95 70.9,119.05 0,78.13'/%3E%3Cpolygon fill='url(%23g)' points='105.3,255.25 161.62,296.7 150.67,165.1 87.81,128.82'/%3E%3Cpolygon fill='url(%23g)' points='175.84,307.18 238.05,352.97 238.05,215.56 166.74,174.39'/%3E%3Cpolygon fill='%23D9DADC' points='317.39,169.75 238.05,215.56 238.05,352.97 307.35,301.94'/%3E%3Cpolygon fill='%23D9DADC' points='312.92,24.57 70.9,119.05 89.95,243.95 102.81,237.25 87.81,128.82 246.77,64.2 308.87,88.93'/%3E%3C/svg%3E">
</head>
<body data-base="<?= $base ?>" data-version="<?= $ver ?>">

<?php readfile(__DIR__ . '/assets/sprite.svg'); ?>

<!-- Sign in / create account ------------------------------------------- -->
<div id="signin" class="signin" hidden>
  <button type="button" class="theme-toggle theme-floating" data-theme-toggle title="Switch between the light and dark theme" aria-label="Switch between the light and dark theme">
    <svg class="ico ico-moon" aria-hidden="true"><use href="#i-moon"></use></svg>
    <svg class="ico ico-sun" aria-hidden="true"><use href="#i-sun"></use></svg>
  </button>
  <div class="signin-card">
    <div class="brand">
      <svg class="logo" role="img" aria-label="FINATUM"><use href="#finatum-logo"></use></svg>
      <span class="brand-name">PropertyBank</span>
    </div>

    <form id="signinForm" autocomplete="on">
      <h1>Sign in</h1>
      <p class="sub">Flats for sale from property sites — the key data in English, searchable, with the distances that matter.</p>
      <p class="form-error" id="signinError" hidden></p>
      <p class="form-ok" id="signinNotice" hidden></p>
      <label><span>Email address</span><input type="email" id="signinEmail" name="email" autocomplete="username" required></label>
      <label><span>Password</span><input type="password" id="signinPassword" name="password" autocomplete="current-password" required></label>
      <button type="submit" class="btn primary" id="signinSubmit">Sign in</button>
      <p class="switch" id="toRegisterLine" hidden>No account yet? <button type="button" class="linkish" id="toRegister">Create one</button></p>
    </form>

    <form id="registerForm" autocomplete="on" hidden>
      <h1>Create an account</h1>
      <p class="sub" id="registerSub"></p>
      <p class="form-error" id="registerError" hidden></p>
      <label><span>Name</span><input type="text" id="regName" name="name" autocomplete="name" required></label>
      <label><span>Email address</span><input type="email" id="regEmail" name="email" autocomplete="username" required></label>
      <label><span id="regPwLabel">Password</span><input type="password" id="regPassword" name="password" autocomplete="new-password" required></label>
      <label><span>Repeat password</span><input type="password" id="regPassword2" autocomplete="new-password" required></label>
      <button type="submit" class="btn primary" id="registerSubmit">Create account</button>
      <p class="switch">Already have one? <button type="button" class="linkish" id="toSignin">Sign in</button></p>
    </form>
  </div>
</div>

<!-- The application -------------------------------------------------- -->
<div id="app" hidden>
  <header class="topbar">
    <div class="topbar-inner">
      <div class="brand">
        <!-- The FINATUM logo leads to the tools on finatum.de; "PropertyBank" to the listings. -->
        <a class="brand-logo" href="/tools/index.html#finance" title="FINATUM tools — Financial Engineering" aria-label="FINATUM tools — Financial Engineering">
          <svg class="logo" role="img" aria-hidden="true"><use href="#finatum-logo"></use></svg>
        </a>
        <span class="brand-sep" aria-hidden="true"></span>
        <a class="brand-name" href="#/listings" data-tab="listings" title="PropertyBank — the listings">PropertyBank</a>
      </div>
      <nav class="tabs" aria-label="Sections">
        <a href="#/listings" data-tab="listings">Listings</a>
        <a href="#/cities" data-tab="cities">Cities</a>
        <a href="#/sources" data-tab="sources">Sources</a>
      </nav>
      <div class="bar-actions">
        <button type="button" class="theme-toggle" data-theme-toggle title="Switch between the light and dark theme" aria-label="Switch between the light and dark theme">
          <svg class="ico ico-moon" aria-hidden="true"><use href="#i-moon"></use></svg>
          <svg class="ico ico-sun" aria-hidden="true"><use href="#i-sun"></use></svg>
        </button>
        <details class="menu" id="accountMenu">
          <summary><span id="userName"></span><span class="role" id="userRole"></span></summary>
          <div class="menu-body">
            <div class="who" id="userEmail"></div>
            <button type="button" data-action="users" id="menuUsers" hidden>Accounts and settings</button>
            <button type="button" data-action="password">Change password</button>
            <button type="button" data-action="about">About the data</button>
            <hr>
            <button type="button" data-action="logout">Sign out</button>
            <button type="button" data-action="close" class="menu-danger">Close my account</button>
          </div>
        </details>
      </div>
    </div>
  </header>
  <main id="view" tabindex="-1"></main>
  <footer class="site-foot">
    Listings: OLX.ua — each row links to the listing, which stays the authority. Addresses located with
    <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap contributors</a> (Nominatim).
    Exchange rates: European Central Bank, National Bank of Ukraine. For personal research.
  </footer>
</div>

<dialog id="modal" class="modal" aria-labelledby="modalTitle"></dialog>
<div id="toast" role="status" aria-live="polite" hidden></div>

<script type="module" src="<?= $base ?>assets/js/app.js?v=<?= $ver ?>"></script>
</body>
</html>
