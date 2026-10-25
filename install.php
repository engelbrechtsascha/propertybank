<?php
declare(strict_types=1);

/**
 * FINATUM PropertyBank — one-time installer.
 *
 * Creates the database tables, the first administrator account and the
 * registration settings, and writes api/config.php. Delete this file once
 * the application is running.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/api/lib/prefix.php';
require __DIR__ . '/api/lib/version.php';

$configPath = __DIR__ . '/api/config.php';
$alreadyInstalled = is_file($configPath);

/** Must match MIN_PASSWORD_LENGTH in api/lib/auth.php. */
const INSTALL_MIN_PASSWORD = 12;
/** Must match PASSWORD_OPTIONS in api/lib/auth.php. */
const INSTALL_HASH_OPTIONS = ['cost' => 12];

$errors = [];
$done   = false;
$reconnected = null;   // accounts found when reconnecting to an earlier installation
$manual = null;
$values = [
    'driver'   => $_POST['driver']   ?? 'mysql',
    'host'     => $_POST['host']     ?? 'localhost',
    'port'     => $_POST['port']     ?? '3306',
    'database' => $_POST['database'] ?? '',
    'username' => $_POST['username'] ?? '',
    'password' => $_POST['password'] ?? '',
    'prefix'   => $_POST['prefix']   ?? '',
    'app_name' => $_POST['app_name'] ?? 'FINATUM PropertyBank',
    'timezone' => $_POST['timezone'] ?? 'Europe/Berlin',
    'admin_name'   => $_POST['admin_name']   ?? '',
    'admin_email'  => $_POST['admin_email']  ?? '',
    'geocoder_contact' => $_POST['geocoder_contact'] ?? '',
];

function install_base_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $dir === '' ? '/' : $dir . '/';
}

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver = $values['driver'] === 'sqlite' ? 'sqlite' : 'mysql';
    $adminName  = trim((string) $values['admin_name']);
    $adminEmail = mb_strtolower(trim((string) $values['admin_email']));
    $adminPass  = (string) ($_POST['admin_password'] ?? '');
    $adminPass2 = (string) ($_POST['admin_password2'] ?? '');

    // One person to begin with: nobody registers; the administrator can open it later.
    $regMode = 'off';
    $geoContact = trim((string) $values['geocoder_contact']);

    if ($adminName === '')                               $errors[] = 'Enter the administrator’s name.';
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid administrator email address.';
    if (mb_strlen($adminPass) < INSTALL_MIN_PASSWORD)    $errors[] = 'The administrator password must be at least ' . INSTALL_MIN_PASSWORD . ' characters long.';
    if ($adminPass !== $adminPass2)                      $errors[] = 'The two passwords do not match.';
    if ($driver === 'mysql' && trim((string) $values['database']) === '') $errors[] = 'Enter the database name.';

    $prefix = trim((string) $values['prefix']);
    if (!pb_valid_prefix($prefix)) {
        $errors[] = 'A table prefix may only contain letters, digits and underscores, must start with a letter and stay under 16 characters — for example pb_.';
    }
    if ($geoContact !== '' && !filter_var($geoContact, FILTER_VALIDATE_EMAIL)) $errors[] = 'The contact for the geocoder must be an email address, or empty.';
    $timezone = trim((string) $values['timezone']);
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) $errors[] = 'Enter a time zone such as Europe/Berlin.';

    if (!$errors) {
        date_default_timezone_set($timezone);
    }

    $pdo = null;
    if (!$errors) {
        try {
            if ($driver === 'sqlite') {
                if (!is_dir(__DIR__ . '/data')) {
                    @mkdir(__DIR__ . '/data', 0775, true);
                }
                $pdo = new PDO('sqlite:' . __DIR__ . '/data/propertybank.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('PRAGMA foreign_keys = ON');
            } else {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $values['host'], (int) $values['port'], $values['database']);
                $pdo = new PDO($dsn, (string) $values['username'], (string) $values['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $errors[] = 'The database refused the connection: ' . $message;
            if (str_contains($message, '1045')) {
                $errors[] = 'The server answered but rejected the user name or password, so the host is correct. '
                    . 'Check the user name exactly as your control panel lists it, and set a fresh password for that database user.';
            } elseif (str_contains($message, '1044') || str_contains($message, '1049')) {
                $errors[] = 'The sign-in worked but this user cannot reach that database. Check the database name, '
                    . 'and add the user to it with ALL PRIVILEGES in your control panel.';
            } elseif ($driver === 'mysql' && in_array(strtolower(trim((string) $values['host'])), ['localhost', '127.0.0.1'], true)) {
                $errors[] = 'The database server could not be reached at all. If your control panel shows a '
                    . '“Remote Database Host” address on the databases page, enter that address instead of localhost.';
            }
        }
    }

    if (!$errors && $pdo instanceof PDO) {
        try {
            // Tables of these names that are not this application's, or that
            // already hold accounts, are never written into.
            $signature = [
                'users' => 'prefs', 'listings' => 'loc_quality', 'cities' => 'landmarks', 'searches' => 'params',
                'audit_log' => 'summary', 'settings' => 'name, value', 'login_attempts' => 'successful',
            ];
            $foreign = [];
            foreach ($signature as $table => $column) {
                $name = $prefix . $table;
                try {
                    $pdo->query('SELECT 1 FROM ' . $name . ' LIMIT 1');
                } catch (Throwable) {
                    continue;
                }
                try {
                    $pdo->query('SELECT ' . $column . ' FROM ' . $name . ' LIMIT 1');
                } catch (Throwable) {
                    $foreign[] = $name;
                }
            }
            if ($foreign) {
                throw new RuntimeException('the database already contains a table called ' . implode(' and ', $foreign) .
                    ' that belongs to something else. Give this installation a table prefix (for example pb_), or create a separate database for it.');
            }
            try {
                $existing = (int) $pdo->query('SELECT COUNT(*) FROM ' . $prefix . 'users')->fetchColumn();
            } catch (Throwable) {
                $existing = 0;
            }
            $has = static function (string $table) use ($pdo, $prefix): bool {
                try {
                    $pdo->query('SELECT 1 FROM ' . $prefix . $table . ' LIMIT 1');
                    return true;
                } catch (Throwable) {
                    return false;
                }
            };
            if ($existing > 0 && $has('listings')) {
                // PropertyBank's own tables, from an earlier run whose config.php
                // never got written (the page reloaded, the folder was not
                // writable). Reconnect — but only for someone who signs in as
                // one of its administrators, so the tables cannot be taken over.
                $st = $pdo->prepare(pb_apply_prefix("SELECT password_hash FROM users WHERE email = ? AND role = 'admin' AND active = 1", $prefix));
                $st->execute([$adminEmail]);
                $hash = $st->fetchColumn();
                if (!is_string($hash) || !password_verify($adminPass, $hash)) {
                    throw new RuntimeException('this database already holds a PropertyBank installation with ' . $existing
                        . ' account(s) — probably from an earlier attempt whose settings file was not saved. To connect to it, enter the '
                        . 'administrator email address and password you used then. To start afresh instead, choose a table prefix '
                        . '(for example pb2_), a separate database, or delete its tables (' . $prefix . 'users, ' . $prefix . 'listings, '
                        . $prefix . 'cities, ' . $prefix . 'searches and the others) in phpMyAdmin.');
                }
                $reconnected = $existing;
            } elseif ($existing > 0) {
                $owner = $has('trades') || $has('market_sets') ? 'the Pricer\'s'
                    : ($has('series_cache') ? 'MarketData\'s' : 'another application\'s');
                $values['prefix'] = $prefix === '' ? 'pb_' : $prefix;     // filled in for the next attempt
                throw new RuntimeException('this database already holds ' . $owner . ' accounts in a table called ' . $prefix . 'users. '
                    . ($prefix === '' ? 'The table prefix field below is now filled in with pb_ — press Install again '
                        . '(PropertyBank then uses pb_users, pb_listings, … and never touches them), or create a separate database (recommended).'
                        : 'Choose another table prefix, or create a separate database.'));
            }

            if ($reconnected === null) {
            $sql = (string) file_get_contents(__DIR__ . '/api/schema.' . $driver . '.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            $sql = pb_apply_prefix($sql, $prefix);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                $pdo->exec($statement);
            }

            $pdo->beginTransaction();
            $st = $pdo->prepare(pb_apply_prefix(
                'INSERT INTO users (email, name, password_hash, role, active, source, created_at) VALUES (?, ?, ?, ?, 1, ?, ?)', $prefix));
            $st->execute([$adminEmail, $adminName, password_hash($adminPass, PASSWORD_DEFAULT, INSTALL_HASH_OPTIONS), 'admin', 'install',
                (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
            $st = $pdo->prepare(pb_apply_prefix('INSERT INTO settings (name, value) VALUES (?, ?)', $prefix));
            foreach (['registration_mode' => $regMode, 'max_users' => '5', 'geocoder_contact' => $geoContact] as $k => $v) {
                $st->execute([$k, $v]);
            }
            $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // The checks above explain themselves; a database error gets its context.
            $errors[] = $e instanceof RuntimeException ? ucfirst($e->getMessage())
                : ($reconnected === null ? 'Creating the tables failed: ' : 'Connecting failed: ') . $e->getMessage();
        }
    }

    if (!$errors) {
        $config = "<?php\n\nreturn " . var_export([
            'driver'       => $driver,
            'host'         => (string) $values['host'],
            'port'         => (int) $values['port'],
            'database'     => (string) $values['database'],
            'username'     => (string) $values['username'],
            'password'     => (string) $values['password'],
            'charset'      => 'utf8mb4',
            'table_prefix' => $prefix,
            'sqlite_path'  => __DIR__ . '/data/propertybank.sqlite',
            'app_name'     => trim((string) $values['app_name']) ?: 'FINATUM PropertyBank',
            'timezone'     => $timezone,
            'session_name' => 'pbsid',
            'cookie_path'  => '',
            'debug'        => false,
        ], true) . ";\n";
        if (@file_put_contents($configPath, $config) === false) {
            $manual = $config;
        }
        $done = true;
    }
}

function v(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Install PropertyBank · FINATUM</title>
<style>
  :root{
    --ground:#F8FEFF;--surface:#fff;--surface-2:#ECF1F2;--line:#DAE0E0;--line-strong:#CBD0D1;
    --ink:#111827;--muted:#374151;--faint:#6B7280;--accent:#F04E29;--accent-ink:#D84625;--accent-text:#B63B1F;--on-accent:#0B1620;
    --risk:#BE123C;--done:#047857;
    --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    --mono:ui-monospace,SFMono-Regular,"Cascadia Mono",Menlo,Consolas,monospace;
  }
  @media (prefers-color-scheme:dark){:root{
    --ground:#011222;--surface:#0E1E2D;--surface-2:#1A2A38;--line:#1F2E3D;--line-strong:#34414E;
    --ink:#F3F4F6;--muted:#D1D5DB;--faint:#9CA3AF;--accent-text:#F04E29;--accent-ink:#FF6E4A;--risk:#FB7185;--done:#34D399;}}
  *{box-sizing:border-box}
  body{margin:0;background:var(--ground);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.55}
  .bar{height:3px;background:linear-gradient(100deg,#F04E29 0%,#F7B735 100%)}
  .wrap{max-width:640px;margin:0 auto;padding:40px 16px 80px}
  h1{font-size:24px;letter-spacing:-.01em;margin:0 0 6px}
  .lede{color:var(--muted);margin:0 0 26px}
  .card{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:22px}
  fieldset{border:0;padding:0;margin:0 0 22px}
  legend{font-family:var(--mono);font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);padding:0;margin-bottom:10px}
  label{display:block;margin-bottom:12px}
  label span{display:block;font-size:13px;color:var(--muted);margin-bottom:4px}
  input[type=text],input[type=email],input[type=password],input[type=number],select{
    width:100%;font:inherit;padding:8px 10px;border-radius:8px;border:1px solid var(--line-strong);background:var(--surface-2);color:var(--ink)}
  .row{display:flex;gap:12px}.row>*{flex:1}
  .check{display:flex;align-items:flex-start;gap:9px;font-size:14px;color:var(--muted)}
  .check input{margin-top:4px}
  button{font:inherit;font-weight:600;background:var(--accent);color:var(--on-accent);border:0;border-radius:8px;padding:10px 18px;cursor:pointer}
  button:hover{background:var(--accent-ink)}
  .errors{border:1px solid var(--risk);border-left-width:3px;border-radius:8px;padding:12px 14px;margin-bottom:20px;color:var(--risk);background:var(--surface)}
  .errors ul{margin:6px 0 0;padding-left:18px}
  .ok{border:1px solid var(--done);border-left-width:3px;border-radius:8px;padding:16px 18px;background:var(--surface)}
  .ok h2{margin:0 0 8px;font-size:18px}
  code,pre{font-family:var(--mono);font-size:12.5px}
  pre{background:var(--surface-2);border:1px solid var(--line);border-radius:8px;padding:12px;overflow:auto}
  .warn{color:var(--risk);font-weight:600}
  .note{color:var(--muted);font-size:13px;margin:-4px 0 12px}
  a{color:var(--accent-text)}
</style>
</head>
<body>
<div class="bar"></div>
<div class="wrap">

<?php if ($alreadyInstalled): ?>
  <h1>Already installed</h1>
  <p class="lede">api/config.php exists, so this installation is complete.</p>
  <div class="card">
    <p>Open <a href="./">PropertyBank</a>.</p>
    <p class="warn">Delete install.php from the server now — it is not needed again.</p>
    <p style="color:var(--muted)">To start over, delete <code>api/config.php</code> (and empty the database) first.</p>
  </div>

<?php elseif ($done): ?>
  <h1>Installed</h1>
  <p class="lede">FINATUM PropertyBank <?= v(PB_VERSION) ?> is ready.<?php if ($reconnected !== null): ?>
    It is connected to the installation already in this database (<?= (int) $reconnected ?> account<?= $reconnected === 1 ? '' : 's' ?>) —
    nothing in it was changed; sign in with your existing administrator account.<?php endif; ?></p>
  <div class="ok">
    <h2>Two things before you start</h2>
    <p><span class="warn">Delete install.php from the server.</span> Anyone who can reach it could otherwise reinstall over your data.</p>
    <p>Then sign in at <a href="./"><?= v(install_base_path()) ?></a> with <code><?= v((string) $values['admin_email']) ?></code>.
       Then press <b>Refresh</b>: the first one reads every listing of the Shevchenkivskyi district and
       locates it — about half an hour, because the geocoder takes one address a second. Keep the page open.</p>
  </div>
  <?php if ($manual !== null): ?>
    <div class="card" style="margin-top:18px">
      <p class="warn">The installer could not write api/config.php — the folder is not writable.</p>
      <p>Create <code>api/config.php</code> yourself with exactly this content:</p>
      <pre><?= v($manual) ?></pre>
    </div>
  <?php endif; ?>

<?php else: ?>
  <h1>Install PropertyBank</h1>
  <p class="lede">This creates the database tables and your account. It runs once. PropertyBank starts as a one-person application: nobody else can register (you can change that later).</p>

  <?php if ($errors): ?>
    <div class="errors"><strong>That did not work:</strong>
      <ul><?php foreach ($errors as $e): ?><li><?= v($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" class="card" autocomplete="off">
    <fieldset>
      <legend>Database</legend>
      <label><span>Type</span>
        <select name="driver" id="driver">
          <option value="mysql" <?= $values['driver'] === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB (shared hosting)</option>
          <option value="sqlite" <?= $values['driver'] === 'sqlite' ? 'selected' : '' ?>>SQLite file (evaluation only)</option>
        </select>
      </label>
      <div id="mysqlFields">
        <div class="row">
          <label><span>Host</span><input type="text" name="host" value="<?= v((string) $values['host']) ?>"></label>
          <label style="max-width:120px"><span>Port</span><input type="number" name="port" value="<?= v((string) $values['port']) ?>"></label>
        </div>
        <label><span>Database name</span><input type="text" name="database" value="<?= v((string) $values['database']) ?>"></label>
        <label><span>Database user</span><input type="text" name="username" value="<?= v((string) $values['username']) ?>"></label>
        <label><span>Database password</span><input type="password" name="password" value="<?= v((string) $values['password']) ?>"></label>
        <label><span>Table prefix (only if this database is shared)</span>
          <input type="text" name="prefix" placeholder="none" value="<?= v((string) $values['prefix']) ?>"></label>
        <p class="note">Leave empty: PropertyBank has a database of its own on WINT.GLOBAL. Only to put it into a database
          another application already uses, enter <code>pb_</code>. It cannot be changed after installation.</p>
      </div>
    </fieldset>

    <fieldset>
      <legend>Administrator account</legend>
      <label><span>Name</span><input type="text" name="admin_name" value="<?= v((string) $values['admin_name']) ?>" required></label>
      <label><span>Email address</span><input type="email" name="admin_email" value="<?= v((string) $values['admin_email']) ?>" required></label>
      <div class="row">
        <label><span>Password (at least 12 characters)</span><input type="password" name="admin_password" required></label>
        <label><span>Repeat password</span><input type="password" name="admin_password2" required></label>
      </div>
    </fieldset>


    <fieldset>
      <legend>Options</legend>
      <label><span>Application name</span><input type="text" name="app_name" value="<?= v((string) $values['app_name']) ?>"></label>
      <label><span>Time zone</span>
        <input type="text" name="timezone" value="<?= v((string) $values['timezone']) ?>"></label>
      <label><span>Your email address for the geocoder (optional)</span>
        <input type="email" name="geocoder_contact" value="<?= v((string) $values['geocoder_contact']) ?>"></label>
      <p class="note">OpenStreetMap's geocoder (Nominatim), which turns addresses into coordinates, asks applications to give a contact.
        It is sent with each lookup and nowhere else. It can be changed later under Settings.</p>
    </fieldset>

    <button type="submit">Install</button>
  </form>

  <script>
    var sel = document.getElementById('driver'), box = document.getElementById('mysqlFields');
    function sync(){ box.style.display = sel.value === 'sqlite' ? 'none' : ''; }
    sel.addEventListener('change', sync); sync();
  </script>
<?php endif; ?>

</div>
</body>
</html>
