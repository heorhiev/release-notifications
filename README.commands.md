# Command Reference

The project runs with Docker Compose. Application commands are executed in the `jira-release-bot` service.

## Slack Transport

The default transport is an incoming webhook:

```env
SLACK_TRANSPORT=incoming_webhook
```

The `workflow_trigger` transport is also supported when `SLACK_WEBHOOK_URL` points to a Slack workflow trigger URL.

The developer-check and reporter-check messages are configured with:

```env
SLACK_DEVELOPERS_CHECK_TEXT="---\n*Dear Team*, please check that all your tasks are included in release {release}."
SLACK_REPORTERS_CHECK_TEXT="*Dear requesters!)*\n\nPlease check <{url_user_tasks}|your tasks>."
```

Both templates support these placeholders:

- `{release}`: current Jira release name.
- `{next_release}`: next Jira release by natural name ordering.
- `{url_user_tasks}`: Jira list URL for the current user's issues in the release.
- `{developers}`: Slack mentions for employees with `role = 1`.
- `{reporters}`: Slack mentions for reporters found in the release issues.

Use Slack mrkdwn syntax, such as `*bold text*` and `<https://example.com|link text>`. When a template begins with `---`, the formatter adds a blank line before the following paragraph.

## Employee and Epic Priorities

Jira-to-Slack mappings are stored in `employees`:

```sql
INSERT INTO employees (jira_user_id, slack_user_id, role, priority)
VALUES ('jira-account-id', 'U12345678', 1, 10);
```

Higher employee priorities are mentioned first. Equal priorities are ordered by Slack user ID. The allowed priority range is `0..255`.

Epic ordering is configured through the `epics` table:

```sql
INSERT INTO epics (jira_key, priority)
VALUES ('PROJ-123', 0)
ON CONFLICT (jira_key)
DO UPDATE SET priority = EXCLUDED.priority, updated_at = NOW();
```

Higher epic priorities are displayed first. Epics not present in the table use priority `1`; equal priorities are ordered alphabetically. The allowed range is `0..255`.

## Docker

Build and start the stack:

```bash
docker compose up --build
```

Start an already built stack in the background:

```bash
docker compose up -d
```

Check container status and application logs:

```bash
docker compose ps
docker compose logs --tail=80 jira-release-bot
```

Stop the stack:

```bash
docker compose down
```

## Migrations

Apply pending SQL migrations after PostgreSQL is healthy:

```bash
docker compose exec jira-release-bot php bin/migrate.php
```

Migration files are read from [migrations](migrations), and already applied versions are skipped.

## Tests

Run unit tests and PostgreSQL repository integration tests:

```bash
docker compose --profile test run --rm tests
```

The test service uses an isolated temporary PostgreSQL database. Stop it after the run with:

```bash
docker compose --profile test stop postgres-test
```

## Build and Save a Report

Build a report for a specific Jira release:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 15"
```

Build a report for the latest Jira release:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php
```

or explicitly:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php --latest
```

The command:

- fetches Jira issues by `fixVersion`;
- builds a deterministic rule-based summary;
- stores the report run in PostgreSQL;
- stores issue snapshots in `report_run_issues`;
- does not send anything to Slack.

Jira release names must match exactly, including spaces. The CLI does not support AI summary modes, department grouping, or a separate issue-details message.

## Preview and Send a Saved Report

Send the latest unsent report:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php
```

Send the latest unsent report for a release:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php "2026 - 15"
```

Send a specific report run:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php --run-id=65
```

Preview a specific run without sending it:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php --run-id=65 --preview
```

The command sends up to three Slack messages:

1. The saved release summary.
2. The developer-check message, when `SLACK_DEVELOPERS_CHECK_TEXT` is configured.
3. The reporter-check message, when `SLACK_REPORTERS_CHECK_TEXT` is configured.

It reuses the saved summary and issue snapshots instead of rebuilding them. It may query Jira project versions to resolve a missing release URL and determine the next release name. After a successful send, the report run is marked as sent.

## HTTP API

### Health Check

```bash
curl http://localhost:8080/health
```

### Build or Send a Report

`POST /release-report` builds and stores a report. Set `dry_run` to `false` to send it to Slack in the same request.

```bash
curl -X POST http://localhost:8080/release-report \
  -H "Content-Type: application/json" \
  -d '{
    "release": "2026 - 15",
    "dry_run": true
  }'
```

Request fields:

- `release`: exact Jira release / `fixVersion` name.
- `latest_release`: use the latest non-archived Jira release; it cannot be combined with `release`.
- `dry_run`: when `true`, do not send Slack messages.

If neither `release` nor `latest_release` is provided, the latest Jira release is used.

### Diagnostic Endpoints

```bash
curl http://localhost:8080/debug/project-versions
curl "http://localhost:8080/debug/jira-search?release=2026%20-%2015"
curl "http://localhost:8080/debug/summary?release=2026%20-%2015"
curl "http://localhost:8080/debug/report-runs?limit=20"
curl http://localhost:8080/debug/report-runs/65
```

The `/debug/*` endpoints are not authenticated. Do not expose them publicly without access controls.

## Common Workflows

Build, preview, and then send a report:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 15"
docker compose exec -T jira-release-bot php bin/send-release-report.php --run-id=65 --preview
docker compose exec -T jira-release-bot php bin/send-release-report.php --run-id=65
```

Replace `65` with the `report_run_id` returned by the build command.

Run the complete pipeline through HTTP:

```bash
curl -X POST http://localhost:8080/release-report \
  -H "Content-Type: application/json" \
  -d '{"release":"2026 - 15","dry_run":false}'
```
