# Jira Release Report Codex Plugin

Codex plugin for the Jira Release Report service in this repository.

## Tools

- `jira_release_report_health` checks the backend health endpoint.
- `build_jira_release_report` builds and stores a report without sending it to Slack.
- `send_jira_release_report` builds, stores, and sends a report to Slack.

## Configuration

The MCP server reads:

- `JIRA_RELEASE_REPORT_API_URL` — backend URL, default `http://localhost:8082`;
- `JIRA_RELEASE_REPORT_TIMEOUT_MS` — HTTP timeout in milliseconds, default `90000`.

Start the backend before using the plugin:

```bash
docker compose up -d --build
```

The backend keeps Jira, Slack, and database credentials in its own `.env`; do not place those secrets in the plugin.
