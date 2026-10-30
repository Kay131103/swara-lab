# MeloMaster

MeloMaster converts short monophonic audio into musical notes and MIDI. The
application is packaged as a PHP/Python container for Vercel; PostgreSQL stores
users, sessions, and results, while Vercel Blob stores private audio and MIDI.

## Vercel Hobby deployment

This deployment uses Vercel's Container Runtime (currently beta). Hobby is free
for personal, non-commercial projects, but has usage limits. Its functions can
run for at most five minutes and include four CPU-hours per month. Transcription
therefore runs synchronously during the upload request and is capped at 4 MB and
30 seconds. Keep an eye on Vercel usage; this is intended for a small demo, not
continuous public traffic.

1. Push the repository to GitHub and import it into Vercel. The root
   `Dockerfile` selects the Container preset.
2. Create a PostgreSQL database through the Vercel Marketplace and connect it
   to the project. The app prefers `POSTGRES_URL_NON_POOLING` (needed for
   session locking), then uses `POSTGRES_URL` or `DATABASE_URL`.
3. Create a **private** Vercel Blob store and connect it to the project. Vercel
   supplies the Blob credentials to the runtime.
4. Apply the schema to that database from a machine with `psql` installed:

   ```sh
   psql "$POSTGRES_URL" -f login_email_verification.sql
   ```

5. Add these environment variables in Vercel:

   - `APP_ENV=production`
   - `SMTP_ENABLED=1`, `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS`, and `SMTP_FROM`
   - `CRON_SECRET` set to a long random value
   - A direct `POSTGRES_URL_NON_POOLING`, or `POSTGRES_URL`/`DATABASE_URL` if
     the integration does not provide a direct connection URL

6. Deploy. The daily Vercel Cron calls `cleanup.php` to remove expired Blob
   files and database rows after 24 hours. Do not commit `.env` or `config.php`.

Vercel Blob and Marketplace database usage may have separate quotas or charges;
check each provider's current pricing and set usage alerts before sharing the
site publicly. New accounts are verified by email, so SMTP must be configured
for registration to work in production.

## Local development

Create `.env` with a database password and start the local PostgreSQL database
and PHP/Python container:

```env
DB_PASS=choose-a-local-password
```

```sh
docker compose up --build
```

Open <http://localhost:8080>. Local Docker Compose uses a private local volume
for files; Vercel deployments use private Blob storage. To configure either
backend, set `MAX_CONCURRENT_JOBS_PER_USER` (default 1),
`MAX_JOBS_PER_HOUR` (default 10), `MAX_USER_STORAGE_MB` (default 100),
`MAX_UPLOAD_MB` (maximum 4), and `MAX_AUDIO_SECONDS` (maximum 30).

Run the transcription tests with `python -m pytest`.

## Existing MySQL database

This version uses PostgreSQL. The SQL schema is not a MySQL migration; existing
accounts and data must be exported and converted before moving to PostgreSQL.
Do not point the PostgreSQL app at the old MySQL database.