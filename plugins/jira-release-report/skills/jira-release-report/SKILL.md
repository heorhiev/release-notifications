---
name: jira-release-report
description: Build and send Jira release reports. Use when the user asks about a Jira release summary, an exact or latest release report, saved release report runs, or sending a release report to Slack.
---

# Jira Release Report

Use the plugin MCP tools instead of constructing HTTP requests manually.

## Workflow

1. Read an exact Jira release name from the user's message when one is supplied.
2. Set `latest_release` to `true` only when the user asks for the latest release or does not name a release.
3. Never provide both `release` and `latest_release: true`.
4. Use `build_jira_release_report` when the user asks to build, generate, inspect, or preview a new report. This stores a report run but does not send anything to Slack.
5. Use `send_jira_release_report` only when the user explicitly asks to send or publish the report to Slack.
6. After building, report the returned release name, report run ID, issue count, and generated summary.
7. After sending, verify that the response contains `sent: true` and report the returned report run ID.

## Release behavior

- `release` must match the Jira fixVersion name exactly, including spaces.
- With `latest_release: true`, the backend selects the latest non-archived Jira release.
- When neither field is supplied, the backend also selects the latest release, but prefer setting `latest_release: true` explicitly.
- Building uses `dry_run: true`: it reads Jira, generates the deterministic rule-based summary, and stores the report run and issue snapshots in PostgreSQL.
- Sending uses `dry_run: false`: it builds and stores a fresh report, sends the configured Slack messages, and marks the run as sent.

## Safety and errors

- Building does not post to Slack, but it does create a saved report run in PostgreSQL.
- Sending changes external state by posting to Slack; never infer permission to send from a request to build or preview.
- If the service is unavailable, use `jira_release_report_health` and tell the user to start the backend or configure `JIRA_RELEASE_REPORT_API_URL`.
- Surface Jira validation and exact-release-name errors without silently changing the requested release.
