# Space TFRs

Watches the FAA's Temporary Flight Restrictions for space operations (launches, reentries,
hazard areas), renders a map of each one, and posts it to Mastodon and Bluesky.

| Script | Schedule | What it does |
|---|---|---|
| `tfrmonitor.php` | every 3 minutes | Finds new space TFRs, renders their maps and posts them. `TFRMONITOR_DRY_RUN=1 php tfrmonitor.php [notam_id ...]` processes without saving, rendering or posting. |
| `45th_weather.php` | every 30 minutes | Scrapes the 45th Weather Squadron launch forecasts and reports changes tied to launches |
| `rocketlaunchlivescraper.php` | every 6 hours | Pulls upcoming launches from RocketLaunch.Live to match TFRs with launches |

`cron/crontab.txt` is a snapshot of the live schedule (including the cleanup of old map images).

## Layout

| Path | What it is |
|---|---|
| `common.inc` | DB connection, `bot_cfg()` config reader, TFR parsing, map rendering, posting helpers |
| `render_*.php` | Map and image rendering |
| `toot.sh`, `skeet.sh` | Post to Mastodon and Bluesky |
| `templates/` | Image templates and logos |
| `launch_sites.kml` | Launch site reference data (`launch_sites_import.php`) |
| `tootophp/` | Small Mastodon client library |
| `schema.sql` | Database structure (no data) |

## Setup

1. Create the database from `schema.sql`.
2. `cp config.example.ini config.ini`, fill it in, then `chown root:www-data config.ini && chmod 640 config.ini`.
3. For the tootophp library: `cp tootophp/tootophp.example.json tootophp/tootophp.json` and fill it in.
4. `php composer.phar install` (or `composer install`). `skeet.sh` also needs the `bsky` CLI in `/opt/bsky_sh_cli/bin`.
5. Fonts are not in the repo (third-party licenses). Put these in `fonts/`: `arial.ttf`, `arialbd.ttf`,
   `ariblk.ttf`, `courbd.ttf`.
6. Install the cron lines from `cron/crontab.txt`.

`config.ini`, `tootophp/tootophp.json`, logs, rendered maps, downloaded data and backups are
git-ignored; never commit real credentials.
