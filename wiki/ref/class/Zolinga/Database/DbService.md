Priority: 0.6

# Database Service

Simple DB `$api->db` service.

Usage:
```php
$text = $api->db->query("SELECT text from test LIMIT 1")['text'];

foreach($api->db->query("SELECT * from test;") as $row) {
  echo $row['text'];
}

$lastInsertId = $api->db->query("INSERT INTO test (text) VALUES (?)", "Hello world!");

$updatedRowsNum = $api->db->query("UPDATE test SET text=?", "Hello world! ". date('c'));
```

## Methods

### query(string $sql, ...$params): DbResult|int

Executes the given SQL query and returns the `\Zolinga\Database\DbResult` object or the number of affected rows for UPDATE|DELETE or last insert id for INSERT.

DbResult implements both Iterable and ArrayAccess so you can do cool stuff like:

```php
foreach($api->db->query("SELECT * FROM table") as $row) { ... }

echo $this->query("SELECT 'abc' as `myField2`")['myField2'];
```

Also you can use also bind parameters to make sure the values are properly escaped.

```php
 $this->query("SELECT * FROM table WHERE id = ?", $id);
```

### expandQuery(string $sql, ...$params): array

Same as `query()` but supports `??` to expand into multiple parameters or multiple values.

You can surround the `??` with backticks, single or double quotes to expand into multiple parameters or multiple values.

You can combine standard `?` with `??` in the same query. But `??` must always map to array parameter while `?` to scalar.
 
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

$api->db->expandQuery(
    "INSERT INTO table SET ?? WHERE id = ?", 
    ['key1' => 'value1', 'key2' => 'value2'], 
    123
);
```

### multiQuery(string $sql, false|int $transaction): array

Execute multiple queries at once. Does not support prepared statements.

Example: 

```php
$results = $api->db->multiQuery(<<<'EOT'
    SELECT * FROM table1; 
    SELECT * FROM table2; 
    DELETE FROM table3 WHERE id = 1;
    EOT);

foreach($results as $result) {
    // ...
}
```

The second parameter is optional and can be used to start or prevent a transaction. If set to `false` no transaction is started. By default
the third parameter is set to `MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT`.

When one query fails the transaction is rolled back and the method throws an exception.