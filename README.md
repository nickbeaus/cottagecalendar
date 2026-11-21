# Cottage Calendar

A shared booking calendar for the family cottages (Front, Back, or Both).
- **Booking:** pick arrival and departure on the calendar; overlapping stays are refused.
- **Recent Activity:** a log of every change.
- **Phone calendars:** a subscription feed.
- **Daily digest:** an email of yesterday's changes and the week's arrivals.

PHP + Apache + SQLite in one Docker container. Data lives in a Docker volume, so updating
the container never loses it.

## Install

You need Docker with the Compose plugin. Nothing is built on the server; the image is downloaded.

```sh
mkdir ~/cottagecalendar && cd ~/cottagecalendar
wget https://raw.githubusercontent.com/nickbeaus/cottagecalendar/main/compose.yaml
wget -O .env https://raw.githubusercontent.com/nickbeaus/cottagecalendar/main/.env.example
chmod 600 .env
nano .env
docker compose up -d
docker compose logs web
```

The log shows a **setup code** and the address of the setup page. Open the page and enter the
code. Then either load a backup from the old calendar or start with an empty one. Until
that's done, the calendar sends everyone to the setup page.

### Bringing over bookings from the old calendar

On the old server, open `/migrate.php`, enter the export PIN, and download the backup. On the
command line there, `php migrate.php export ~/micalendar-backup.json` does the same.

Choose that file on the setup page. Every booking and the whole activity log are copied
with their original ids, so phones subscribed to the feed don't see duplicates. If anything
in the file is wrong, nothing is saved and the page says which row is the problem.

The same import works from the command line. It's also the way to replace a calendar that
already has bookings:

```sh
docker compose cp micalendar-backup.json web:/tmp/backup.json
docker compose exec web php bin/import.php /tmp/backup.json --dry-run   # check only
docker compose exec web php bin/import.php /tmp/backup.json             # empty calendar
docker compose exec web php bin/import.php /tmp/backup.json --replace   # delete what's there first
```

## Settings (.env)

Put PINs and passwords in `'single quotes'`. Otherwise Docker Compose changes values that
contain `$` and cuts them off at ` #`. Apply changes with `docker compose up -d`.

| Setting | Meaning |
| --- | --- |
| `HOST_PORT` | Port on the Docker host that serves the site (default 8080) |
| `BASE_URL` | Public address of the site, ending with `/`. Used for the button in emails |
| `APP_TIMEZONE` | Timezone for "today", the digest's "yesterday", and times in emails |
| `TRUSTED_PROXIES` | IP address(es) of your reverse proxy, comma-separated. Leave empty if none |
| `APP_PINS` | Family PINs, comma-separated. Any one of them unlocks the calendar |
| `ICAL_USER`, `ICAL_PASS` | Username and password for the phone calendar feed. Empty password = feed off |
| `EXPORT_PIN` | PIN for the backup page, separate from the family PINs. Empty = web backups off |
| `SMTP_HOST`, `SMTP_PORT` | Mail server, e.g. port 587 (STARTTLS) or 465 (SSL) |
| `SMTP_SECURITY` | `starttls`, `ssl` or `none`. Empty = chosen from the port |
| `SMTP_USER`, `SMTP_PASS` | Mail server login |
| `MAIL_FROM`, `MAIL_FROM_NAME` | Sender address (default `SMTP_USER`) and name |
| `DIGEST_RECIPIENTS` | Who gets the daily digest, comma-separated (sent as BCC). Empty = no digest |
| `DIGEST_TIME` | When to send it, e.g. `07:00`, in `APP_TIMEZONE` |

**Behind a reverse proxy**, set `TRUSTED_PROXIES` to the proxy's IP as the container sees it.
Then the activity log records the real visitor's IP, and lockouts apply to that IP instead of the proxy's.

Ten wrong PINs or feed passwords from one IP address in 15 minutes lock that address out
for 15 minutes. The calendar, the feed, the backup page and the setup page are counted separately.

## Email digest

The container sends the digest itself at `DIGEST_TIME` every day; no cron job is needed.
- **Contents:** yesterday's changes and the stays arriving in the next 7 days.
- **Quiet days:** if nothing changed and nobody arrives exactly a week out, no email is sent.
- **Missed or failed sends:** if the container was down at that time, it sends when it starts
  again that day. A failed send is retried every 30 minutes.

The container log (`docker compose logs web`) shows what happened each day. To check the
settings:

```sh
docker compose exec web php bin/digest.php test you@example.org   # send a test email
docker compose exec web php bin/digest.php preview                # show today's digest; sends nothing
docker compose exec web php bin/digest.php send                   # send today's digest now
```

## Phone calendars

**Add to My Phone's Calendar** on the calendar page explains how to subscribe. The feed is
`ical.php` with `ICAL_USER` / `ICAL_PASS`. Phones that subscribed to the old calendar keep
working if the site keeps the same address and you keep the same feed username and password.

## Backups

- **On the web:** `/backup.php`, using `EXPORT_PIN`. Each download shows up in Recent Activity.
  The old `/migrate.php` address redirects there.
- **On the command line:**

  ```sh
  docker compose exec -T web php bin/export.php - > micalendar-backup-$(date +%F).json
  ```

  `-T` keeps the file exactly as written.

Either file can be loaded into a fresh install as described above. Backups contain names
and IP addresses, so keep them private.

## Updating

Change the version in `compose.yaml`, then:

```sh
docker compose pull && docker compose up -d
```

Database changes are applied automatically at startup. Before changing an existing database,
a copy is saved in the data volume as `calendar.sqlite.before-vN.<date>.bak`.

## Development

Build from this folder instead of downloading the image:

```sh
cp .env.example .env
docker compose -f compose.yaml -f compose.build.yaml up -d --build
```

| Path | Contents |
| --- | --- |
| `app/html/` | The pages (Apache's web root): `index.html` (the calendar), `api.php`, `ical.php`, `backup.php`, `setup.php` |
| `app/includes/` | Settings, database, PIN checks, backup format, email and digest (not reachable from the web) |
| `app/migrations/` | Database schema, applied in order at startup |
| `app/bin/` | Command-line tools: `migrate.php`, `import.php`, `export.php`, `digest.php`, and the background `scheduler.php` |
| `app/composer.json` | PHPMailer, installed into `app/vendor/` when the image is built |
| `docker/` | Apache, PHP and startup configuration |

## Releasing

Pushing a version tag builds the image for amd64 and arm64 and publishes it to
`ghcr.io/nickbeaus/cottagecalendar`:

```sh
git tag v1.0.1
git push && git push origin v1.0.1
```

Set the same version in `compose.yaml` and commit it before tagging.
