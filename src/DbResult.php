<?php

declare(strict_types=1);

namespace Zolinga\Database;

use Iterator;
use mysqli, mysqli_result, Exception;


/**
 * Simple mysqli_result wrapper to standardize the result handling and function names.
 * 
 * It impements Traversable interface to allow foreach loop. And also ArrayAccess to allow array-like access.
 * 
 * E.g.
 * 
 *   foreach($api->db->query("SELECT * FROM table") as $row) { ... }
 * 
 *   echo $this->query("SELECT 1 as `myField1`,2 as `myField2`,3")['myField2'];
 *
 * @implements \ArrayAccess<string, string>
 * @implements \Iterator<string, array>
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2024-02-13
 */
class DbResult implements \Iterator, \ArrayAccess
{
    /**
     * MySQLi result external iterator.
     *
     * @var Iterator $iterator
     */
    private Iterator $iterator;

    public function __construct(
        public readonly mysqli_result $result, 
        public readonly ?int $lastInsertId = null
    ) {
        /** @phpstan-ignore-next-line */
        $this->iterator = $this->result->getIterator();
    }

    // public function __get($name)
    // {
    //     $current = $this->current();
    //     if (isset($current[$name])) {
    //         return $current[$name];
    //     }
    //     throw new Exception("Property $name does not exist on current result set: " . print_r($current, true));
    // }

    /**
     * Fetches the next row from the result set as an associative array.
     *
     * @return array<string,mixed>|null The next row as an associative array, or null if there are no more rows.
     */
    public function fetchAssoc(): ?array
    {
        return $this->result->fetch_assoc();
    }

    /**
     * Fetches all rows from the result set as an array of associative arrays.
     *
     * @param int $mode The fetch mode. Default is MYSQLI_ASSOC. Other options include MYSQLI_NUM and MYSQLI_BOTH.
     * @return array<array<string,mixed>> An array of associative arrays representing all rows in the result set.
     */
    public function fetchAll(int $mode = MYSQLI_ASSOC): array
    {
        return $this->result->fetch_all($mode);
    }

    /**
     * Return only first column of all rows as an array.
     *
     * @return array<string|float|int|null>
     */
    public function fetchFirstColumnAll(): array
    {
        return array_map(function ($row) {
            return reset($row);
        }, $this->fetchAll());
    }

    /**
     * Retrieves the number of rows in the result set.
     *
     * @return int<0,max> The number of rows in the result set.
     */
    public function numRows(): int
    {
        /** @phpstan-ignore-next-line */
        return $this->result->num_rows;
    }

    /**
     * Retrieves the number of fields in the result set.
     *
     * @return int The number of fields in the result set.
     */
    public function numFields(): int
    {
        return $this->result->field_count;
    }

    /**
     * Frees the memory associated with the result set.
     */
    public function free(): void
    {
        $this->result->free();
    }

    /**
     * Rewinds the result set to the first row.
     */
    public function rewind(): void
    {
        $this->iterator->rewind();
    }

    /**
     * Checks if the current position in the result set is valid.
     *
     * @return bool True if the current position is valid, false otherwise.
     */
    public function valid(): bool
    {
        return $this->iterator->valid();
    }

    /**
     * Moves the result set pointer to the next row.
     */
    public function next(): void
    {
        $this->iterator->next();
    }

    /**
     * Retrieves the current row from the result set.
     *
     * @return array<string,mixed>|null The current row as an associative array, or null if the current position is invalid.
     */
    public function current(): ?array
    {
        return $this->iterator->current();
    }

    /**
     * Retrieves the current position in the result set.
     *
     * @return mixed The current position in the result set.
     */
    public function key(): mixed
    {
        return $this->iterator->key();
    }

    /** ArrayAccess interface */

    /**
     * Checks if a given offset exists in the current row.
     *
     * @param mixed $offset The offset to check.
     * @return bool True if the offset exists, false otherwise.
     */
    public function offsetExists($offset): bool
    {
        $row = $this->current();
        return isset($row[$offset]);
    }

    /**
     * Retrieves the value at a given offset in the current row.
     *
     * @param mixed $offset The offset to retrieve.
     * @return mixed|null The value at the given offset, or null if the offset does not exist.
     */
    public function offsetGet($offset): mixed
    {
        $row = $this->current();
        return $row[$offset] ?? null;
    }

    /**
     * Sets the value at a given offset in the current row (not supported).
     *
     * @param mixed $offset The offset to set.
     * @param mixed $value The value to set.
     * @throws \RuntimeException This method is not supported.
     */
    public function offsetSet($offset, $value): void
    {
        throw new \RuntimeException("Setting values in the current row is not supported.");
    }

    /**
     * Unsets the value at a given offset in the current row (not supported).
     *
     * @param mixed $offset The offset to unset.
     * @throws \RuntimeException This method is not supported.
     */
    public function offsetUnset($offset): void
    {
        throw new \RuntimeException("Unsetting values in the current row is not supported.");
    }

    public function __destruct()
    {
        $this->free();
    }
}
