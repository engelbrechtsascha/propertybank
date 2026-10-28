# FINATUM PropertyBank

Flats for sale from property websites — the **key data in English**, stored,
searchable and sortable, with **distances to the landmarks that matter** and a
**schematic map** for each listing. Everything else stays on the website: each
row links to the listing.

To begin with it reads **OLX.ua**, flats for sale in **Kyiv's Shevchenkivskyi
district**, and it is for **one person** (registration is off). Both are
settings, not code: more searches, cities and accounts can be added.

Built like the Pricer and MarketData — PHP and MySQL, no build step, no
Composer, no Node.js on the server, nothing loaded from another domain. Upload
the folder, open `install.php`, done.

Version 0.3.0

---

## 1. What is stored per listing

| | Key data | Where it comes from |
|---|---|---|
| 1 | City, 1b district | OLX's location fields, transliterated (Київ → Kyiv, Шевченківський → Shevchenkivskyi) |
| 2 | Address | the street and house number the seller entered on OLX (OLX's location service); else an address found in the title or description; else (since 0.1.1) a street named in the title without a number; the street name as OpenStreetMap writes it, in English: *Михайлівський пров. 9 → 9 Mykhailivskyi Lane* |
| 3 | Floor / floors (x/y) | OLX fields `floor`, `total_floors` |
| 4 | Floorspace m² | OLX field `total_area` |
| 5 | Ceiling height | not an OLX field — read from the description (*висота стелі 3,2 м*, *потолки 3.1*, *280 см*) |
| 6 | Year of construction | OLX field `year_of_commissioning`, else the description (*1958 року побудови*) |
| 7 | Price | as listed — value and currency (OLX's own `currency`: USD, EUR or UAH) — and the other of USD/EUR converted at each refresh: EUR/USD from the ECB, hryvnia from the National Bank of Ukraine. The listed price is shown in bold, the converted one with ≈ |
| 8 | Distance to each landmark | Kyiv: the Golden Gate and the Maidan; per city on the *Cities* page |
| 9 | The two nearest metro stations, with distances | all 52 Kyiv Metro stations, from OpenStreetMap |
| 10 | Schematic map | drawn in the browser: north up, the flat, the two landmarks and the two stations, each line with its distance in metres |
| 11 | Posting date | the date OLX shows ("Опубліковано"): when the seller last renewed the listing (`last_refresh_time`), with the first posting (`created_time`) below it when that was on another day |
| 12 | Link | opens the listing in a window of its own |
| — | Price per m² | in USD and EUR, from the price and the floorspace |
| — | Title and description in English | translated by DeepL or Google Translate during the refresh and stored; shown with the original beneath (§4a) |

Plus rooms, the original title, when PropertyBank first and last saw the
listing, and the raw OLX record (compressed) so listings can be parsed again
when the parser improves.

**Distances are straight lines** over the earth's surface (great circle,
haversine), in metres — not walking distances.

**How each listing was located** is shown beside its address:

| Badge | Meaning |
|---|---|
| exact | the seller's own address on OLX, found on the map |
| from text | an address in the title or description, found on the map inside the listing's district |
| street | only the street is known — from the text or from a street named in the title without a number; distances are to a point on that street, within a few hundred metres |
| ≈ 1 km | no address; OLX's own map point, which OLX says is within about a kilometre |
| no location | none of these — no distances (left out when a distance filter is set) |

On the live site on 23 October 2026, about one listing in six had an exact
address and most others name their street and house in the text.

## 2. Installing at `finatum.de/propertybank` (WINT.GLOBAL)

**Step 1 — Create a database.** cPanel → *Databases → MySQL® Databases*:
create `propertybank`, a user with a strong password, and add the user to the
database with **ALL PRIVILEGES**.

**Step 2 — Upload.** cPanel → *File Manager* → `public_html`: upload
`propertybank-0.3.0.zip`, *Extract*, delete the zip.

**Step 3 — Install** at **https://www.finatum.de/propertybank/install.php**:
MySQL, `localhost` / `3306`, the database, user and password from step 1 (with
the cPanel prefix), table prefix **empty**, your name, email and a password of
12+ characters. Optionally your email for the geocoder (see §4).

**Step 4 — Delete `install.php`.**

**Step 5 — Sign in, then *Sources → Test the connections*.** Every line
should say *ok*. Then go to *Listings* and press **Refresh**. The first
refresh reads about 2,500 listings (a few minutes) and then locates them —
about **half an hour to an hour**, because the geocoder allows one lookup a
second. Keep the page open; if you close it, the refresh pauses and the page
offers to continue. Later refreshes only locate new or changed listings.

### Updating to 0.3.1

**Dashboard.** Under the filters, tiles show what the filters let through:
flats shown out of all (with the ads behind them), and the range (min – max)
of floorspace, price in USD and EUR, price per m² in USD and EUR, and the
distance to each landmark of the city.

**Marked.** The ☆ in the first column (and in a flat's dialog) marks a flat as
of interest; *Only marked ★* shows just those. Marks belong to the person
signed in and stay across refreshes; they are kept per ad, so a flat stays
marked when another ad of it is shown.

**Defaults.** The filters start at Kyiv · Shevchenkivskyi (once, for filters
saved earlier too); *Clear filters* returns there.

The database gains the `marks` table by itself. Upload
`propertybank-0.3.1-update.zip` as below.

### Updating to 0.3.0

**One row per flat.** The same flat is often advertised several times on OLX,
as separate ads by different agents. Ads are taken as the same flat when they
are in the same city, on the same floor of a building with the same number of
floors, with the same floorspace (within 1 m²) and rooms, and either have the
same located street and house number or word for word the same description of
at least 150 characters; ads with different located addresses are never
merged, and the price is not compared. The list shows each flat once — with
the ad renewed most recently — and an "N ads" badge; the flat's dialog lists
every ad with its price and link. *Show each ad separately* switches the
grouping off. The groups are recalculated at the end of every refresh, so new
ads of a flat already known join it.

**New and updated.** A refresh marks ads seen for the first time **new**, and
ads whose text or price changed, or that are back on OLX, **updated** (with
the old price struck through). The labels of a refresh are shown once it has
finished and stay until the next refresh finishes. *Only new and updated*
shows just those flats.

The database gains the columns it needs by itself. Upload
`propertybank-0.3.0-update.zip` as below; the first page load groups the
existing ads once. Labels appear from the next refresh on.

### Updating to 0.2.5

0.2.5 reworks the listing dialog: the listing's title, in English with the
original in small print, stands under the address in the header; the dialog
can be moved by dragging its header; the distances to the landmarks and the
two nearest metro stations are tiles under the map; and the listing last
opened stays marked in the table (also after reloading). Upload
`propertybank-0.2.5-update.zip` as below.

### Updating to 0.2.4

0.2.4 tidies the texts: OLX writes every line break as a blank line and
every paragraph break as three, with spaces at the start of lines. Titles and
descriptions — originals and translations, also those already stored — are now
shown with single line breaks and one blank line between paragraphs. Nothing in
the database has to change. Upload `propertybank-0.2.4-update.zip` as below.

### Updating to 0.2.3

0.2.3 corrects the fingerprint of a listing's text. Until then it included
OLX's hryvnia conversion of the price, which moves with the exchange rate, so
after a rate change the import rejected translations as outdated and a refresh
treated unchanged listings as changed. Now the fingerprint covers the title and
description only, and the import also accepts a translation whose original in
the file is still the listing's text. A translations file that was rejected
before can simply be imported again.

Upload `propertybank-0.2.3-update.zip` into `public_html`, *Extract* with
overwrite, reload with Ctrl+F5. The database gains four columns for the
translations by itself, on the first request. Then set up translation (§4a)
and press **Refresh**: it translates every listing not yet in English.

### Updating from 0.1.0 to 0.1.1

Upload `propertybank-0.1.1-update.zip` into `public_html`, *Extract* with
overwrite, reload with Ctrl+F5. Nothing changes in the database. The next
**Refresh** locates again every listing without an exact position: those whose title names a street without a house number get
the street and approximate distances. That takes some minutes, because
OLX's location service is asked once per listing and every new street once by
the geocoder.

## 3. Using it

- **The annunciator** at the top of *Listings* says when the data were last
  refreshed (amber after 24 hours), how many listings there are, the exchange
  rate in use, and what the last refresh found. **Refresh** starts one.
- **Search** in framed groups: *Location* (city, district, street — English
  or original spelling), *Max distance* (to each landmark), *Floor*,
  *Building floors* (how many floors the building has), *Size* (m²),
  *Price* and *Price per m²* (each min/max, in USD or EUR). A listing without a location has no address, so a
  Street search does not find it, and any distance limit hides it. *Include listings no longer on OLX* shows the removed
  ones too. The filters and the sort order are remembered on the device.
- **Sort** by any column but the link (and the map thumbnail). Empty values
  always sort last. 100 rows a page.
- **A click on a row** shows everything stored about the listing with a large
  map, and the title and description in English with the original beneath. **A click on OLX** opens the listing in its own window.
- **Cities**: landmarks (up to six per city; the first two are drawn on the
  map; add one by name — it is looked up — or by coordinates) and metro
  stations (one a line). Saving recomputes every listing's distances at once.
- **Sources**: the OLX searches a refresh reads (category, region, city,
  district ids — Shevchenkivskyi is `district_id=19`), the connection test and
  the last refreshes.
- **Accounts and settings** (account menu): registration (off), accounts,
  the geocoder's address and contact, the pause between OLX requests.

## 4. The sources and their rules

| Source | What | Notes |
|---|---|---|
| OLX.ua search API (`/api/v1/offers/`) | the listings | the JSON behind OLX's own pages; answers at most 1,000 listings per search, so a larger district is read in **price bands** found by halving the price range |
| OLX location service (`/apigateway/exact-location-backend/…`) | the seller's street and house | needs the header `olx-locale: uk-UA`; 404 when the seller gave none |
| Nominatim (OpenStreetMap) | address → coordinates | usage policy: ≤ 1 request a second, results cached (`geocode_cache`), an identifying user agent; a contact address can be set. Map data © OpenStreetMap contributors (ODbL) |
| ECB euro reference rates | EUR/USD | daily |
| National Bank of Ukraine | UAH per USD and EUR | daily; EUR/USD falls back to its cross if the ECB is unreachable |

PropertyBank reads public listing pages for one person's research, at a polite
pace (250 ms between OLX requests by default). It keeps the key facts and links
to the listing; photos, contact details and the full text stay on OLX.

**The parsers were built against the live site** (23 October 2026) and are
checked against saved copies: `php tools/test-parse.php` (51 checks). Whether
the **server** at WINT.GLOBAL may reach OLX — some sites block requests from
hosting companies — is the one thing only the installation can tell:
*Sources → Test the connections*. If OLX refuses the server, see §7.

## 4a. Translation

**Without a service — with a file.** *Accounts and settings → Translation →
Export listings not yet in English* downloads
`propertybank-translations-<date>.json`: every listing not yet in English with
its title and description and empty `title_en` / `description_en`. Have it
translated (for example by Claude), then choose the file under *Import
translations*. Each listing carries a fingerprint of its text; a translation
of a text that changed on OLX since the export is not stored (export again).
The imported translations are marked with the name in *Translated by*.

**With a service:**

Titles and descriptions are translated by a service with an API key, set
under *Accounts and settings → Translation*:

- **DeepL** — recommended. Sign up at <https://www.deepl.com/pro-api> for
  *DeepL API Free*: 500,000 characters a month, no charge. A free key ends
  in `:fx`; a paid key works too.
- **Google Translate** — a Cloud Translation API key from a Google Cloud
  project with billing on.

Each refresh, after locating, translates the title and description of every
listing not yet in English — the new ones, and those whose text changed on
OLX — ten listings to a request, and stores the English in the row; the
application only reads it. The first refresh after setting up translation
sends every listing: about 2.5 million characters for Shevchenkivskyi. DeepL
Free allows 500,000 a month, so with a free key the backlog is worked off over
several months (the refresh says when the month's allowance is used up and
continues next time); with DeepL API Pro (pay per character) it is done at
once. Afterwards only new and changed listings are sent — a few hundred
thousand characters a month. The key stays on the server (it may also be set as
`translator_key` in `api/config.php` or the environment variable
`PB_TRANSLATOR_KEY`). *Sources → Test the connections*
checks the service.

## 5. How a refresh works

A shared host stops a PHP request after 30–60 s, so a refresh is a row in
`refresh_runs` with its work queue, and the browser asks for one ~18-second step
after another:

1. **fx** — exchange rates
2. **plan** — the price bands of each search
3. **list** — every page: new and changed listings are stored and marked for
   locating; when a search was read completely, listings no longer seen are
   marked *removed* (never when the read looks incomplete)
4. **enrich** — each new or changed listing: OLX's exact address, else text
   addresses, each geocoded and accepted only inside the listing's city and
   district; else OLX's map point if within ~1 km; then distances
5. **translate** — the title and description of every listing not yet in
   English, ten to a request, stored in the row
6. **finish** — every listing's USD and EUR price at the new rate

## 6. How it is built

```
index.php             the page (sign-in, the application shell)
install.php           the installer — delete after installing
api/index.php         the API (?p=listings, status, refresh/start|step|cancel, cities, searches, geocode, diag, auth/…, users, settings)
api/lib/olx.php       OLX: search, price bands, exact location, parsing, addresses/ceiling/year from text
api/lib/listings.php  storing, locating, distances, the rows the browser gets
api/lib/refresh.php   the stepped refresh
api/lib/geo.php       haversine, Nominatim with cache and pacing
api/lib/translit.php  Ukrainian (KMU 2010) and Russian transliteration, street kinds into English
api/lib/fx.php        ECB and NBU rates, conversion
api/lib/cities.php    landmarks and stations; Kyiv's defaults
assets/js/            the browser: listings (search, sort, table, detail), map (the schematic map), refresh (annunciator), cities, sources, admin
tools/                tests, fixtures, mock sources, release — never deployed
```

Accounts, sessions, CSRF, lockout and the installer are the Pricer's and
MarketData's. Filtering and sorting run in the browser on the whole list (a
few thousand rows, a few hundred kilobytes compressed).

### Data model

| Table | |
|---|---|
| `listings` | one row per listing: key data (original and English), coordinates and how they were found, distances (`geo`, JSON), prices, dates, active/removed, raw record |
| `geocode_cache` | every address asked of the geocoder, with the answer |
| `cities` | landmarks and stations per city (JSON) |
| `searches` | what a refresh reads |
| `refresh_runs` | each refresh, its queue and its result |
| `fx_rates` | the rates each refresh used |
| `users`, `settings`, `audit_log`, `login_attempts`, `api_tokens` | as in the Pricer |

## 7. Local testing

```bash
php tools/test-parse.php                                      # parsers against real OLX answers
php -S 127.0.0.1:8099 tools/mock-sources.php                  # invented listings in OLX's format, a fake geocoder, ECB, NBU
PB_SOURCES_MOCK=http://127.0.0.1:8099/ php -S 127.0.0.1:8000 -t ..
# then /propertybank/install.php, choose SQLite
```

The mock's listings are **invented** (1,240 flats) — for testing the
mechanics only.

## 8. Releasing

```bash
php tools/release.php --zip     # runs the checks, builds propertybank-X.zip and propertybank-X-update.zip
```

Update an installation as the Pricer: database backup, upload the `-update`
zip into `public_html`, *Extract* with overwrite, reload with Ctrl+F5.

## 9. Where to take it next

- **If OLX blocks the server:** a bookmarklet that runs on olx.ua in your own
  browser, reads the same API there and posts the listings to PropertyBank
  (the token mechanism and CORS are already in `tokens.php`).
- **More sites** (DOM.RIA, LUN): a `lib/<site>.php` with the same
  `parse_offer` shape and a `source` value.
- **Walking distances** to the stations (OSRM or OpenRouteService) beside the
  straight lines.
- **Price history**: keep each price change, show reductions.
- **Correcting a location** by hand where the text parser picked the wrong
  street.
- **Other cities' metro**: Kharkiv and Dnipro stations from OpenStreetMap.
