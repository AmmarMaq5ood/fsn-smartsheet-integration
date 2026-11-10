# FSN LP → Smartsheet integration (PHP)

Replaces the Make find-or-create scenarios. Make is kept only as a thin proxy that holds the
Smartsheet credential (one scenario, never edited). All rules live in `config.php`.

## Flow

LP workflow webhook → `lp.php?sheet=<key>` → rules + find-or-create → Make proxy → Smartsheet API

## Files

| File | Purpose |
|---|---|
| `lp.php` | Webhook entry point. `?sheet=schools` or `?sheet=educators`. Requires header `x-webhook-key`. |
| `health.php` | `?key=…` config check; `&deep=1` also verifies every mapped column exists on the sheet. |
| `config.php` | Secrets, dry-run switch, sheet definitions and column mappings (copy from `config.sample.php`). |
| `lib/Sync.php` | Find-or-create engine driven by the sheet config. |
| `lib/Payload.php` | Path access into the LP payload, templates, staff gate, school-name fallback. |
| `lib/Smartsheet.php` | Smartsheet client. Transport `proxy` (Make) or `direct` (bearer token). Resolves column titles to IDs at runtime and caches them. |
| `logs/` | JSON-lines logs, one file per month, written as `.php` with a guard so nginx never serves them. |
| `cache/` | Column and sheet ID caches. Safe to delete. |
| `tests/run.php` | Offline tests against the saved LP payloads in `../runs`. `php tests/run.php` |

## Deploy

1. Copy `config.sample.php` to `config.php`, set `inbound_key`, the proxy URL and secret. Keep `dry_run => true`.
2. Upload everything except `tests/`, `check.php` and `config.sample.php` to `/fsn-smartsheet` on the server.
3. Open `https://webhook.launchpadinnovations.ai/fsn-smartsheet/health.php?key=<inbound_key>&deep=1`.
   `missing_columns` must be empty for every sheet.
4. In LP, point the workflow webhook at `https://webhook.launchpadinnovations.ai/fsn-smartsheet/lp.php?sheet=<key>`
   and add header `x-webhook-key: <inbound_key>`.
5. Send a test event, read `logs/app-YYYY-MM.log.php` over FTP (the `sync_*` lines show what would be written).
6. Set `dry_run => false`, upload `config.php` again.

## Auto-deploy on push (GitHub Actions)

`.github/workflows/deploy.yml` lints every PHP file and runs `server/tests/run.php` on each push
and pull request. On `main` it then rsyncs `server/` to the VPS over SSH as the site user
`launchpadinnovations-webhook`, excluding `config.php`, `cache/`, `data/`, `logs/` and `tests/`,
and finally calls `health.php?deep=1`. A push to `main` is a deploy.

One-time setup:

1. The deploy key pair lives on Ammar's laptop at `~/.ssh/fsn-smartsheet_deploy`. The public half
   must be in `/home/launchpadinnovations-webhook/.ssh/authorized_keys` on the VPS (root, once).
2. Repo secrets: `DEPLOY_HOST` = `2.24.203.63`, `DEPLOY_USER` = `launchpadinnovations-webhook`,
   `DEPLOY_KEY` = the private key file's contents, `HEALTH_KEY` = the `inbound_key` from config.php.
3. `config.php` is written on the server by hand (FileZilla) and is never in git. When
   `config.sample.php` gains a key, add it to the server's `config.php` too.

Day to day: commit on `main`, push, watch the Actions tab (about a minute), then open the
dashboard. Roll back by reverting the commit and pushing again.

Manual sync from the laptop, if GitHub is unavailable:

```
rsync -az --delete --exclude config.php --exclude cache/ --exclude data/ --exclude logs/ --exclude tests/   -e "ssh -i ~/.ssh/fsn-smartsheet_deploy" server/   launchpadinnovations-webhook@2.24.203.63:/home/launchpadinnovations-webhook/htdocs/webhook.launchpadinnovations.ai/fsn-smartsheet/
```

## Make proxy contract

PHP posts JSON to the proxy webhook: `{secret, method, path, query, body}` and expects the raw
Smartsheet API response back, or `{statusCode, body}`. The proxy is: Custom webhook → filter on
`secret` → Smartsheet "Make an API Call" → Webhook response.

## Document routing (schools)

LP sends every document through the same field (`LP SOW Sent` / `SOW Document URL`); the document
name decides where it lands:

| document name contains                              | result                                   |
|-----------------------------------------------------|------------------------------------------|
| contractor / educator / independent / staff / program manager | skipped for schools (staff sheet handles it) |
| work order                                          | written to **LP WO Sent**                |
| anything else                                       | written to **LP SOW Sent**               |

LP fires "Documents & Contracts Signed" once per signer, and Rich countersigns every SOW, so his
contact arrives as a second event. `reject_emails` on both sheets drops those (reason
"internal signer"); without it the staff sheet got a nameless row holding his copy of the link.

A Work Order event never clears the SOW link (empty values are not written on update), so a row ends
up with both links. Mapping guards are `if_doc` / `unless_doc` on any array spec in `config.php`.

## Dashboard and event store

`dashboard.php` shows every event the integration received: who, which document, what Sync did
(created / updated / skipped with reason / error), the Smartsheet row, every log line of that
request, and the full LP payload. Open it once with `dashboard.php?key=<dashboard_key>`; a cookie
keeps you signed in for 30 days.

- Filter by sheet, result, source and date; free-text search over name, school, document, email,
  ids and row numbers; **Export CSV** of the current filter.
- **View** an event for its payload, log lines and an "Open Smartsheet row" link.
- **Replay** re-runs a stored event through the normal pipeline (same find-or-create rules), for
  events that failed while Smartsheet was unreachable or a column was locked.
- **Import log history** loads events from the JSON-lines logs written before the store existed
  (idempotent: already-imported requests are skipped).
- **Check Smartsheet** does the same connection test as `health.php?deep=1`.

Storage is SQLite (`lib/Store.php`) in `data/` under an unguessable file name; `.htaccess` and an
empty `index.html` sit beside it. Never upload or download `data/`. If `pdo_sqlite` is missing the
store switches itself off, webhooks keep working, and `health.php` reports the reason.

## Slack notifications

Each sheet can carry `notify` rules (see `config.sample.php`). After a successful create or update,
a rule whose `when_doc` keywords match the document name posts to its Slack incoming webhook.
`on` limits it to `sent`, `signed` or both; "signed" means the LP workflow that fired has "Signed"
in its name (Staff - Doc Signed -> Slack). A rule with an empty `slack_webhook` is off. Slack
failures are logged (`notify_failed`) and shown in the dashboard but never fail the LP webhook.

Current rule: Program Manager SOWs (sent and signed) -> private channel #sow-program-managers,
asked for by Rich on Nov 5 2026. The webhook URL lives only in the server's config.php.

## Concurrency

`lp.php` takes an exclusive file lock per `sheet + contact_id` (`cache/locks/`) for the duration of
the sync. Parallel events for the same contact are serialised, so the second one finds the row the
first one created instead of creating a duplicate.

## Switching to a direct Smartsheet token later

Set `smartsheet.transport` to `direct` and fill `direct.token`. Nothing else changes.
