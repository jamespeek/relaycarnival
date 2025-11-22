# Capital Athletics Relay Carnival results

## Files audit

* `index.php` User-facing results page, loads data via `./data/results.json` and triggers regenerate when in dev or when `result` query argument supplied with correct key.
* `data.php` Uses the API to `GET /results` and transform it into a single JSON file with score calculations and tally table included.
* `utils.php` Variety of env, string and database utilities that are used between files.
* `results.php` Single page entry results screen.
* `api/index.php` Provides HTTP/REST access to the various GET/POST/DELETE endpoints to interact with the database.
* `api/.htaccess` Allows `./api/results` and `./api/races` etc to remap incoming HTTP calls to `index.php` for the various endpoints.
* `db/` Database scripts for recreating the database and injecting the required races.
* `data/` Stores the generates JSON file as well as an export of the Capital Athletic relay records.
* `assets/` Stores images.

## Deployment model

`index.php`, `utils.php`, and `.env` plus `./assets` and `./data` directories on public facing web server (no database). Currently this is https://relaycarnival.capitalathletics.au

All files also exist on non-advertised/protected web server for data entry via API + database. 

For speed and security, the public site can call private server `data.php` endpont to generate and save the JSON via `?refresh=xxx` call which can be run as required.

## Environment variables

The following environment variables must exist in `.env`

```
EVENT_NAME=Relay Carnival 20xx-xx

JSON_URL=https://abc.com/data.php // private admin server URL to grab JSON from
SYNC_KEY=relaycarnivalsync // magic key to use for triggering sync of JSON file via JSON_URL

DB_HOST=x.x.x.x
DB_NAME=relaycarnival
DB_USERNAME=relaycarnival
DB_PASSWORD=xxxxx
DB_CHARSET=utf8mb4

GTAG=G-xxxxxxxxxx // Google analytics tag
```