# Aaxis Dev Tools Bundle

A back-office (admin) toolbox for OroCommerce that bundles several operational / developer tools
under a single **"Dev Tools"** application-menu sub-group. Every tool can be enabled/disabled and
configured from **System Configuration → General Setup → Aaxis Dev Tools**.

- Namespace: `Aaxis\Bundle\DevToolsBundle`
- Bundle class: `AaxisDevToolsBundle` (**not** auto-registered — see [Installation](#installation))
- Back-office route prefix: `/admin/aaxis/devtools`
- Config alias: `aaxis_devtools`

> **Related Aaxis bundles**
> - **`AaxisCommonBundle`** — shared base bundle (TypeScript build pipeline, the top-level "Aaxis"
>   menu group and its icon, the shared grid widgets, and the shared **connection-test** registry /
>   endpoint / JS used by the tools' "Test it" buttons). Required by this bundle.
> - **`AaxisToolsBundle`** — the lighter toolbox (Queue Monitor, API Collection, Base64). It was
>   split apart from this bundle; the two are independent of each other (both require CommonBundle).
> - All Aaxis feature bundles render under the same top-level **"Aaxis"** application-menu group
>   (`aaxis_tab`, provided by CommonBundle).

---

## Tools

| Tool | Route | Summary |
|------|-------|---------|
| Runtime Config | `aaxis_devtools_runtime_config` | Read-only view of env vars / container params / PHP runtime (secrets always masked) |
| Filesystem Browser | `aaxis_devtools_filesystem_browser` | Read-only server filesystem browser with previews |
| Bucket Browser | `aaxis_devtools_bucket_browser` | S3-compatible object-storage browser (shares the engine with Filesystem Browser) |
| Database Viewer | `aaxis_devtools_database_viewer` | Read-only SQL IDE (PostgreSQL) |
| Elastic Viewer | `aaxis_devtools_elastic_viewer` | ES\|QL query console |
| Redis Viewer | `aaxis_devtools_redis_viewer` | Read-only Redis database/key/value inspector |
| MongoDB Viewer | `aaxis_devtools_mongodb_viewer` | Read-only MongoDB database/collection/document browser |
| Network Tools | `aaxis_devtools_network_tools` | Network diagnostics console |

### Shared storage engine (Filesystem & Bucket browsers)
- **`Storage/StorageBrowserInterface`** — common contract (`getStartPath`, `listDirectory`,
  `readFileContent`, `openResource`).
- **`Storage/FilesystemStorageBrowser`** (local `Filesystem/FilesystemBrowser`) and
  **`Storage/BucketStorageBrowser`** (S3 via `Storage/S3Client`) implement it.
- **`Controller/AbstractStorageBrowserController`** holds the list/preview/raw/download actions;
  `FilesystemBrowserController` / `BucketBrowserController` only declare routes and pick the storage.
- **`storage-browser-component.ts`** is a single front-end component used by both pages.

### Connection tests
The Database / Elastic / Redis / MongoDB viewers and the Bucket Browser expose a **"Test it"** button
on their System Configuration page. The per-tool checks live in `Connection/*ConnectionTester`
(implementing `Aaxis\Bundle\CommonBundle\Connection\ConnectionTesterInterface`) and are dispatched by
CommonBundle's shared registry/endpoint. **Passwords are never returned** in the result.

---

## Persistence (migrations)

| Table | Purpose |
|-------|---------|
| `aaxis_dbviewer_query_history` | Database Viewer query execution log |
| `aaxis_dbviewer_saved_query` | Database Viewer saved queries (favorites) |
| `aaxis_network_test_history` | Network Tools run log (`payload` is `jsonb`) |

### History retention & nightly cleanup
The **Database Viewer** and **Network Tools** sections expose a **"Keep history data for (days)"**
setting (default **30**). The cron command `aaxis:devtools:history:cleanup` runs once a day at
midnight and deletes records older than the configured retention (0 keeps records indefinitely). It
uses the shared `Aaxis\Bundle\CommonBundle\Command\HistoryRetentionPurger`.

---

## Feature toggles & security

Each tool's *Enabled* flag is wired to an Oro **feature toggle** (`Resources/config/oro/features.yml`);
disabling a tool hides its menu item and 404s its routes.

**Access control** uses two action capabilities (`Resources/config/oro/acls.yml`):

- **Access Aaxis Dev Tools** (`aaxis_devtools`) — gates every tool page and AJAX endpoint
  (read/browse). Granted to the Administrator role by
  `Migrations/Data/ORM/LoadAaxisDevToolsAdminPermissions`.
- **Modify storage buckets** (`aaxis_devtools_write`) — gates the *destructive* Bucket Browser
  operations (create folder, upload, delete). It is **not granted by default**, so bucket writes are
  disabled — including for admins — until you assign this capability to a role under
  **System → User Management → Roles → [role] → Capabilities**. Browsing, preview and download stay
  under the access capability above.

**Other safeguards:** Runtime Config always masks secret values (no opt-out). Network Tools and the
Bucket Browser block requests to link-local / cloud-metadata addresses (e.g. `169.254.169.254`),
while still allowing internal RFC1918 hosts so you can diagnose internal services. Connection tests
never return passwords.

These tools are powerful (server filesystem, read-only DB access, outbound network requests,
object-storage read/write); review before exposing the bundle widely.

---

## Installation

Add both repositories and require the package — Composer pulls in `aaxisdigital/oro-common`
automatically (the project already has the Oro Enterprise Composer registry, so
`oro/platform-enterprise` resolves):

```jsonc
// composer.json
"repositories": {
    "aaxis-common":   { "type": "vcs", "url": "https://github.com/aaxisdigital/oro-common.git" },
    "aaxis-devtools": { "type": "vcs", "url": "https://github.com/aaxisdigital/oro-devtools.git" }
}
```

```bash
composer require aaxisdigital/oro-devtools:7.0.*
```

> Requires OroCommerce **Enterprise** (the Elasticsearch viewer uses `oro/platform-enterprise`),
> plus `mongodb/mongodb` and `openspout/openspout` (pulled in automatically).

> **⚠️ This bundle does NOT auto-register — by design.** Unlike the other Aaxis bundles, its
> `Resources/config/oro/bundles.yml` is intentionally left commented out, so the Oro kernel will
> **not** load it just because it is present in `vendor/`. These tools expose the database,
> filesystem, object storage, Redis/Mongo/Elastic and network internals of the running instance, so
> loading them must be a **deliberate, environment-scoped decision** — never an automatic side effect
> of `composer require`. You decide *when* (and in which environments) it is active by registering it
> yourself in `AppKernel`, e.g. enable it only for `dev`/`staging` and keep it out of `prod`:
>
> ```php
> // config/bundles.php (or AppKernel::registerBundles())
> Aaxis\Bundle\DevToolsBundle\AaxisDevToolsBundle::class => ['dev' => true, 'staging' => true],
> ```
>
> Access is still gated by the `aaxis_devtools` ACL and each tool's feature toggle, but registration
> is the first and most important gate. Its dependency `AaxisCommonBundle` **does** auto-register and
> is present anyway (it's required by the other Aaxis bundles), so `AaxisDevToolsBundle` is the only
> one you add by hand — no need to register Common yourself.

After install/update run (prefix each with your PHP runner, e.g. `docker exec <php-container> ...`,
when running in Docker):

```bash
php bin/console cache:clear --no-interaction
php bin/console oro:migration:load --force                 # creates the dbviewer/network tables
php bin/console oro:migration:data:load --no-interaction   # grants the aaxis_devtools ACL to the Administrator role
php bin/console oro:assets:build --no-interaction          # also compiles this bundle's TypeScript
php bin/console oro:translation:load --no-interaction
php bin/console oro:translation:rebuild-cache --no-interaction
php bin/console oro:cron:definitions:load                  # registers aaxis:devtools:history:cleanup
```

---

## Front-end / build

TypeScript components live in `Resources/js-src` and are compiled to `Resources/public/js`
automatically on `oro:assets:build` (via `CompileTypeScriptOnAssetsBuildListener`). Just re-run
`oro:assets:build` after changing any `.ts` source.
