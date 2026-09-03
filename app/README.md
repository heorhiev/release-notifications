# Jira Release Report Application

This directory contains the PHP application responsible for collecting Jira release issues, building deterministic summaries, storing report history, and publishing explicitly requested reports to Slack.

## Architecture

- HTTP entrypoint: [public/index.php](public/index.php)
- Application workflow: [src/ReleaseReportWorkflow.php](src/ReleaseReportWorkflow.php)
- External clients: [src/Client](src/Client)
- Contracts: [src/Contracts](src/Contracts)
- Data transfer objects: [src/DTO](src/DTO)
- Infrastructure and database access: [src/Infrastructure](src/Infrastructure)
- Domain models: [src/Model](src/Model)
- Repositories: [src/Repository](src/Repository)
- Summary engine: [src/ReleaseSummary](src/ReleaseSummary)
- Slack transports: [src/Slack](src/Slack)
- Shared helpers: [src/Support](src/Support)
- SQL migrations: [migrations](migrations)

## Report workflow

1. A client calls `POST /release-report` or `bin/release-report.php`.
2. The workflow fetches Jira issues for the configured project and release.
3. The summary engine groups issues and applies epic priorities.
4. The formatter builds the Slack messages.
5. The report and Jira issue snapshots are stored in PostgreSQL.
6. Unless the request is a dry run, the configured messages are sent to Slack.

Building a report with `dry_run: true` writes the report run to PostgreSQL but does not send anything to Slack.

## Directory structure

```text
bin/
  migrate.php
  release-report.php
  send-release-report.php
migrations/
public/
  index.php
src/
  Client/
  Contracts/
  DTO/
  Infrastructure/
  Model/
  ReleaseDepartments/
  ReleaseSummary/
  Repository/
  Slack/
  Support/
  ReleaseReportWorkflow.php
tests/
  Integration/
  Unit/
composer.json
composer.lock
phpunit.xml
```

## Architectural boundaries

- `Client` contains integrations with Jira and Slack.
- `Contracts` defines interfaces used by the workflow and repositories.
- `Repository` owns PostgreSQL persistence for reports, employees, and epic priorities.
- `ReleaseSummary` maps Jira issues and produces deterministic rule-based summaries.
- `Slack` provides interchangeable incoming-webhook and workflow-trigger transports.
- `Infrastructure` owns the PDO connection and SQL migration runner.
- `public/index.php` exposes the HTTP API while `bin/` provides command-line entrypoints.

## Employee priorities

Jira-to-Slack user mappings are stored in `employees`:

```sql
INSERT INTO employees (jira_user_id, slack_user_id, role, priority)
VALUES ('jira-account-id', 'U12345678', 1, 0);
```

- `role = 1` identifies developers.
- Higher `priority` values are mentioned first.
- Employees with the same priority are ordered by Slack user ID.
- `priority` is limited to the unsigned tinyint-equivalent range `0..255`.

## Epic priorities

Epic ordering is configured through the `epics` table:

```sql
INSERT INTO epics (jira_key, priority)
VALUES ('PROJ-123', 0)
ON CONFLICT (jira_key)
DO UPDATE SET priority = EXCLUDED.priority, updated_at = NOW();
```

- Higher priority values are displayed first.
- Epics not present in the table use priority `1`.
- Epics with the same priority are ordered alphabetically.
- The group without an epic is placed last among groups with the same priority.
- `priority` is limited to `0..255`.

## Tests

Unit tests cover formatting, mapping, environment parsing, and workflow behavior. Integration tests exercise PostgreSQL repositories. Run the complete suite from the repository root:

```bash
docker compose --profile test run --rm tests
```
