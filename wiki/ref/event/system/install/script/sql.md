## Description

Executes SQL installation and update scripts during module installation.

- **Event:** `system:install:script:sql`
- **Class:** `Zolinga\Database\InstallSqlScript`
- **Method:** `onInstall`
- **Origin:** `internal`
- **Event Type:** `\Zolinga\System\Events\InstallScriptEvent`

## Behavior

When the install controller finds a `.sql` file in a module's `install/install/` or `install/install/updates/` directory, this handler executes it against the database via `$api->db`.

## See Also

- [system:install:script:*](wildcard.md) — the wildcard install script event
- SQL Installation Scripts wiki article
