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

1. **Create the project.** On [railway.app](https://railway.app) choose
   *New Project* > *Deploy from GitHub repo* and pick `online-exam-portal`.
   Railway detects the `Dockerfile` and builds it - no build settings to fill in.

2. **Add the database.** In the same project click *New* > *Database* >
   *Add MySQL*. This creates a second service on the project's private network.

3. **Wire the two together.** Open the **app** service > *Variables* and add:

   ```
   DB_HOST      = ${{MySQL.MYSQLHOST}}
   DB_PORT      = ${{MySQL.MYSQLPORT}}
   DB_USER      = ${{MySQL.MYSQLUSER}}
   DB_PASS      = ${{MySQL.MYSQLPASSWORD}}
   DB_NAME      = online_exam
   APP_DEBUG    = 0
   ```

   The `${{MySQL.*}}` form is Railway's reference syntax - it pulls the live
   value from the MySQL service, so nothing is copied by hand and rotating the
   database password does not break the app.

4. **Expose the app.** App service > *Settings* > *Networking* >
   *Generate Domain*. You get a `*.up.railway.app` URL.

5. **Create the schema.** Visit `https://<your-domain>/install.php` once and
   press the install button. It runs `database/schema.sql`, applies the faculty
   and college migrations, and creates the two seed accounts:

   | Role | Username / email | Password |
   |---|---|---|
   | Administrator | `admin` | `admin123` |
   | Demo student | `student@example.com` | `student123` |

6. **Lock it down.** Change both passwords immediately, then delete
   `install.php` and `upgrade.php` from the repo and push - they re-run schema
   changes and must not sit on a public URL.

## Uploaded files

Container filesystems are wiped on every redeploy, so college logos written to
`uploads/logos/` would not survive one. To keep them, add a Railway **Volume**
to the app service mounted at `/var/www/html/uploads`. Without a volume the app
still works - only the uploaded images are lost on redeploy.

## Local development

Unchanged. Start MySQL and Apache in XAMPP with the project in `htdocs`, or
serve it directly:

```bash
php -S 127.0.0.1:8080 -t .
```

Both pick up the fallback values, so no environment variables are needed.
