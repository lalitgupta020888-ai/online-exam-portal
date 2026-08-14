# Deploying the Online Exam Portal

The app is a stock PHP 8 + MySQL application, so it runs on anything that can
serve PHP and reach a MySQL server. The [`Dockerfile`](Dockerfile) reproduces
the XAMPP stack (Apache + PHP 8.2 + `pdo_mysql`) and binds to whatever port the
host assigns through `$PORT`.

## Configuration

No credentials are baked into the image. `config/config.php` reads them from
the environment and falls back to the XAMPP defaults when nothing is set, so a
local checkout keeps working unchanged.

| Variable | Default | Notes |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | Also accepts Railway's `MYSQLHOST` |
| `DB_PORT` | `3306` | Also accepts `MYSQLPORT` |
| `DB_USER` | `root` | Also accepts `MYSQLUSER` |
| `DB_PASS` | *(empty)* | Also accepts `MYSQLPASSWORD` |
| `DB_NAME` | `online_exam` | **Not** aliased to `MYSQLDATABASE` - see below |
| `APP_DEBUG` | `1` | Set to `0` in production to hide PHP errors |

`DB_NAME` is deliberately not read from `MYSQLDATABASE`. Managed MySQL services
hand you a database named after the platform (Railway calls it `railway`), but
`database/schema.sql` creates and populates one called `online_exam`. Pointing
the app at the platform's empty database would produce "table not found" on
every page.

## Railway

The project is deployed and live at **https://onlineexam.up.railway.app**.

Two services sit in the Railway project `online-exam-portal`:

| Service | What it is |
|---|---|
| `online-exam` | This app, built from the `Dockerfile`, volume at `/var/www/html/uploads` |
| `MySQL` | MySQL 9.4, volume at `/var/lib/mysql` |

The app service is configured with these variables. The `${{MySQL.*}}` form is
Railway's reference syntax: it reads the live value from the MySQL service, so
nothing is copied by hand and rotating the database password does not break the
app.

```
DB_HOST   = ${{MySQL.MYSQLHOST}}
DB_PORT   = ${{MySQL.MYSQLPORT}}
DB_USER   = ${{MySQL.MYSQLUSER}}
DB_PASS   = ${{MySQL.MYSQLPASSWORD}}
DB_NAME   = online_exam
APP_DEBUG = 0
PORT      = 8080
```

`PORT` is set explicitly because the generated domain forwards to port 8080,
while the Apache base image is hard-wired to 80. The entrypoint rewrites
Apache's `Listen` directive to whatever `PORT` says.

### Redeploying

```bash
railway up --service online-exam
```

This uploads the working directory and rebuilds, so it deploys local changes -
committed or not. Push to GitHub separately.

### Recreating the project from scratch

```bash
railway init --name online-exam-portal
railway add --database mysql
railway add --service online-exam
railway variables --service online-exam \
  --set 'DB_HOST=${{MySQL.MYSQLHOST}}' --set 'DB_PORT=${{MySQL.MYSQLPORT}}' \
  --set 'DB_USER=${{MySQL.MYSQLUSER}}' --set 'DB_PASS=${{MySQL.MYSQLPASSWORD}}' \
  --set 'DB_NAME=online_exam' --set 'APP_DEBUG=0' --set 'PORT=8080'
railway volume add --mount-path /var/www/html/uploads
railway domain --service online-exam --port 8080
railway up --service online-exam
```

Then run the installer once - see below.

## Running the installer on a deployed site

`install.php` and `upgrade.php` are listed in `.dockerignore`, so they are
**not** shipped to the server. Both accept an unauthenticated POST that re-runs
the schema and resets the administrator password back to `admin123`; on a public
URL that is an open door to anyone who guesses the filename.

The schema on the live site has already been created. To run one of them again:
comment its line out of `.dockerignore`, `railway up`, use it, then restore the
line and `railway up` again.

The installer seeds two accounts:

| Role | Username / email | Password |
|---|---|---|
| Administrator | `admin` | `admin123` |
| Demo student | `student@example.com` | `student123` |

**Change both passwords** - the defaults are published in this file and in the
repository's history.

## Uploaded files

Container filesystems are wiped on every redeploy, so college logos written to
`uploads/logos/` would not survive one. A Railway volume is mounted at
`/var/www/html/uploads` to keep them.

A mounted volume replaces the directory baked into the image and arrives owned
by `root`, so the `Dockerfile`'s build-time `chown` does not apply to it. The
entrypoint re-creates `uploads/logos` and chowns it to `www-data` on every boot;
without that, Apache cannot write logos to the volume.

## Local development

Unchanged. Start MySQL and Apache in XAMPP with the project in `htdocs`, or
serve it directly:

```bash
php -S 127.0.0.1:8080 -t .
```

Both pick up the fallback values, so no environment variables are needed.
