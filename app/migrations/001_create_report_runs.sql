CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(255) PRIMARY KEY,
    executed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS report_runs (
    id BIGSERIAL PRIMARY KEY,
    release_name VARCHAR(255) NOT NULL,
    issues_count INTEGER NOT NULL,
    include_description BOOLEAN NOT NULL,
    dry_run BOOLEAN NOT NULL,
    slack_sent BOOLEAN NOT NULL,
    message_preview TEXT NOT NULL,
    jira_jql TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS report_run_issues (
    id BIGSERIAL PRIMARY KEY,
    report_run_id BIGINT NOT NULL REFERENCES report_runs(id) ON DELETE CASCADE,
    issue_key VARCHAR(64) NOT NULL,
    summary TEXT NOT NULL,
    issue_type VARCHAR(128) NOT NULL,
    status VARCHAR(128) NOT NULL,
    assignee VARCHAR(255),
    description TEXT,
    raw_issue JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_report_runs_release_name ON report_runs (release_name);
CREATE INDEX IF NOT EXISTS idx_report_runs_created_at ON report_runs (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_report_run_issues_report_run_id ON report_run_issues (report_run_id);
CREATE INDEX IF NOT EXISTS idx_report_run_issues_issue_key ON report_run_issues (issue_key);

