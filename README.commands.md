# Command Reference

Этот файл описывает все консольные команды проекта, их параметры и типовые варианты запуска.

Проект запускается в Docker Compose, поэтому почти все рабочие команды выполняются через контейнер `jira-release-bot`.

## Содержание

- запуск и управление окружением
- CLI-команды приложения
- HTTP debug-команды через `curl`
- типовые сценарии

## Slack transports

Проект поддерживает два режима доставки в Slack:

- `incoming_webhook`
- `workflow_trigger`

Переключение идет через `.env`:

```env
SLACK_TRANSPORT=incoming_webhook
```

или

```env
SLACK_TRANSPORT=workflow_trigger
SLACK_WORKFLOW_VARIABLE_SUMMARY=summary
SLACK_WORKFLOW_SUMMARY_MAX_CHARS=2800
```

Если используется `workflow_trigger`, то `SLACK_WEBHOOK_URL` должен быть URL вида:

```text
https://hooks.slack.com/triggers/...
```

В workflow trigger приложение отправляет JSON по переменным workflow, например:

```json
{
  "summary": "..."
}
```

Если summary длиннее безопасного лимита, приложение автоматически сокращает его только для `workflow_trigger`, чтобы избежать ошибок Slack при построении block-based сообщений внутри workflow.

## 1. Запуск и управление окружением

### `docker compose up --build`

Полный первый запуск проекта с пересборкой образа.

Что делает:

- собирает Docker image приложения
- поднимает контейнеры:
  - `jira-release-bot`
  - `postgres`
  - `pgadmin`

Когда использовать:

- первый запуск проекта
- после изменения [Dockerfile](/Users/mac/dev/adsy/bot/Dockerfile)
- после изменения системных зависимостей контейнера

Пример:

```bash
docker compose up --build
```

### `docker compose up`

Обычный запуск проекта без пересборки образа.

Что делает:

- поднимает контейнеры из уже собранного образа
- использует bind mount для PHP-кода

Когда использовать:

- при ежедневной разработке
- после изменения PHP-кода, когда пересборка не нужна

Пример:

```bash
docker compose up
```

### `docker compose up -d`

Запуск в фоне.

Что делает:

- поднимает все сервисы в detached-режиме

Пример:

```bash
docker compose up -d
```

### `docker compose down`

Остановка и удаление контейнеров compose-проекта.

Что делает:

- останавливает контейнеры
- удаляет их из текущего compose run

Пример:

```bash
docker compose down
```

### `docker compose ps`

Показывает состояние контейнеров проекта.

Что делает:

- выводит список сервисов
- показывает `STATUS`, порты и имена контейнеров

Пример:

```bash
docker compose ps
```

### `docker compose logs --tail=80 jira-release-bot`

Показывает последние логи приложения.

Что делает:

- выводит последние строки логов PHP-сервиса
- полезно для диагностики Jira/OpenAI/Gemini/Slack ошибок

Параметры:

- `--tail=80`
  Ограничивает количество выводимых строк
- `jira-release-bot`
  Имя compose-сервиса

Пример:

```bash
docker compose logs --tail=80 jira-release-bot
```

## 2. CLI-команды приложения

В проекте есть две собственные CLI-команды:

- [bin/migrate.php](/Users/mac/dev/adsy/bot/bin/migrate.php)
- [bin/release-report.php](/Users/mac/dev/adsy/bot/bin/release-report.php)
- [bin/send-release-report.php](/Users/mac/dev/adsy/bot/bin/send-release-report.php)

### 2.1. Миграции

### `docker compose exec jira-release-bot php bin/migrate.php`

Запускает все SQL-миграции, которые еще не были применены.

Что делает:

- читает файлы из [migrations](/Users/mac/dev/adsy/bot/migrations)
- применяет новые миграции к PostgreSQL
- возвращает JSON с результатом

Параметры:

- у команды нет пользовательских CLI-параметров

Пример:

```bash
docker compose exec jira-release-bot php bin/migrate.php
```

Ожидаемый результат:

- JSON в `stdout`
- код выхода `0`, если все успешно
- код выхода `1`, если миграция завершилась ошибкой

Когда использовать:

- после первого запуска проекта
- после добавления новых файлов миграций

### 2.2. Сбор релизного summary

### Базовая команда

```bash
docker compose exec -T jira-release-bot php bin/release-report.php [<release>]
```

Где:

- `<release>`
  Необязательный positional argument
  Это имя релиза Jira, то есть значение `fixVersion`

Пример:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2"
```

Что делает базовая команда:

- получает задачи Jira по релизу
- строит summary
- формирует итоговый релизный отчет
- не отправляет сообщение в Slack
- сохраняет запуск в PostgreSQL
- если релиз не передан, автоматически выбирает последний релиз Jira

### Все параметры `bin/release-report.php`

#### `<release>`

Необязательный параметр.

Что делает:

- задает имя релиза Jira
- используется как `fixVersion`

Пример:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2"
```

#### `--latest`

Опциональный флаг.

Что делает:

- явно говорит команде взять последний релиз Jira
- если релиз не передан вообще, это поведение и так включается автоматически

Когда использовать:

- когда нужно явно зафиксировать сценарий "последний релиз"
- когда не хочется указывать имя релиза вручную

Пример:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php --latest
```

#### `--no-description`

Опциональный флаг.

Что делает:

- исключает `description` задач из подробного отчета
- summary при этом все равно строится

Когда использовать:

- если нужны более короткие детали
- если descriptions слишком шумные

Пример:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --no-description
```

#### `--summary-mode=<mode>`

Опциональный параметр со значением.

Поддерживаемые значения:

- `rule`
- `openai`
- `gemini`

Что делает:

- выбирает движок, который строит общий summary релиза

##### `--summary-mode=rule`

Rule-based summary без AI.

Что делает:

- строит summary по эвристикам
- работает без внешних AI API

Пример:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=rule
```

##### `--summary-mode=openai`

Summary через OpenAI.

Что делает:

- отправляет задачи релиза в OpenAI
- получает grouped summary на русском
- если OpenAI недоступен, автоматически делает fallback на `rule`

Требует:

- `OPENAI_API_KEY`

Пример:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=openai
```

##### `--summary-mode=gemini`

Summary через Gemini.

Что делает:

- отправляет задачи релиза в Gemini
- получает grouped summary на русском
- если Gemini недоступен, автоматически делает fallback на `rule`

Требует:

- `GEMINI_API_KEY`

Пример:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=gemini
```

#### `--with-department-groups`

Опциональный флаг.

Что делает:

- дополнительно включает rule-based группировку задач по отделам

Текущие группы:

- `Support`
- `Internal Publishers`
- `Moderation`
- `Other`

Важно:

- по умолчанию группировка по отделам выключена
- summary без этого флага строится без department grouping

Пример:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=openai --with-department-groups
```

#### `--summary-only`

Опциональный флаг.

Что делает:

- выводит в `stdout` только текст summary
- не печатает JSON-обертку результата

Когда использовать:

- когда нужен чистый summary без служебных полей
- для быстрого сравнения `rule`, `openai`, `gemini`

Пример:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=openai --summary-only
```

### Возможные комбинации параметров

#### Rule summary

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=rule --summary-only
```

#### OpenAI summary

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=openai --summary-only
```

#### Gemini summary

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=gemini --summary-only
```

#### Summary с department grouping

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=openai --with-department-groups
```

#### Summary по последнему релизу

```bash
docker compose exec -T jira-release-bot php bin/release-report.php --latest --summary-mode=openai --summary-only
```

### 2.3. Отправка сохраненного summary в Slack

### Базовая команда

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php
```

Что делает:

- берет уже сохраненный `summary_text` из PostgreSQL
- формирует короткое Slack-сообщение только с summary
- отправляет это сообщение в Slack
- обновляет у соответствующего `report_runs` поле `slack_sent = true`

Важно:

- эта команда не собирает задачи из Jira
- эта команда не строит новый summary
- она работает только с уже сохраненными run в БД

### Параметры `bin/send-release-report.php`

#### Без параметров

Что делает:

- отправляет в Slack summary последнего сохраненного run

Пример:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php
```

#### `<release>`

Опциональный positional argument.

Что делает:

- ищет последний сохраненный run для указанного релиза
- отправляет его summary в Slack

Пример:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php "2026 - 2"
```

#### `--latest`

Опциональный флаг.

Что делает:

- явно указывает взять последний сохраненный run из БД

Пример:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php --latest
```

#### `--run-id=<id>`

Опциональный параметр.

Что делает:

- отправляет в Slack summary конкретного сохраненного run по его `id`

Пример:

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php --run-id=29
```

Ограничения:

- нельзя одновременно передавать `--run-id=<id>` и `<release>`
- нельзя одновременно передавать `--run-id=<id>` и `--latest`
- нельзя одновременно передавать `<release>` и `--latest`

### Поведение по умолчанию

Если параметры не переданы:

- `summary_mode = rule`
- `latest_release = true`, если релиз не указан
- `include_description = true`
- `include_department_groups = false`
- `summary_only = false`

## 3. HTTP debug-команды через `curl`

Эти команды не являются PHP CLI-скриптами, но используются из консоли для проверки и отладки сервиса.

### `GET /health`

Проверка, что сервис жив.

```bash
curl "http://localhost:8080/health"
```

Что делает:

- возвращает `{"status":"ok"}`

### `GET /debug/project-versions`

Возвращает список версий Jira-проекта.

```bash
curl "http://localhost:8080/debug/project-versions"
```

Что делает:

- показывает все версии проекта Jira
- полезно для проверки точного имени `fixVersion`

Параметры:

- нет

### `GET /debug/jira-search?release=<release>`

Показывает raw поиск задач Jira по релизу.

```bash
curl "http://localhost:8080/debug/jira-search?release=2026%20-%202"
```

Параметры:

- `release`
  Обязательный query parameter
  Имя релиза Jira

Что делает:

- выполняет поиск задач в Jira
- возвращает `issues` и `jql`

### `GET /debug/summary?release=<release>&summary_mode=<mode>`

Возвращает только summary по релизу.

```bash
curl "http://localhost:8080/debug/summary?release=2026%20-%202&summary_mode=rule"
curl "http://localhost:8080/debug/summary?release=2026%20-%202&summary_mode=openai"
curl "http://localhost:8080/debug/summary?release=2026%20-%202&summary_mode=gemini"
```

Параметры:

- `release`
  Обязательный query parameter
  Имя релиза Jira
- `summary_mode`
  Опциональный query parameter
  Возможные значения:
  - `rule`
  - `openai`
  - `gemini`

Что делает:

- получает задачи Jira по релизу
- строит summary выбранным движком
- возвращает компактный JSON:
  - `release`
  - `summary_mode`
  - `issues_count`
  - `summary.mode`
  - `summary.text`

### `GET /debug/report-runs`

Показывает историю запусков отчета.

```bash
curl "http://localhost:8080/debug/report-runs"
curl "http://localhost:8080/debug/report-runs?limit=10"
```

Параметры:

- `limit`
  Опциональный query parameter
  Ограничивает количество возвращаемых запусков

### `GET /debug/report-runs/<id>`

Показывает детали конкретного запуска.

```bash
curl "http://localhost:8080/debug/report-runs/1"
```

Параметры:

- `<id>`
  Идентификатор запуска из таблицы `report_runs`

Что делает:

- возвращает сохраненный run
- возвращает snapshot задач Jira для этого запуска

## 4. Типовые сценарии

### Первый запуск проекта

```bash
docker compose up --build
docker compose exec jira-release-bot php bin/migrate.php
curl "http://localhost:8080/health"
```

### Проверить, что Jira отдает задачи релиза

```bash
curl "http://localhost:8080/debug/project-versions"
curl "http://localhost:8080/debug/jira-search?release=2026%20-%202"
```

### Быстро получить только summary без JSON

Rule:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=rule --summary-only
```

OpenAI:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=openai --summary-only
```

Gemini:

```bash
docker compose exec -T jira-release-bot php bin/release-report.php "2026 - 2" --summary-mode=gemini --summary-only
```

### Взять последний релиз автоматически

```bash
docker compose exec -T jira-release-bot php bin/release-report.php --latest --summary-mode=rule --summary-only
```

### Отправить последний сохраненный summary в Slack

```bash
docker compose exec -T jira-release-bot php bin/send-release-report.php --latest
```

## 5. Коды возврата CLI-команд

Для CLI-скриптов проекта:

- `0`
  Команда завершилась успешно
- `1`
  Команда завершилась с ошибкой

## 6. Важные замечания

- `bin/release-report.php` никогда не отправляет сообщение в Slack, он только собирает summary и сохраняет запуск в PostgreSQL.
- `bin/send-release-report.php` не ходит в Jira и не строит новый summary, а отправляет в Slack уже сохраненный `summary_text`.
- `--summary-only` влияет только на формат вывода CLI и не меняет сам pipeline.
- `summary_mode=openai` и `summary_mode=gemini` могут автоматически перейти в `rule`, если внешний AI недоступен.
- department grouping не включается по умолчанию.
- `migrate.php` не принимает параметров и просто применяет новые миграции.
