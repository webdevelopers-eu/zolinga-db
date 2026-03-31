## Description

MySQL database service providing SQL query API.

- **Service:** `$api->db`
- **Class:** `Zolinga\Database\DbService`
- **Module:** zolinga-db
- **Event:** `system:service:db`

## Usage

```php
$result = $api->db->query("SELECT * FROM users WHERE id = ?", $id);
$rows = $api->db->queryExpand("SELECT * FROM users WHERE id IN (?)", [$ids]);
```
