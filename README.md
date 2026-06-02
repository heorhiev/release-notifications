# Jira Release Summary Bot

PHP 8.3 сервис для сбора задач Jira по релизу (`fixVersion`), построения rule-based summary, отправки summary в Slack и сохранения истории запусков в PostgreSQL.

## Что Умеет

- получает задачи Jira по `fixVersion`
- строит только rule-based summary без AI
- отправляет в Slack только summary релиза
- дополнительно отправляет release-check сообщение из `SLACK_RELEASE_CHECK_TEXT`
- дополнительно отправляет task-check сообщение из `SLACK_TASK_CHECK_TEXT`
- сохраняет run, summary и snapshot задач в PostgreSQL
- отдает debug endpoints для Jira, summary и истории запусков

Сервис больше не формирует и не отправляет блок `Issue Details` в Slack. Полный snapshot задач сохраняется только в базе.

## Архитектура

- HTTP слой: [public/index.php](/Users/mac/dev/adsy/bot/public/index.php)
- orchestration: [src/ReleaseReportService.php](/Users/mac/dev/adsy/bot/src/ReleaseReportService.php)
- Jira integration: [src/JiraClient.php](/Users/mac/dev/adsy/bot/src/JiraClient.php)
- Slack integration: [src/SlackClient.php](/Users/mac/dev/adsy/bot/src/SlackClient.php)
- summary engine: [src/ReleaseSummary](/Users/mac/dev/adsy/bot/src/ReleaseSummary)
- persistence: [src/Database.php](/Users/mac/dev/adsy/bot/src/Database.php), [src/ReportRunRepository.php](/Users/mac/dev/adsy/bot/src/ReportRunRepository.php)
- migrations: [src/MigrationRunner.php](/Users/mac/dev/adsy/bot/src/MigrationRunner.php), [migrations](/Users/mac/dev/adsy/bot/migrations)

Пайплайн:

1. Клиент вызывает `POST /release-report` или `bin/release-report.php`.
2. Сервис получает задачи Jira по `project + fixVersion`.
3. Строит rule-based summary.
4. Формирует Slack-сообщение только с summary.
5. Если это не `dry_run`, отправляет summary и release-check сообщение в Slack.
6. Сохраняет run и snapshot задач в PostgreSQL.

## Структура

```text
bin/
  migrate.php
  release-report.php
  send-release-report.php
migrations/
public/
  index.php
src/
  ReleaseSummary/
    RuleBased/
compose.yaml
Dockerfile
```

## Переменные Окружения

Скопируй [.env.example](/Users/mac/dev/adsy/bot/.env.example) в `.env`.

Обязательные:

- `JIRA_BASE_URL`
- `JIRA_EMAIL`
- `JIRA_API_TOKEN`
- `JIRA_PROJECT_KEY`
- `SLACK_WEBHOOK_URL`

Приложение и БД:

- `APP_ENV`
- `APP_PORT`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

Slack:

- `SLACK_TRANSPORT`
  Возможные значения: `incoming_webhook`, `workflow_trigger`
- `SLACK_CHANNEL`
- `SLACK_USERNAME`
- `SLACK_ICON_EMOJI`
- `SLACK_MENTION_USER_IDS`
- `SLACK_RELEASE_CHECK_TEXT`
- `SLACK_TASK_CHECK_TEXT`

`SLACK_RELEASE_CHECK_TEXT` и `SLACK_TASK_CHECK_TEXT` поддерживают плейсхолдеры:

- `{release}` — имя текущего релиза.
- `{next_release}` — следующий после текущего Jira release по сортировке имени.
- `{url_user_tasks}` — ссылка на Jira list с задачами текущего пользователя для текущего релиза.

Пример Slack-ссылки:

```text
<https://example.com|Текст ссылки>
```

## Docker

Первый запуск:

```bash
docker compose up --build
```

Запуск в фоне:

```bash
docker compose up -d
```

Миграции:

```bash
docker compose exec jira-release-bot php bin/migrate.php
```

Остановка:

```bash
docker compose down
```

## CLI

Собрать отчет без отправки в Slack и сохранить run:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 6"
```

Если релиз не передан, команда автоматически берет последний релиз Jira:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php
```

Отправить последний сохраненный unsent summary:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php
```

Отправить последний unsent summary для конкретного релиза:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php "2026 - 6"
```

Preview сохраненного сообщения без отправки:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php "2026 - 6" --preview
```

## HTTP API

### `POST /release-report`

Строит rule-based отчет, сохраняет run и опционально отправляет summary в Slack.

```bash
curl -X POST http://localhost:8080/release-report \
  -H "Content-Type: application/json" \
  -d '{
    "release": "2026 - 6",
    "dry_run": true
  }'
```

Поля:

- `release` — имя `fixVersion`; если не задано, используется последний релиз Jira
- `latest_release` — явно взять последний релиз Jira
- `dry_run` — если `true`, Slack-отправки не будет

### `GET /debug/jira-search`

```bash
curl "http://localhost:8080/debug/jira-search?release=2026%20-%206"
```

### `GET /debug/summary`

```bash
curl "http://localhost:8080/debug/summary?release=2026%20-%206"
```

### `GET /debug/report-runs`

```bash
curl "http://localhost:8080/debug/report-runs?limit=20"
```

## Slack Формат

В Slack отправляется:

1. `Release: <name>` и rule-based summary.
2. Отдельное release-check сообщение из `SLACK_RELEASE_CHECK_TEXT`, если оно задано.
3. Отдельное task-check сообщение из `SLACK_TASK_CHECK_TEXT`, если оно задано.

`Issue Details` в Slack не отправляется.
