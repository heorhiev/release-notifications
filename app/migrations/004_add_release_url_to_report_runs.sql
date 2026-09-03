ALTER TABLE report_runs
    ADD COLUMN IF NOT EXISTS release_url TEXT;
