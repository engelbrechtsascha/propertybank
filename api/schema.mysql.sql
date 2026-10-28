-- FINATUM PropertyBank — MySQL / MariaDB schema (0.1.0)
-- Imported automatically by install.php, or by hand through phpMyAdmin.

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(16)  NOT NULL DEFAULT 'user',
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  source        VARCHAR(16)  NOT NULL DEFAULT 'admin',   -- admin | self | install
  prefs         TEXT         NULL,                       -- small JSON: the last filters
  created_at    DATETIME     NOT NULL,
  last_login_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per listing on a property site. The key data are kept here, in
-- English; everything else stays on the site, behind `url`.
--   *_orig      as the site wrote it (Ukrainian or Russian on OLX)
--   *_en        translated / transliterated into English
--   loc_quality how the coordinates were found:
--                 exact  the address the seller entered on OLX, geocoded
--                 text   an address found in the title or description, geocoded
--                 street a street without a house number, geocoded to the street
--                 approx OLX's own map point (within about a kilometre)
--                 none   no usable location — distances stay empty
--   geo         JSON: distances to the city's landmarks and the two nearest
--               stations, recomputed when the city's landmarks change
--   price_*     the price as listed (value, currency) and in USD and EUR at
--               the exchange rate of the last refresh
CREATE TABLE IF NOT EXISTS listings (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source           VARCHAR(16)  NOT NULL,
  source_id        VARCHAR(40)  NOT NULL,
  search_id        INT UNSIGNED NULL,
  url              VARCHAR(500) NOT NULL,
  title            VARCHAR(300) NOT NULL DEFAULT '',
  city_id          INT UNSIGNED NULL,
  city_orig        VARCHAR(120) NOT NULL DEFAULT '',
  city_en          VARCHAR(120) NOT NULL DEFAULT '',
  district_orig    VARCHAR(120) NOT NULL DEFAULT '',
  district_en      VARCHAR(120) NOT NULL DEFAULT '',
  street_orig      VARCHAR(200) NOT NULL DEFAULT '',
  street_en        VARCHAR(200) NOT NULL DEFAULT '',
  house_no         VARCHAR(30)  NOT NULL DEFAULT '',
  address_en       VARCHAR(250) NOT NULL DEFAULT '',
  address_orig     VARCHAR(250) NOT NULL DEFAULT '',
  loc_quality      VARCHAR(8)   NOT NULL DEFAULT 'none',
  lat              DOUBLE       NULL,
  lon              DOUBLE       NULL,
  geo              TEXT         NULL,
  floor            SMALLINT     NULL,
  floors_total     SMALLINT     NULL,
  area_m2          DECIMAL(8,2) NULL,
  rooms            TINYINT      NULL,
  ceiling_m        DECIMAL(4,2) NULL,
  year_built       SMALLINT     NULL,
  price_value      DECIMAL(14,2) NULL,
  price_currency   CHAR(3)      NOT NULL DEFAULT '',
  price_usd        DECIMAL(14,2) NULL,
  price_eur        DECIMAL(14,2) NULL,
  posted_at        DATETIME     NULL,
  site_refreshed_at DATETIME    NULL,
  first_seen_at    DATETIME     NOT NULL,
  last_seen_at     DATETIME     NOT NULL,
  active           TINYINT(1)   NOT NULL DEFAULT 1,
  content_hash     CHAR(40)     NOT NULL DEFAULT '',
  needs_enrich     TINYINT(1)   NOT NULL DEFAULT 1,
  enriched_at      DATETIME     NULL,
  raw              MEDIUMTEXT   NULL,          -- the site's record, compressed ("z:…"), for re-parsing
  title_en         VARCHAR(400) NULL,          -- title and description in English, translated when
  description_en   MEDIUMTEXT   NULL,          -- the listing is first opened (translate.php)
  translated_from  VARCHAR(8)   NULL,
  translated_at    DATETIME     NULL,
  translated_by    VARCHAR(40)  NULL,          -- DeepL, Google Translate, or what an imported file names
  group_id         INT UNSIGNED NULL,          -- the main ad of the flat this ad is for (several ads, one flat)
  change_flag      VARCHAR(8)   NULL,          -- new | updated, set by the refresh change_run
  change_run       INT UNSIGNED NULL,
  prev_price_value DECIMAL(14,2) NULL,         -- the price before the last change
  prev_price_currency CHAR(3)   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_listings_source (source, source_id),
  KEY ix_listings_enrich (needs_enrich),
  KEY ix_listings_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every address sent to the geocoder, with the answer, so it is asked once.
CREATE TABLE IF NOT EXISTS geocode_cache (
  qhash      CHAR(40)     NOT NULL,
  query      VARCHAR(400) NOT NULL,
  ok         TINYINT(1)   NOT NULL DEFAULT 0,
  lat        DOUBLE       NULL,
  lon        DOUBLE       NULL,
  road       VARCHAR(200) NOT NULL DEFAULT '',
  house      VARCHAR(40)  NOT NULL DEFAULT '',
  borough    VARCHAR(120) NOT NULL DEFAULT '',
  kind       VARCHAR(40)  NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (qhash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A city: its landmarks and its metro stations, both editable in the
-- application. `olx_city_id` ties listings to it.
CREATE TABLE IF NOT EXISTS cities (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  olx_city_id INT UNSIGNED NULL,
  name_en     VARCHAR(120) NOT NULL,
  name_orig   VARCHAR(120) NOT NULL DEFAULT '',
  landmarks   TEXT         NOT NULL,   -- JSON [{key, name, lat, lon}]
  stations    MEDIUMTEXT   NOT NULL,   -- JSON [{name, name_orig, line, colour, lat, lon}]
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_cities_olx (olx_city_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a refresh reads: one row per search on a site.
CREATE TABLE IF NOT EXISTS searches (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source       VARCHAR(16)  NOT NULL,
  label        VARCHAR(160) NOT NULL,
  params       TEXT         NOT NULL,   -- JSON, e.g. {"category_id":1758,"region_id":25,"city_id":268,"district_id":19}
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_run_at  DATETIME     NULL,
  last_count   INT UNSIGNED NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A refresh, run in steps the browser asks for one after another, so no
-- request runs longer than a shared host allows. `state` is the work queue.
CREATE TABLE IF NOT EXISTS refresh_runs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  status      VARCHAR(12)  NOT NULL,     -- running | done | failed | cancelled
  phase       VARCHAR(16)  NOT NULL DEFAULT 'plan',
  state       MEDIUMTEXT   NULL,
  stats       TEXT         NULL,
  message     VARCHAR(400) NOT NULL DEFAULT '',
  started_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  finished_at DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The exchange rates each refresh used.
CREATE TABLE IF NOT EXISTS fx_rates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  eurusd      DOUBLE       NOT NULL,     -- USD per EUR
  usduah      DOUBLE       NULL,         -- UAH per USD
  euruah      DOUBLE       NULL,         -- UAH per EUR
  rate_date   DATE         NULL,
  source      VARCHAR(80)  NOT NULL,
  fetched_at  DATETIME     NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  user_name   VARCHAR(120) NULL,
  entity      VARCHAR(16)  NOT NULL,
  entity_id   INT UNSIGNED NULL,
  action      VARCHAR(24)  NOT NULL,
  summary     VARCHAR(400) NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name  VARCHAR(64)  NOT NULL,
  value VARCHAR(400) NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(190) NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  successful TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_attempts_email (email, created_at),
  KEY ix_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Flats a person marked as of interest, per ad (the site's ad number), so a
-- mark survives regrouping. Also created on first use by listings.php.
CREATE TABLE IF NOT EXISTS marks (
  user_id    INT UNSIGNED NOT NULL,
  source     VARCHAR(16)  NOT NULL,
  source_id  VARCHAR(40)  NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (user_id, source, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
