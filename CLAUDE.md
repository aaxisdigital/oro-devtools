# CLAUDE.md — AaxisDevToolsBundle

Guidance for working in this bundle. Read alongside `README.md` (user-facing) and the CommonBundle
`CLAUDE.md` (shared infrastructure).

## What this bundle is

The operational/developer toolbox: **Runtime Config, Filesystem Browser, Bucket Browser, Database
Viewer, Elastic Viewer, Redis Viewer, MongoDB Viewer, Network Tools**. It was split out of
`AaxisToolsBundle`. It depends only on `AaxisCommonBundle` and is independent of `AaxisToolsBundle`.

## Identity / naming conventions (get these right)

| Thing | Value | Where |
|-------|-------|-------|
| PHP namespace | `Aaxis\Bundle\DevToolsBundle` | all classes |
| Bundle class | `AaxisDevToolsBundle` | auto-registered via `Resources/config/oro/bundles.yml` |
| Config alias | `aaxis_devtools` | `DependencyInjection/Configuration.php` tree + setting keys `aaxis_devtools.*` |
| Route prefix | `/aaxis/devtools` | `Resources/config/oro/routing.yml` |
| Route names | `aaxis_devtools_*` | controller `#[Route(name:)]` |
| Twig namespace | `@AaxisDevTools/...` | `#[Template]` + `path()` in twig |
| Asset namespace | `aaxisdevtools` | `bundles/aaxisdevtools/...`, JS module ids `aaxisdevtools/js/...` |
| Translation root | `aaxis.devtools.*` | `Resources/translations/{messages,jsmessages}.en.yml` |
| ACL capabilities | `aaxis_devtools` (access all tools) + `aaxis_devtools_write` (destructive bucket writes; **not** granted by default) | `Resources/config/oro/acls.yml` |
| Service id prefix | `aaxis_devtools.*` | `Resources/config/services.yml` |

⚠️ **Alias gotcha (important).** Symfony derives an extension alias by underscoring the class name, so
`AaxisDevToolsExtension` would become **`aaxis_dev_tools`** — which would NOT match the
`aaxis_devtools.*` setting keys. To force the intended alias, this bundle overrides:
- `AaxisDevToolsExtension::getAlias()` → returns `'aaxis_devtools'`
- `AaxisDevToolsBundle::getContainerExtension()` → returns the extension directly (the default would
  reject the non-underscored alias).

Keep both overrides if you touch those files. The asset namespace (`aaxisdevtools`) is the bundle
name lowercased with `Bundle` stripped — that one is fine as-is.

## Layout

- `Controller/DevToolsPageController.php` — the **page** actions for the 7 page-tools (renders the
  twig + passes System Configuration options). Per-tool **AJAX** endpoints live in dedicated
  controllers (`DatabaseViewerController`, `ElasticViewerController`, `RedisViewerController`,
  `MongoViewerController`, `FilesystemBrowserController`, `BucketBrowserController`,
  `NetworkToolsController`). `RuntimeConfigController` renders its own page (no AJAX).
- `Storage/` — shared filesystem+bucket engine (`StorageBrowserInterface`,
  `FilesystemStorageBrowser`, `BucketStorageBrowser`, `S3Client`); `Controller/AbstractStorageBrowserController`
  holds list/preview/raw/download; both browser controllers only declare routes + pick the storage.
  Front end: single `storage-browser-component.ts` used by both pages.
- `Database/`, `Elastic/`, `Redis/`, `Mongo/`, `Filesystem/`, `Config/`, `Network/` — the per-tool
  service logic (inspectors / executors).
- `Connection/*ConnectionTester.php` — per-tool "Test it" checks (see below).
- `Manager/` + `Entity/` (+ `Entity/Repository/`) — `QueryHistory`, `SavedQuery`,
  `NetworkTestHistory` and their managers. API Collection entities stayed in `AaxisToolsBundle`.
- `Command/CleanupHistoryCommand.php` — `aaxis:devtools:history:cleanup` cron.

## Connection-test ("Test it") pattern

The controller, route, JS and registry are in **CommonBundle**. To add/adjust a tool's check:
1. Add `Connection/<Tool>ConnectionTester` implementing
   `Aaxis\Bundle\CommonBundle\Connection\ConnectionTesterInterface` (`getTool()` + `test($overrides)`,
   returning `{success, message, details}`; mask any password).
2. Register + tag it in `services.yml`: `{ name: aaxis_common.connection_tester, tool: <tool_key> }`.
3. In `system_configuration.yml`, the `*_test` field uses
   `data-page-component-module: 'aaxiscommon/js/app/components/connection-test-component'` and
   `data-page-component-options: '{"tool":"<tool_key>"}'`.
   No new route/controller/JS is needed.

## Security model & hardening (don't regress)

Access is gated by two **action** capabilities in `acls.yml`:
- **`aaxis_devtools`** — class-bound to every controller; grants access to all tool pages + AJAX
  endpoints (read/browse). Granted to Administrator by
  `Migrations/Data/ORM/LoadAaxisDevToolsAdminPermissions`.
- **`aaxis_devtools_write`** — gates the *destructive* Bucket Browser actions (create folder,
  upload, delete) via method-level `#[AclAncestor('aaxis_devtools_write')]`. It is **not** bound to
  a controller and **not** granted by any migration, so bucket writes are denied for everyone (incl.
  admins) until someone assigns it to a role (System → Roles → Capabilities). Re-enable from the UI,
  not code.

These hardening measures are deliberate — keep them when editing the relevant files:
- **Runtime Config** (`Config/RuntimeConfigInspector`) **always** redacts: secret-looking keys and
  secret-looking values (PEM/SSH keys, JWTs) are masked, and URL/DSN credentials are stripped. There
  is no "show raw" toggle — don't reintroduce one.
- **Storage `raw` route** (`Controller/AbstractStorageBrowserController::doStream`) sends
  `X-Content-Type-Options: nosniff`, serves only `image/*` + `application/pdf` inline (everything
  else is forced to `attachment`/`octet-stream`), and adds `Content-Security-Policy: sandbox` for
  SVG. Don't widen the inline allow-list.
- **Network Tools** (`Network/NetworkToolExecutor`) and **`Storage/S3Client`** reject targets that
  resolve to link-local / cloud-metadata addresses (`169.254.0.0/16`, IPv6 `fe80::/10`,
  `fd00:ec2::254`, `metadata.google.internal`) via `assertTargetAllowed()` /
  `assertEndpointAllowed()`. RFC1918 / loopback stay reachable on purpose (diagnosing internal
  services). The curl tool is pinned to `CURLPROTO_HTTP|HTTPS`.
- **`Database/ResultExporter`** neutralizes spreadsheet formula injection (`=`/`+`/`-`/`@`/tab/CR).
- **Mongo Viewer** error responses run through `MongoInspector::redactDsnCredentials()`.

Still open (product decision, not yet done): a full SSRF egress policy beyond link-local,
unrestricted-filesystem gating, a low-privilege DB role for the SQL viewer, and a MongoDB `$where`
block.

## History cleanup pattern

`CleanupHistoryCommand` reads `aaxis_devtools.<tool>_history_retention_days` and calls the shared
`Aaxis\Bundle\CommonBundle\Command\HistoryRetentionPurger::purge(EntityClass::class, $days)`. The
entity needs a `runAt` field. Add a `purge(...)` line per history entity.

## Adding a new tool (checklist)

1. **Controller**: page action in `DevToolsPageController` (or a dedicated controller) + an AJAX
   controller if it has endpoints. Routes via `#[Route(name: 'aaxis_devtools_<tool>...')]`.
2. **Service(s)**: define in `services.yml` with id `aaxis_devtools.<tool>_*`; wire the controller's
   subscribed services in `controllers.yml` (`container.service_subscriber`).
3. **Config**: add settings to `DependencyInjection/Configuration.php` AND fields/group/tree to
   `system_configuration.yml` (keys `aaxis_devtools.<tool>_*`).
4. **Menu**: `navigation.yml` item + title under `aaxis_devtools_group`.
5. **Feature toggle**: `features.yml` entry (`toggle: aaxis_devtools.<tool>_enabled`, list its routes
   + navigation item). Disabling 404s the routes and hides the menu item.
6. **ACL**: add the controller class to the `aaxis_devtools` binding in `acls.yml`. Gate any
   *destructive/mutating* action with method-level `#[AclAncestor('aaxis_devtools_write')]` (see
   "Security model & hardening" above).
7. **Assets**: add SCSS to `assets.yml`; add the JS component to `jsmodules.yml` (`aaxisdevtools/...`).
8. **Templates**: `Resources/views/Tools/<tool>.html.twig` (+ `_<tool>Help.html.twig` importing
   `@AaxisCommon/Tools/help.html.twig`).
9. **Translations**: `aaxis.devtools.*` in `messages.en.yml` (+ JS strings in `jsmessages.en.yml`).
10. **Migration** (if it persists data): add a table in
    `Migrations/Schema/AaxisDevToolsBundleInstaller` (consolidated install — this is pre-prod, no
    upgrade migrations).

## Front end / TypeScript

`Resources/js-src/*.ts` compile to `Resources/public/js/*.js` via
`php bin/console aaxis:devtools:typescript:compile` (also runs on `oro:assets:build`). **Both `.ts`
sources and emitted `.js` are committed.** `tsconfig.json` extends CommonBundle's
`tsconfig.base.json`. Shared grid/dialog widgets come from `aaxiscommon/js/app/widgets/*`.

## Verify after changes

```bash
php bin/console cache:clear --no-interaction
php bin/console debug:router | grep aaxis_devtools
php bin/console debug:container --tag=aaxis_common.connection_tester   # testers wired?
php bin/console aaxis:devtools:typescript:compile
```
`lint:container` currently fails on an unrelated pre-existing Oro alias issue
(`UserAuthorizationCheckerInterface`); use `cache:clear` as the compile check instead.
