# Zolinga Database

This module provides API to MySQL/MariaDB database. It is based on the [mysqli](https://www.php.net/manual/en/book.mysqli.php) PHP extension.

This module provides:

- `$api->db` service to interact with the database.
- `$api->registry` service to store and retrieve key - value pairs.
    - The registry provides also simple database-based way to obtain cross-script locks.

## SQL Queries

### Simple Queries

The `$api->db->query()` method to execute SQL queries. The method returns the `\Zolinga\Database\DbResult` object that implements both [Iterable](https://www.php.net/manual/en/class.iterator.php) and [ArrayAccess](https://www.php.net/manual/en/class.arrayaccess.php) interfaces.

The method also supports binding parameters to the query. This ensures that the values are properly escaped.

```php
// You can use ArrayAccess interface to access current row's columns
$text = $api->db->query("SELECT text from test LIMIT 1")['text'];

// You can use Iterable interface to iterate over the result set
foreach($api->db->query("SELECT * from test WHERE grp = ?", "sales") as $row) {
    echo $row['text'];
}

// You can use binding parameters to the query and get the last insert id
$lastInsertId = $api->db->query("INSERT INTO test (text) VALUES (?)", "Hello world!");

// Get number of updated rows
$updatedRowsNum = $api->db->query("UPDATE test SET text = ? WHERE id = ?", "Hello world!", 2);
```

### Multiple Queries

The method `$api->db->multiQuery()` allows you to execute multiple queries at once. It does not support prepared statements.

```php
$results = $api->db->multiQuery(<<<EOT
    SELECT * FROM table1; 
    SELECT * FROM table2; 
    DELETE FROM table3 WHERE id = 1;
    EOT); // Returns array<DbResult|int>
```

### Expanding SQL Queries

The method `$api->db->expandQuery()` allows you to expand SQL queries with the values from the array.

It is same as `$api->db->query()` but supports `??` to expand into multiple parameters or multiple values.
You can surround the with backticks, single or double quotes to expand into multiple parameters or multiple values.

You can combine standard `?` with `??` in the same query. But `??` must always map to array parameter while `?` to a scalar.
 
- `\`??\`` will expand into `\`value1\`, \`value2\`, ...`
- `'??'` or `"??"` will expand into `'value1', 2, 'value3', ...` strings or numbers depending on the value type.
- `??` will expand into `\`key1\` = 'value1', \`key2\` = 'value2', ...` where values are strings or numbers depending on the value type.


Example:

```php
$data = [
  "username" => "test@example.com",
  "password" => "123456",
];

$api->db->expandQuery(
    "INSERT INTO rmsUsers (`??`) VALUES ('??')", 
    array_keys($data), 
    $data
);
// Executes: INSERT INTO rmsUsers (`username`, `password`) VALUES ('test@example.com', '123456')

$api->db->expandQuery(
    "INSERT INTO table SET ?? WHERE id = ?", 
    ['key1' => 'value1', 'key2' => 'value2'], 
    123
);
// Executes: INSERT INTO table SET `key1` = 'value1', `key2` = 'value2' WHERE id = 123
```
