CREATE TABLE epics (
    id BIGSERIAL PRIMARY KEY,
    jira_key VARCHAR(255) NOT NULL,
    priority SMALLINT NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_epics_jira_key UNIQUE (jira_key),
    CONSTRAINT chk_epics_priority_unsigned_tinyint CHECK (priority BETWEEN 0 AND 255)
);

INSERT INTO epics (jira_key, priority)
VALUES ('ADSY-14403', 0)
ON CONFLICT (jira_key)
DO UPDATE SET priority = EXCLUDED.priority, updated_at = NOW();
