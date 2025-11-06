<?php

declare(strict_types=1);

namespace Zolinga\Database;

use Zolinga\System\Events\ServiceInterface;
use mysqli, mysqli_result, mysqli_stmt;
use UnitEnum, BackedEnum;
use Exception, Throwable, InvalidArgumentException, Stringable;


/**
 * Simple DB $api->db service.
 * 
 * Usage:
 * 
 * $text = $api->db->query("SELECT text from test LIMIT 1")['text'];
 * 
 * foreach($api->db->query("SELECT * from test;") as $row) {
 *   echo $row['text'];
 * }
 * 
 * $lastInsertId = $api->db->query("INSERT INTO test (text) VALUES (?)", "Hello world!");
 * $updatedRowsNum = $api->db->query("UPDATE test SET text=?", "Hello world! ". date('c'));
 * 
 * 
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2024-02-10
 */
class DbService implements ServiceInterface
{
    private ?\mysqli $conn = null;

    public function __construct()
    {
        $this->connect();
    }

    private function connect(): void
    {
        $credentials = $this->getCredentials();

        $credentials['host'] or throw new Exception("Missing MySQL host in the configuration!");
        $credentials['user'] or throw new Exception("Missing MySQL user in the configuration!");
        $credentials['password'] or throw new Exception("Missing MySQL password in the configuration!");
        $credentials['database'] or throw new Exception("Missing MySQL database in the configuration!");

        try {
            $this->conn = new mysqli($credentials['host'], $credentials['user'], $credentials['password'], $credentials['database']);
        } catch (Throwable $e) {
            trigger_error("Error connecting to MySQL: EXCEPTION[". get_class($e). "]: " . $e->getMessage(). ", CODE: " . $e->getCode() . ", host = {$credentials['host']}, user = {$credentials['user']}, database = {$credentials['database']}", E_USER_WARNING);
            throw new Exception("DB Connection failed.", 500, $e); // this will bubble up to the front end.
        }
        // if (!is_object($this->conn) || $this->conn->connect_error) {
        //     trigger_error("Connection failed: " . $this->conn->connect_error, E_USER_WARNING);
        //     throw new Exception("DB Connection failed."); // this will bubble up to the front end.
        // }
    }

    /**
     * Get credentials from the config file.
     *
     * @return array{host: string, user: string, password: string, database: string}
     */
    protected function getCredentials(): array
    {
        global $api;
        $credentials = $api->config['database'] or throw new Exception("Missing database configuration in the configuration file!");
        return $credentials;
    }

    /**
     * Executes the given SQL query and returns the DbResult result.
     * 
     * DbResult implements both Iterable and ArrayAccess so you can do cool stuff like:
     * 
     *  foreach($api->db->query("SELECT * FROM table") as $row) { ... }
     *  echo $this->query("SELECT 'abc' as `myField2`")['myField2'];
     *  
     * Also you can use also bind parameters to make sure the values are properly escaped.
     * 
     *  $this->query("SELECT * FROM table WHERE id = ?", $id);
     * 
     * @throws Exception In case of any error.
     * @param string $sql The SQL query to execute.
     * @param int|float|string|Stringable|null|UnitEnum|bool ...$params The parameters to bind to the query.
     * @return DbResult|int The result of the SELECT query, or int number of affected rows for UPDATE|DELETE or last insert id for INSERT
     *          For INSERT ... ON DUPLICATE KEY UPDATE it returns the existing ID if the value was updated and 0 if all the values are same as before.
     */
    public function query(string $sql, int|float|string|Stringable|null|UnitEnum|bool ...$params): DbResult|int
    {
        global $api;

        try {
            $stmt = $this->conn->prepare($sql);
        } catch (Throwable $e) {
            $api->log->error("db", "Error preparing query: " . $e->getMessage(), ['sql' => $sql, 'trace' => $e->getTraceAsString()]);
            throw $e; // rethrow
        }

        if ($stmt === false) {
            throw new Exception("Failed to prepare the query $sql: " . $this->conn->error);
        }

        try {
            if (!empty($params)) {
                // Convert objects into strings
                $params = array_map(fn ($param) => $this->paramToScalar($param), $params);
                $types = '';
                $bindParams = [];
                foreach ($params as $param) {
                    if (is_int($param) || is_bool($param)) {
                        $types .= 'i';
                        $bindParams[] = $param;
                    } elseif (is_float($param)) {
                        $types .= 'd';
                        $bindParams[] = $param;
                    } elseif (is_string($param)) {
                        $types .= 's';
                        $bindParams[] = $param;
                    } else { // blob sent in packets
                        $types .= 'b';
                        $bindParams[] = $param;
                    }
                }
                $stmt->bind_param($types, ...$bindParams);
            }

            $stmt->execute();

            if ($this->conn->errno) {
                throw new Exception("Failed to execute the query: " . $this->conn->error);
            }

            $ret = $this->resultToObject($stmt->get_result(), $stmt);
        } catch (Throwable $e) {
            $api->log->error("db", "Error executing query: ".substr(trim($sql), 0, 128)." \nError: " . get_class($e) ." #" . $e->getCode(). " " . $e->getMessage(), 
                ['sql' => $sql, 'params' => $params, 'trace' => $e->getTraceAsString()]);
            throw $e; // rethrow, show we log it somewhere here?
        } finally {
            $stmt->close();
        }

        return $ret;
    }

    /**
     * Same as query() but supports ?? to expand into multiple parameters or multiple values.
     * 
     * You can surround the ?? with backticks, single or double quotes to expand into multiple field names or multiple field values.
     * 
     * You can combine standard ? with ?? in the same query. But ?? must always map to array parameter while ? to scalar.
     *  
     * E.g. 
     * 
     *   INSERT INTO rmsUsers (`??`) VALUES ('??');
     *   UPDATE rmsUsers SET ?? WHERE id = ?;
     * 
     * will expand into internally into
     * 
     *   INSERT INTO rmsUsers (`username`, `password`) VALUES (?, ?);
     *   UPDATE rmsUsers SET `key1` = ?, `key2` = ? WHERE id = ?;
     * 
     * Example:
     * 
     *   $data = [
     *     "username" => "test@example.com",
     *     "password" => "123456",
     *   ];
     *   $api->db->queryExpand("INSERT INTO rmsUsers (`??`) VALUES ('??')", array_keys($data), $data);
     *   $api->db->queryExpand("UPDATE table SET ?? WHERE id = ?", ['key1' => 'value1', 'key2' => 'value2'], 123);
     * 
     * @throws Exception In case of any error.
     * @param string $sql The SQL query to execute.
     * @param int|float|string|null|Stringable|UnitEnum|bool|array<int|float|string|null|Stringable|UnitEnum|bool> ...$params The parameters to bind to the query.
     * @return DbResult|int The result of the SELECT query, or int number of affected rows for UPDATE|DELETE or last insert id for INSERT
     */
    public function queryExpand(string $sql, array|int|float|string|null|Stringable|UnitEnum|bool ...$params): DbResult|int
    {
        $processParams = $params;
        $paramsExpanded = [];
        $sqlExpanded = preg_replace_callback("/(?<quote>[`'\"])?(?<replace>\?{1,2})(?:\k<quote>)?/", function ($matches) use (&$processParams, &$paramsExpanded) {
            $param = array_shift($processParams);
            if ($matches["replace"] === '?') {
                $paramsExpanded[] = $param;
                return '?';
            } elseif ($matches["replace"] === '??' && is_array($param)) { // Expand to multiple params
                if (!count($param)) {
                    throw new InvalidArgumentException("Invalid parameter in the query: $matches[0] . The positional parameter ?? must be an array with at least one element.");
                } elseif ($matches['quote'] == '"' || $matches['quote'] == "'") { // Expand to ?, ?, ...
                    $paramsExpanded = [...$paramsExpanded, ...$param];
                    return implode(', ', array_fill(0, count($param), '?'));
                } elseif ($matches['quote'] == '`') { // Expand to `key`, `key`, ...
                    return implode(', ', array_map(fn ($v) => $this->quoteIdentifier((string) $this->paramToScalar($v)), $param));
                } else { // Expand to `key1` = ?, `key2` = ?, ...
                    $paramsExpanded = [...$paramsExpanded, ...array_values($param)];
                    return implode(', ', array_map(
                        fn ($k) => is_string($k) ? $this->quoteIdentifier($k) . ' = ?' : throw new InvalidArgumentException("Invalid parameter in the query \"$k\". The keys must be a colum names in array: " . json_encode($param) . " . The positional argument {$matches[0]} will be expanded to `key` = ?, `key` = ? and so on! Did you mean to use \"`??`\" or \"'??'\" instead of \"{$matches[0]}\" ?"),
                        array_keys($param)
                    ));
                }
            } else {
                throw new InvalidArgumentException("Invalid parameter in the query: $matches[0] . If ?? is used then the corresponding positional parameter must be an array: " . json_encode($param));
            }
        }, $sql);

        /** @phpstan-ignore-next-line */
        return $this->query($sqlExpanded, ...$paramsExpanded);
    }

    /**
     * Convert the parameter to scalar.
     *
     * @param int|float|string|null|Stringable|UnitEnum|bool $param
     * @return string|int|float|bool
     */
    private function paramToScalar(int|float|string|null|Stringable|UnitEnum|bool $param): string|int|float|bool|null
    {
        if (is_scalar($param)) return $param;
        if (is_null($param)) return null;
        if ($param instanceof Stringable) return (string) $param;
        if ($param instanceof BackedEnum) return $param->value;
        if ($param instanceof UnitEnum) return $param->name;
        
        throw new InvalidArgumentException("Invalid parameter type: " . gettype($param));
    }

    /**
     * Execute multiple queries at once. Does not support prepared statements.
     * 
     * Example: 
     * 
     * $results = $api->db->multiQuery("SELECT * FROM table1; SELECT * FROM table2; DELETE FROM table3 WHERE id = 1;");
     *
     * @throws Exception In case of any error. If $transaction is set to true, then it will rollback the transaction and rethrow the exception.
     * @param string $sql semicolon separated SQL queries
     * @param false|int $transaction MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT|MYSQLI_TRANS_START_READ_WRITE|MYSQLI_TRANS_START_READ_ONLY|false , if false then queries won't be wrapped into transaction. Default: MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT
     * @return array<DbResult|int> The result of the SELECT queries, or int number of affected rows for UPDATE|DELETE or last insert id for INSERT 
     */
    public function multiQuery(string $sql, false|int $transaction = MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT): array
    {
        global $api;
        $results = [];

        try {
            if ($transaction) {
                $this->conn->begin_transaction($transaction);
            }
            $this->conn->multi_query($sql);

            if ($this->conn->errno) {
                throw new Exception("Failed to execute the query: " . $sql . " " . $this->conn->error);
            }

            do {
                $results[] = $this->resultToObject($this->conn->store_result());
            } while ($this->conn->next_result());

            if ($transaction) {
                $this->conn->commit();
            }
        } catch (Throwable $e) {
            if ($transaction) {
                $this->conn->rollback();
            }
            $api->log->error("db", "Error executing multiQuery: " . $e->getMessage(), ['sql' => $sql, 'trace' => $e->getTraceAsString()]);
            throw $e; // rethrow, show we log it somewhere here?
        }

        return $results;
    }

    /**
     * Converts the result to DbResult or int. So for SELECT queries it returns DbResult, 
     * for INSERT it returns last insert id, for UPDATE|DELETE it returns number of affected rows.
     *
     * @param mysqli_result|false $result
     * @param mysqli_stmt|false $stmt optional statment to use to retrieve affected rows from...
     * @return DbResult|int The result of the SELECT query, or int number of affected rows for UPDATE|DELETE or last insert id for INSERT
     */
    private function resultToObject(mysqli_result|false $result, mysqli_stmt|false $stmt = false): int|DbResult
    {
        // Updates and removes return "false" so we need to distinguish states
        // by calling mysqli_stmt_errno() 
        if ($result === false) {
            // Insert or update
            $ret = $this->getLastInsertId() ?: ($stmt ? mysqli_stmt_affected_rows($stmt) : $this->conn->affected_rows);
            $ret = intval($ret);
        } else {
            $ret = new DbResult($result, intval($this->conn->insert_id));
        }

        return $ret;
    }

    /**
     * Escapes the identifier (table or column name) and optionally adds quotes.
     *
     * @param string $value
     * @param boolean $addQuotes
     * @return string
     */
    public function quoteIdentifier(string $value, bool $addQuotes = true): string
    {
        $ret = str_replace('`', '``', $value);
        return $addQuotes ? "`$ret`" : $ret;
    }

    /**
     * Escapes the string and optionally adds quotes.
     *
     * @param string $value
     * @param boolean $addQuotes
     * @return string
     */
    public function quoteString(string $value, bool $addQuotes = true): string
    {
        $ret = $this->conn->real_escape_string($value);
        return $addQuotes ? "'$ret'" : $ret;
    }

    /**
     * Get the last insert id.
     *
     * @return integer|string
     */
    public function getLastInsertId(): int|string
    {
        return $this->conn->insert_id;
    }

    public function close(): void
    {
        $this->conn->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __toString()
    {
        return "DbService[{$this->conn->host_info}]";
    }
}
