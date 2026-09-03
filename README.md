# Jira Release Summary Bot

A PHP 8.3 service that collects Jira issues by release (`fixVersion`), builds a rule-based summary, sends it to Slack, and stores report history in PostgreSQL.

## Quick up

Create the local environment file and fill in the Jira and Slack credentials:

```bash
cp .env.example .env
```

Build and start the services:

```bash
docker compose up -d --build
```

After PostgreSQL becomes healthy, apply the database migrations:

```bash
docker compose exec -T jira-release-bot php bin/migrate.php
```

Verify the backend:

```bash
curl http://localhost:8082/health
```

Application architecture and code structure are documented in [app/README.md](app/README.md).

## Features

- Fetches Jira issues by project and `fixVersion`.
- Builds deterministic rule-based summaries without AI.
- Groups issues by epic and applies database-driven epic priorities.
- Sends the release summary to Slack.
- Optionally sends separate developer-check and reporter-check messages.
- Orders Slack mentions using employee priorities stored in PostgreSQL.
- Stores each report run, its summary, and Jira issue snapshots.
- Exposes health and diagnostic HTTP endpoints.
- Supports previewing a saved report before sending it.

The bot does not send a separate `Issue Details` block to Slack. Complete Jira issue snapshots are stored only in PostgreSQL.

## Configuration

Copy [.env.example](.env.example) to `.env` and replace the placeholder values.

Required Jira variables:

- `JIRA_BASE_URL`
- `JIRA_EMAIL`
- `JIRA_API_TOKEN`
- `JIRA_PROJECT_KEY`

Required Slack variables:

- `SLACK_TRANSPORT`: `incoming_webhook` or `workflow_trigger`
- `SLACK_WEBHOOK_URL`

Application and database variables:

- `APP_ENV`
- `APP_PORT`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

Optional Slack variables:

- `SLACK_CHANNEL`
- `SLACK_USERNAME`
- `SLACK_ICON_EMOJI`
- `SLACK_DEVELOPERS_CHECK_TEXT`
- `SLACK_REPORTERS_CHECK_TEXT`
- `SLACK_REPORTERS_EXTRA_USER_IDS`

`SLACK_DEVELOPERS_CHECK_TEXT` and `SLACK_REPORTERS_CHECK_TEXT` support these placeholders:

- `{release}`: current Jira release name.
- `{next_release}`: next Jira release by natural name ordering.
- `{url_user_tasks}`: Jira list URL for the current user's issues in the release.
- `{developers}`: Slack mentions for employees with the developer role.
- `{reporters}`: Slack mentions for reporters found in the release issues.

Use Slack mrkdwn syntax in message templates. For example:

```text
*Bold text*
<https://example.com|Link text>
```

When a template begins with `---`, the formatter ensures that a blank line separates it from the following paragraph.

## Docker

The complete startup sequence is documented in [Quick up](#quick-up). Check container status and application logs with:

```bash
docker compose ps
docker compose logs --tail=80 jira-release-bot
```

Stop the stack:

```bash
docker compose down
```

The production image excludes `.env`, `.git`, local Composer dependencies, IDE metadata, and PHPUnit cache files from its build context.

## Tests

Run unit tests and PostgreSQL integration tests:

```bash
docker compose --profile test run --rm tests
```

The test service uses an isolated PostgreSQL instance backed by `tmpfs` and does not use the application database.

To stop the test database after a run:

```bash
docker compose --profile test stop postgres-test
```

## CLI

### Build and save a report

For a specific Jira release:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 15"
```

For the latest Jira release:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php
```

or explicitly:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php --latest
```

This command always creates a dry-run report: it fetches Jira issues, builds the summary, and saves the report without sending anything to Slack. Jira release names must match exactly, including spaces.

### Send a saved report

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
docker compose exec -T jira-release-bot php bin/send-release-report.php --run-id=12
```

Preview a saved report without sending it:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php --run-id=12 --preview
```

The send command uses the saved summary and issue snapshots. It may query Jira project versions to resolve a missing release URL and determine the next release name.

## Common workflows

Build and save a report, preview its Slack messages, and then send the same saved report:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 15"
docker compose exec -T jira-release-bot php bin/send-release-report.php --run-id=12 --preview
docker compose exec -T jira-release-bot php bin/send-release-report.php --run-id=12
```

Replace `12` with the `report_run_id` returned by the build command.

Build the latest release and send it immediately through the HTTP API:

```bash
curl -X POST http://localhost:8082/release-report \
  -H "Content-Type: application/json" \
  -d '{"latest_release":true,"dry_run":false}'
```

## HTTP API

### `GET /health`

```bash
curl http://localhost:8082/health
```

### `POST /release-report`

Builds and stores a report. It also sends Slack messages when `dry_run` is `false`.

```bash
curl -X POST http://localhost:8082/release-report \
  -H "Content-Type: application/json" \
  -d '{
    "release": "2026 - 15",
    "dry_run": true
  }'
```

Request fields:

- `release`: exact Jira release / `fixVersion` name;
- `latest_release`: use the latest non-archived Jira release. It cannot be combined with `release`;
- `dry_run`: when `true`, do not send Slack messages.

If neither `release` nor `latest_release` is provided, the latest Jira release is used.

### Diagnostic endpoints

```bash
curl http://localhost:8082/debug/project-versions
curl "http://localhost:8082/debug/jira-search?release=2026%20-%2015"
curl "http://localhost:8082/debug/summary?release=2026%20-%2015"
curl "http://localhost:8082/debug/report-runs?limit=20"
curl http://localhost:8082/debug/report-runs/12
```

The application currently does not authenticate `/debug/*` endpoints. Do not expose them publicly without access controls.

## Slack Output

Up to three separate messages are sent:

1. Release name and rule-based summary.
2. Developer-check message from `SLACK_DEVELOPERS_CHECK_TEXT`, when configured.
3. Reporter-check message from `SLACK_REPORTERS_CHECK_TEXT`, when configured.

The full Jira issue snapshot remains in PostgreSQL and is not included as a separate Slack message.
