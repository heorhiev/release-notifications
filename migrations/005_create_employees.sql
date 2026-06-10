CREATE TABLE IF NOT EXISTS employees (
    id BIGSERIAL PRIMARY KEY,
    jira_user_id VARCHAR(255),
    slack_user_id VARCHAR(255) NOT NULL,
    role SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_employees_jira_user_id ON employees (jira_user_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_employees_slack_user_id ON employees (slack_user_id);
