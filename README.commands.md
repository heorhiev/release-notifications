# Command Reference

Проект запускается в Docker Compose. Рабочие команды выполняются через сервис `jira-release-bot`.

## Slack Transport

Основной режим:

```env
SLACK_TRANSPORT=incoming_webhook
```

Также в коде есть `workflow_trigger`, если `SLACK_WEBHOOK_URL` указывает на Slack workflow trigger URL.

Release-check текст задается переменной:

```env
SLACK_DEVELOPERS_CHECK_TEXT="---\n*Dear Team*, проверьте все ли ваши задачи попали в релиз {release}."
```

Reporters-check текст задается переменной:

```env
SLACK_REPORTERS_CHECK_TEXT='Уважаемые постановщики задач!)\n\nПожалуйста, проверьте <{url_user_tasks}|свои задачи>:\n- если всё в порядке - переведите задачу в статус "Выполнено";\n- если есть замечания - верните задачу на доработку\n\nПосле проверки задач, пожалуйста, отпишитесь под этим сообщением\n\nСпасибо!)'
```

В `SLACK_DEVELOPERS_CHECK_TEXT` и `SLACK_REPORTERS_CHECK_TEXT` поддерживаются плейсхолдеры:

- `{release}` — имя текущего релиза.
- `{next_release}` — следующий после текущего Jira release по сортировке имени.
- `{url_user_tasks}` — ссылка на Jira list с задачами текущего пользователя для текущего релиза.
- `{developers}` — Slack-упоминания сотрудников с ролью `developer`.
- `{reporters}` — Slack-упоминания авторов задач из релиза.

`{developers}` используется в `SLACK_DEVELOPERS_CHECK_TEXT`. Список берется из таблицы `employees`, где `role = 1`.

`{reporters}` используется в `SLACK_REPORTERS_CHECK_TEXT`. Связка хранится в таблице `employees`: `jira_user_id` — Jira `reporter.accountId`, `slack_user_id` — Slack user ID без `<@...>`.

```sql
INSERT INTO employees (jira_user_id, slack_user_id, role)
VALUES ('jira-account-id', 'U12345678', 1);
```

## Docker

### Первый запуск

```bash
docker compose up --build
```

### Запуск в фоне

```bash
docker compose up -d
```

### Состояние контейнеров

```bash
docker compose ps
```

### Логи приложения

```bash
docker compose logs --tail=80 jira-release-bot
```

### Остановка

```bash
docker compose down
```

## Миграции

```bash
docker compose exec jira-release-bot php bin/migrate.php
```

Команда применяет новые SQL-миграции из [migrations](/Users/mac/dev/adsy/bot/migrations).

## Сбор Отчета

### Конкретный релиз

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 6"
```

Что делает:

- получает задачи Jira по `fixVersion`
- строит rule-based summary
- сохраняет run в PostgreSQL
- сохраняет snapshot задач в `report_run_issues`
- не отправляет сообщение в Slack

### Последний релиз Jira

```bash
docker compose exec -T jira-release-bot php bin/release-report.php
```

или явно:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php --latest
```

Других режимов summary нет. CLI не поддерживает `--summary-mode`, `--summary-only`, `--with-department-groups` и `--no-description`.

## Отправка Сохраненного Summary

### Последний unsent run

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php
```

### Последний unsent run по релизу

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php "2026 - 6"
```

### Конкретный run

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php --run-id=12
```

### Preview без отправки

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php "2026 - 6" --preview
```

Что отправляет:

- Slack-сообщение с release summary
- developers-check сообщение из `SLACK_DEVELOPERS_CHECK_TEXT`, если оно задано
- reporters-check сообщение из `SLACK_REPORTERS_CHECK_TEXT`, если оно задано

`send-release-report.php` не ходит в Jira и не строит новый summary. Он отправляет уже сохраненный `summary_text`.

## HTTP API

### `POST /release-report`

```bash
curl -X POST http://localhost:8080/release-report \
  -H "Content-Type: application/json" \
  -d '{
    "release": "2026 - 6",
    "dry_run": true
  }'
```

Поля:

- `release` — имя Jira release / `fixVersion`
- `latest_release` — взять последний релиз Jira
- `dry_run` — если `true`, Slack-отправки не будет

Запрос без `release` использует последний релиз Jira.

### `GET /debug/project-versions`

```bash
curl "http://localhost:8080/debug/project-versions"
```

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

## Типовые Сценарии

### Собрать и проверить отчет без Slack

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 6"
```

### Отправить сохраненный отчет

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php "2026 - 6"
```

### Отправить весь pipeline через HTTP

```bash
curl -X POST http://localhost:8080/release-report \
  -H "Content-Type: application/json" \
  -d '{"release":"2026 - 6","dry_run":false}'
```

## Важно

- Summary всегда rule-based.
- AI summary не поддерживается.
- Department grouping не поддерживается.
- `Issue Details` в Slack не отправляется.
- Snapshot задач хранится в PostgreSQL.
