-- FINATUM PropertyBank — SQLite schema (0.1.0), local development only.
-- Production uses schema.mysql.sql, which explains each table.

CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  email         TEXT NOT NULL UNIQUE,
  name          TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'user',
  active        INTEGER NOT NULL DEFAULT 1,
  source        TEXT NOT NULL DEFAULT 'admin',
  prefs         TEXT,
  created_at    TEXT NOT NULL,
  last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS listings (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  source           TEXT NOT NULL,
  source_id        TEXT NOT NULL,
  search_id        INTEGER,
  url              TEXT NOT NULL,
  title            TEXT NOT NULL DEFAULT '',
  city_id          INTEGER,
  city_orig        TEXT NOT NULL DEFAULT '',
  city_en          TEXT NOT NULL DEFAULT '',
  district_orig    TEXT NOT NULL DEFAULT '',
  district_en      TEXT NOT NULL DEFAULT '',
  street_orig      TEXT NOT NULL DEFAULT '',
  street_en        TEXT NOT NULL DEFAULT '',
  house_no         TEXT NOT NULL DEFAULT '',
  address_en       TEXT NOT NULL DEFAULT '',
  address_orig     TEXT NOT NULL DEFAULT '',
  loc_quality      TEXT NOT NULL DEFAULT 'none',
  lat              REAL,
  lon              REAL,
  geo              TEXT,
  floor            INTEGER,
  floors_total     INTEGER,
  area_m2          REAL,
  rooms            INTEGER,
  ceiling_m        REAL,
  year_built       INTEGER,
  price_value      REAL,
  price_currency   TEXT NOT NULL DEFAULT '',
  price_usd        REAL,
  price_eur        REAL,
  posted_at        TEXT,
  site_refreshed_at TEXT,
  first_seen_at    TEXT NOT NULL,
  last_seen_at     TEXT NOT NULL,
  active           INTEGER NOT NULL DEFAULT 1,
  content_hash     TEXT NOT NULL DEFAULT '',
  needs_enrich     INTEGER NOT NULL DEFAULT 1,
  enriched_at      TEXT,
  raw              TEXT,
  title_en         TEXT,
  description_en   TEXT,
  translated_from  TEXT,
  translated_at    TEXT,
  translated_by    TEXT,
  group_id         INTEGER,
  change_flag      TEXT,
  change_run       INTEGER,
  prev_price_value REAL,
  prev_price_currency TEXT,
  UNIQUE (source, source_id)
);

CREATE TABLE IF NOT EXISTS geocode_cache (
  qhash      TEXT PRIMARY KEY,
  query      TEXT NOT NULL,
  ok         INTEGER NOT NULL DEFAULT 0,
  lat        REAL,
  lon        REAL,
  road       TEXT NOT NULL DEFAULT '',
  house      TEXT NOT NULL DEFAULT '',
  borough    TEXT NOT NULL DEFAULT '',
  kind       TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS cities (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  olx_city_id INTEGER,
  name_en     TEXT NOT NULL,
  name_orig   TEXT NOT NULL DEFAULT '',
  landmarks   TEXT NOT NULL,
  stations    TEXT NOT NULL,
  updated_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS searches (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  source       TEXT NOT NULL,
  label        TEXT NOT NULL,
  params       TEXT NOT NULL,
  active       INTEGER NOT NULL DEFAULT 1,
  last_run_at  TEXT,
  last_count   INTEGER
);

CREATE TABLE IF NOT EXISTS refresh_runs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER,
  status      TEXT NOT NULL,
  phase       TEXT NOT NULL DEFAULT 'plan',
  state       TEXT,
  stats       TEXT,
  message     TEXT NOT NULL DEFAULT '',
  started_at  TEXT NOT NULL,
  updated_at  TEXT NOT NULL,
  finished_at TEXT
);

CREATE TABLE IF NOT EXISTS fx_rates (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  eurusd      REAL NOT NULL,
  usduah      REAL,
  euruah      REAL,
  rate_date   TEXT,
  source      TEXT NOT NULL,
  fetched_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER,
  user_name   TEXT,
  entity      TEXT NOT NULL,
  entity_id   INTEGER,
  action      TEXT NOT NULL,
  summary     TEXT NOT NULL,
  created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
  name  TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  email      TEXT NOT NULL,
  ip         TEXT NOT NULL,
  successful INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS ix_listings_enrich ON listings (needs_enrich);
CREATE INDEX IF NOT EXISTS ix_listings_active ON listings (active);
CREATE INDEX IF NOT EXISTS ix_runs_status ON refresh_runs (status);
CREATE INDEX IF NOT EXISTS ix_audit_created ON audit_log (created_at);
CREATE INDEX IF NOT EXISTS ix_attempts_email ON login_attempts (email, created_at);
CREATE INDEX IF NOT EXISTS ix_attempts_ip ON login_attempts (ip, created_at);
