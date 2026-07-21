# AgeSense — Database Backup & Recovery Guide

This guide covers taking and restoring ad-hoc backups of the AgeSense MySQL database (`osca_db`). It is a separate concern from [DATABASE_SHARING.md](DATABASE_SHARING.md), which covers keeping multiple team devices in sync with the same dataset — the same underlying `mysqldump`/`mysql` commands are used, but backups exist purely to protect against data loss, not to synchronize devices.

> **⚠️ These dumps contain senior citizens' personal and health data.** Treat every backup file as sensitive as the live database itself.

---

## What's encrypted, what isn't

Three fields (`contact_number`, `place_of_birth`, `philsys_id` on `senior_citizens`) are encrypted at the application level via Laravel's `Crypt` facade (`App\Casts\EncryptedOrPlainText`, using `APP_KEY`). A raw `mysqldump` captures the column values exactly as MySQL stores them — for these three fields, that's **ciphertext**, unreadable without the matching `APP_KEY`.

**Every other field is plain text in the dump** — full names, barangay, date of birth, all QoL survey responses, health/dependency/economic indicators, GPS coordinates, ML risk scores, and recommendations. Losing or exposing a backup file is equivalent to exposing that data. This is why backups are gitignored (`database/backups/*`, enforced in `.gitignore`) and must never be committed, emailed, or uploaded to a shared drive without access controls.

---

## Taking a backup

### From the app (recommended for non-technical admins)

Log in as an **admin** → sidebar **Registry and Backup** → **Create Backup Now**. This runs the
same `mysqldump` logic as the PowerShell script (same flags, same password-via-env-var handling,
same loud-failure-on-empty-dump guard), writing to `database/backups/osca_backup_YYYYMMDD_HHmmss.sql`.
Only the **latest 3** app-created backups are kept — older ones are deleted automatically the next
time a backup is created. This rotation only ever touches its own `osca_backup_*` files; it never
deletes manual dumps or the PowerShell script's `osca_db_backup_*` files.

From the same page you can **download** one of the latest 3 backups to your machine, or **delete**
one manually. Every create/download/delete is recorded in the Activity Log. Downloaded files carry
the same PII as any other backup — see the warning above.

### From the command line

From the repo root, on any team device with `mysqldump` available (bundled with Laragon/XAMPP/a standalone MySQL install — the script checks PATH and the common install locations for each):

```powershell
.\backup-database.ps1
```

The script:
- Reads `DB_DATABASE` / `DB_HOST` / `DB_PORT` / `DB_USERNAME` / `DB_PASSWORD` from your local `.env` (never hardcoded, so it works the same way on every device).
- Passes the password via the `MYSQL_PWD` environment variable rather than a command-line argument, so it never appears in process listings or shell history, and clears it immediately afterward.
- Writes a timestamped dump to `database/backups/osca_db_backup_YYYYMMDD_HHmmss.sql` (already gitignored — the folder itself stays tracked via `.gitkeep`, its contents never do).
- Fails loudly — non-zero exit and a clear message — if `mysqldump` isn't found, `.env` is missing, the dump command itself fails, or the result is a 0-byte file. It never leaves a silently empty or partial backup behind.
- Does not auto-rotate — CLI dumps accumulate until you delete them manually.

## Where to keep backups

- **Locally**: `database/backups/` (default, already gitignored).
- **Off-machine**: keep at least one copy somewhere other than the laptop that produced it — a team shared drive with restricted access, an encrypted external disk, or similar. The point is surviving a single device failure, not building a distributed archive; a small pilot project doesn't need more than that.
- **Never**: git, personal email, public cloud storage without access controls, or any channel the wider team doesn't control.

## When to back up

There's no automated schedule for this project (no cron/queue-scheduler infrastructure exists, and adding one would be overkill for a small pilot system) — back up manually:
- Before any bulk data operation (bulk upload, mass re-analysis, schema migration).
- Before defense day or a live demo.
- Periodically during active development, at whatever cadence feels reasonable for how much re-entry would hurt if lost.

## Restoring a backup

**Always restore into a scratch/test database first to verify the dump before touching a live database.** A backup you haven't verified you can restore isn't a backup you can rely on.

1. Create (or reuse) a scratch database:
   ```sql
   CREATE DATABASE osca_db_restore_test;
   ```
2. Restore into it:
   ```powershell
   mysql -u root -p osca_db_restore_test < database\backups\osca_db_backup_20260709_120000.sql
   ```
   (On Windows, `mysql` prompts for the password interactively if you omit it from the command — preferred over typing it inline, for the same reason `backup-database.ps1` avoids passing it as an argument.)
3. Spot-check row counts and a few records against what you expect before trusting the dump:
   ```sql
   USE osca_db_restore_test;
   SELECT COUNT(*) FROM senior_citizens;
   SELECT COUNT(*) FROM ml_results;
   ```
4. Only once you've confirmed the scratch restore looks right, restore into the real `osca_db` if that's actually what you need to do — and only after understanding that this **overwrites** whatever is currently there:
   ```powershell
   mysql -u root -p osca_db < database\backups\osca_db_backup_20260709_120000.sql
   ```
5. Drop the scratch database when you're done with it:
   ```sql
   DROP DATABASE osca_db_restore_test;
   ```
