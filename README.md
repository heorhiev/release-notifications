# Jira Release Summary Bot

PHP 8.3 сервис для сбора задач Jira по релизу (`fixVersion`), построения общего summary релиза, группировки задач по отделам, отправки результата в Slack и сохранения истории запусков в PostgreSQL.

## Что умеет

- получает задачи Jira по `fixVersion`
- строит summary релиза в двух режимах:
  - `rule` — rule-based summary
  - `openai` — AI summary через OpenAI Responses API
  - `gemini` — AI summary через Gemini API
- делает fallback `openai -> rule` и `gemini -> rule`, если AI summary недоступен
- группирует задачи по отделам:
  - `Support`
  - `Internal Publishers`
  - `Moderation`
  - `Other`
- отправляет итог в Slack через incoming webhook
- сохраняет историю запусков, snapshot задач и runtime-метаданные summary в PostgreSQL
- отдает debug endpoints для Jira, summary и истории запусков

## Архитектура

Сервис не разбит на микросервисы. Это один PHP application service с несколькими слоями:

- HTTP слой: [public/index.php](/Users/mac/dev/adsy/bot/public/index.php)
- orchestration: [src/ReleaseReportService.php](/Users/mac/dev/adsy/bot/src/ReleaseReportService.php)
- Jira integration: [src/JiraClient.php](/Users/mac/dev/adsy/bot/src/JiraClient.php)
- Slack integration: [src/SlackClient.php](/Users/mac/dev/adsy/bot/src/SlackClient.php)
- summary engine: [src/ReleaseSummary](/Users/mac/dev/adsy/bot/src/ReleaseSummary)
- department grouping: [src/ReleaseDepartments](/Users/mac/dev/adsy/bot/src/ReleaseDepartments)
- persistence: [src/Database.php](/Users/mac/dev/adsy/bot/src/Database.php), [src/ReportRunRepository.php](/Users/mac/dev/adsy/bot/src/ReportRunRepository.php)
- migrations: [src/MigrationRunner.php](/Users/mac/dev/adsy/bot/src/MigrationRunner.php), [migrations](/Users/mac/dev/adsy/bot/migrations)
- logging: [src/Logger.php](/Users/mac/dev/adsy/bot/src/Logger.php)

Пайплайн выглядит так:

1. Клиент вызывает `POST /release-report` или CLI-команду.
2. Сервис получает задачи Jira по `project + fixVersion`.
3. Строит общий summary релиза.
4. Раскладывает задачи по отделам.
5. Формирует итоговое сообщение.
6. Отправляет его в Slack, если это не `dry_run`.
7. Сохраняет результат и snapshot задач в PostgreSQL.

## Структура проекта

```text
bin/
  migrate.php
  release-report.php
  send-release-report.php
migrations/
  001_create_report_runs.sql
  002_add_summary_to_report_runs.sql
  003_add_summary_runtime_metadata.sql
public/
  index.php
src/
  ReleaseSummary/
    RuleBased/
    OpenAI/
  ReleaseDepartments/
compose.yaml
Dockerfile
```

## Переменные окружения

Скопируй [.env.example](/Users/mac/dev/adsy/bot/.env.example) в `.env`.

### Обязательные

- `JIRA_BASE_URL`
- `JIRA_EMAIL`
- `JIRA_API_TOKEN`
- `JIRA_PROJECT_KEY`
- `SLACK_WEBHOOK_URL`

### База данных

- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

### Приложение

- `APP_ENV`
- `APP_PORT`

### PgAdmin

- `PGADMIN_DEFAULT_EMAIL`
- `PGADMIN_DEFAULT_PASSWORD`

### OpenAI summary

- `OPENAI_API_KEY`
- `OPENAI_SUMMARY_MODEL`
  По умолчанию: `gpt-5-mini`
- `OPENAI_BASE_URL`
  По умолчанию: `https://api.openai.com/v1`
- `OPENAI_SUMMARY_MAX_ISSUES`
  По умолчанию: `40`
- `OPENAI_SUMMARY_MAX_INPUT_CHARS`
  По умолчанию: `18000`
- `OPENAI_SUMMARY_MAX_SUMMARY_CHARS`
  По умолчанию: `240`
- `OPENAI_SUMMARY_MAX_DESCRIPTION_CHARS`
  По умолчанию: `400`

### Gemini summary

- `GEMINI_API_KEY`
- `GEMINI_SUMMARY_MODEL`
  По умолчанию: `gemini-2.5-flash`
- `GEMINI_BASE_URL`
  По умолчанию: `https://generativelanguage.googleapis.com/v1beta`
- `GEMINI_SUMMARY_MAX_ISSUES`
  По умолчанию: `100`
- `GEMINI_SUMMARY_MAX_INPUT_CHARS`
  По умолчанию: `60000`
- `GEMINI_SUMMARY_MAX_SUMMARY_CHARS`
  По умолчанию: `400`
- `GEMINI_SUMMARY_MAX_DESCRIPTION_CHARS`
  По умолчанию: `700`

### Slack formatting

- `SLACK_TRANSPORT`
  Возможные значения:
  - `incoming_webhook`
  - `workflow_trigger`
  По умолчанию: `incoming_webhook`
- `SLACK_CHANNEL`
- `SLACK_USERNAME`
- `SLACK_ICON_EMOJI`
- `SLACK_WORKFLOW_VARIABLE_SUMMARY`
  Имя переменной workflow trigger, в которую будет передан summary.
  По умолчанию: `summary`
- `SLACK_WORKFLOW_SUMMARY_MAX_CHARS`
  Максимальная длина summary, отправляемого в workflow trigger.
  По умолчанию: `2800`

Если используется `SLACK_TRANSPORT=workflow_trigger`, то `SLACK_WEBHOOK_URL` должен быть workflow trigger URL вида:

```text
https://hooks.slack.com/triggers/...
```

В этом режиме приложение отправляет плоский JSON вида:

```json
{
  "summary": "..."
}
```

где ключ `summary` можно переопределить через `SLACK_WORKFLOW_VARIABLE_SUMMARY`.
Если summary длиннее лимита, заданного в `SLACK_WORKFLOW_SUMMARY_MAX_CHARS`, то приложение автоматически сокращает его только для workflow transport, чтобы избежать ошибок Slack вроде `invalid_blocks`.

## Локальный запуск через Docker Compose

Для разработки используется bind mount, поэтому PHP-код подхватывается без пересборки образа. Пересборка нужна только при изменении [Dockerfile](/Users/mac/dev/adsy/bot/Dockerfile).

Первый запуск:

```bash
docker compose up --build
```

Инициализация схемы БД:

```bash
docker compose exec jira-release-bot php bin/migrate.php
```

Обычный запуск:

```bash
docker compose up
```

Запуск в фоне:

```bash
docker compose up -d
```

Остановка:

```bash
docker compose down
```

## PgAdmin

PgAdmin поднимается вместе с Compose:

- URL: [http://localhost:5050](http://localhost:5050)

Подключение к Postgres внутри PgAdmin:

- Host: `postgres`
- Port: `5432`
- Database: значение `DB_DATABASE`
- Username: значение `DB_USERNAME`
- Password: значение `DB_PASSWORD`

## Summary modes

### `rule`

Rule-based summary без внешнего AI.

Что делает:

- считает общие типы и статусы задач
- выделяет bugfix/stability work
- выделяет user-facing changes
- выделяет platform/internal work
- строит общий grouped summary

Реализация: [src/ReleaseSummary/RuleBased/RuleBasedSummaryGenerator.php](/Users/mac/dev/adsy/bot/src/ReleaseSummary/RuleBased/RuleBasedSummaryGenerator.php)

### `openai`

AI summary через OpenAI Responses API.

Что делает:

- строит общий release summary по всему релизу
- группирует изменения по тематическим блокам
- добавляет `overview`, `groups`, `risks`
- если OpenAI недоступен, автоматически падает в `rule`

Реализация:

- [src/ReleaseSummary/OpenAI/OpenAiSummaryGenerator.php](/Users/mac/dev/adsy/bot/src/ReleaseSummary/OpenAI/OpenAiSummaryGenerator.php)
- [src/ReleaseSummary/OpenAI/OpenAiPromptBuilder.php](/Users/mac/dev/adsy/bot/src/ReleaseSummary/OpenAI/OpenAiPromptBuilder.php)
- prompts:
  - [system.php](/Users/mac/dev/adsy/bot/src/ReleaseSummary/OpenAI/Prompts/system.php)
  - [input.php](/Users/mac/dev/adsy/bot/src/ReleaseSummary/OpenAI/Prompts/input.php)

### `gemini`

AI summary через Gemini `generateContent`.

Что делает:

- строит общий release summary по всему релизу
- группирует изменения по тематическим блокам
- возвращает summary в том же формате, что и `openai`
- если Gemini недоступен, автоматически падает в `rule`

Реализация:

- [src/ReleaseSummary/Gemini/GeminiSummaryGenerator.php](/Users/mac/dev/adsy/bot/src/ReleaseSummary/Gemini/GeminiSummaryGenerator.php)
- [src/ReleaseSummary/Gemini/GeminiPromptBuilder.php](/Users/mac/dev/adsy/bot/src/ReleaseSummary/Gemini/GeminiPromptBuilder.php)
- [src/ReleaseSummary/Gemini/GeminiGenerateContentClient.php](/Users/mac/dev/adsy/bot/src/ReleaseSummary/Gemini/GeminiGenerateContentClient.php)
- prompts:
  - [system.php](/Users/mac/dev/adsy/bot/src/ReleaseSummary/Gemini/Prompts/system.php)
  - [input.php](/Users/mac/dev/adsy/bot/src/ReleaseSummary/Gemini/Prompts/input.php)

## Группировка по отделам

Отдельный модуль группирует задачи по отделам по rule-based эвристикам:

- `Support`
- `Internal Publishers`
- `Moderation`
- `Other`

Реализация:

- [src/ReleaseDepartments/DepartmentGroupingService.php](/Users/mac/dev/adsy/bot/src/ReleaseDepartments/DepartmentGroupingService.php)
- [src/ReleaseDepartments/DTO/DepartmentGroup.php](/Users/mac/dev/adsy/bot/src/ReleaseDepartments/DTO/DepartmentGroup.php)

Группировка не зависит от OpenAI summary. Это отдельный слой, который можно расширять:

- добавлением новых отделов
- выносом словарей в config
- поддержкой мультиклассификации одной задачи в несколько отделов

## HTTP API

### POST `/release-report`

Строит отчет по релизу, опционально отправляет в Slack и сохраняет run в БД.

Пример:

```bash
curl -X POST http://localhost:8080/release-report \
  -H "Content-Type: application/json" \
  -d '{
    "release": "2026 - 2",
    "dry_run": true,
    "summary_mode": "openai",
    "include_department_groups": false
  }'
```

Поля запроса:

- `release` — обязательное имя `fixVersion`
- `include_description` — опционально, по умолчанию `true`
- `dry_run` — опционально, по умолчанию `false`
- `summary_mode` — `rule` или `openai`
- `include_department_groups` — опционально, по умолчанию `false`

Ответ содержит:

- `report_run_id`
- `release`
- `issues_count`
- `summary`
- `preview`

Если `include_department_groups=true`, дополнительно вернется:

- `department_groups`

### GET `/health`

Проверка доступности сервиса.

```bash
curl http://localhost:8080/health
```

### GET `/debug/project-versions`

Показывает версии проекта Jira.

```bash
curl "http://localhost:8080/debug/project-versions"
```

### GET `/debug/jira-search`

Показывает raw search по релизу.

```bash
curl "http://localhost:8080/debug/jira-search?release=2026%20-%202"
```

### GET `/debug/summary`

Показывает только summary без Slack-отправки.

Rule-based:

```bash
curl "http://localhost:8080/debug/summary?release=2026%20-%202&summary_mode=rule"
```

OpenAI:

```bash
curl "http://localhost:8080/debug/summary?release=2026%20-%202&summary_mode=openai"
```

### GET `/debug/report-runs`

История запусков.

```bash
curl "http://localhost:8080/debug/report-runs"
curl "http://localhost:8080/debug/report-runs?limit=10"
```

### GET `/debug/report-runs/{id}`

Детали конкретного запуска.

```bash
curl "http://localhost:8080/debug/report-runs/1"
```

## CLI

Собрать и сохранить report:

```bash
docker compose exec jira-release-bot php bin/release-report.php "2026 - 2"
```

Взять последний релиз автоматически:

```bash
docker compose exec jira-release-bot php bin/release-report.php --latest
```

Rule summary:

```bash
docker compose exec jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=rule
```

OpenAI summary:

```bash
docker compose exec jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=openai
```

Включить группировку по отделам:

```bash
docker compose exec jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=openai --with-department-groups
```

Без описаний задач:

```bash
docker compose exec jira-release-bot php bin/release-report.php "2026 - 2" --no-description
```

Отправить в Slack последний сохраненный summary:

```bash
docker compose exec jira-release-bot php bin/send-release-report.php --latest
```

## База данных

Схема создается миграциями:

- [001_create_report_runs.sql](/Users/mac/dev/adsy/bot/migrations/001_create_report_runs.sql)
- [002_add_summary_to_report_runs.sql](/Users/mac/dev/adsy/bot/migrations/002_add_summary_to_report_runs.sql)
- [003_add_summary_runtime_metadata.sql](/Users/mac/dev/adsy/bot/migrations/003_add_summary_runtime_metadata.sql)

Основные таблицы:

### `report_runs`

Хранит:

- релиз
- количество задач
- dry run или реальная отправка
- summary text
- summary mode
- summary provider
- summary model
- fallback usage
- raw summary output
- preview сообщения
- JQL

### `report_run_issues`

Хранит snapshot задач на момент запуска:

- key
- summary
- status
- assignee
- description
- raw issue JSON

## Логирование

Базовый logger пишет в stderr:

- ошибки Jira API
- ошибки Slack webhook
- ошибки OpenAI API
- fallback OpenAI -> rule

Реализация: [src/Logger.php](/Users/mac/dev/adsy/bot/src/Logger.php)

## Как сервис ходит в Jira

Используется JQL:

```text
project = "PROJ" AND fixVersion = "1.2.3" ORDER BY issuetype ASC, key ASC
```

Запрос идет в Jira Cloud API через endpoint `search/jql`.

## Что сейчас входит в итоговый отчет

Итоговый отчет состоит из трех частей:

1. `Summary`
   Общий summary релиза
2. `Issue Details`
   Полный список задач с описаниями

Опционально:

3. `Department Groups`
   Задачи, сгруппированные по отделам, если включен `include_department_groups`

Формирование сообщения: [src/IssueFormatter.php](/Users/mac/dev/adsy/bot/src/IssueFormatter.php)

## Проверка после настройки

Минимальная последовательность:

```bash
docker compose up -d --build
docker compose exec jira-release-bot php bin/migrate.php
curl "http://localhost:8080/health"
curl "http://localhost:8080/debug/project-versions"
curl "http://localhost:8080/debug/summary?release=2026%20-%202&summary_mode=rule"
```

Если OpenAI ключ задан:

```bash
curl "http://localhost:8080/debug/summary?release=2026%20-%202&summary_mode=openai"
```

## Ограничения текущей версии

- department grouping rule-based и опирается на ключевые слова
- одна задача сейчас попадает только в один отдел
- summary хранится в одной таблице вместе с report run, без отдельной истории summary attempts
- OpenAI summary еще не валидируется вторым проходом against issue list

## Что можно развивать дальше

- вынести department rules в отдельный config
- разрешить одной задаче относиться к нескольким отделам
- добавить отдельные режимы `business` и `technical` для OpenAI summary
- добавить webhook endpoint под Jira Automation
- добавить scheduler и идемпотентные запуски
- добавить retry policy и post-validation для OpenAI summary
