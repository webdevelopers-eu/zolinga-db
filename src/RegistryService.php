<?php

namespace Zolinga\Database;
use Zolinga\System\Events\ServiceInterface;

/**
 * DB API for global settings.
 *
 * For global read-only configuration refer to $api->config service.
 *
 * Example:
 *   $api->registry->set("cms.title", "Zolinga");
 *   $title=$api->registry->get("cms.title");
 *   $newVal=$api->registry->increment("cms.viewCounter");
 * 
 * You can store any JSON-serializable structure. To retrieve typed values use
 * 
 * $api->registry->getString(...);
 * $api->registry->getInt(...);
 * $api->registry->getFloat(...);
 * $api->registry->getArray(...);
 * 
 * Example:
 * 
 *   $api->registry->set("cms.title", "Zolinga");
 *   $title=$api->registry->getString("cms.title", "default value");
 * 
 * PHP will check the type of the returned value and throw an error if it is not of the expected type.
 *
 * There is 'system:id' key with 32-char md5 random hash that is unique installation id.
 *
 * @module    Registry
 * @author     Daniel Sevcik <sevcik@webdevelopers.cz>
 * @copyright  2015 Daniel Sevcik
 * @since      2015-09-28 20:30:16 UTC
 * @access     public
 */
class RegistryService implements ServiceInterface
{
    /** 
     * Unique installation ID. Can be used for various purposes, e.g. to
     * obtain MySQL locks and such.
     * 
     * It is stored in registry's `system:id` key and read from there.
     * 
     * @var string
     */
    public readonly string $installationId;

    public function __construct() {
        global $api;

        $json = $api->db->query("SELECT `json` FROM registry WHERE `hash`=unhex(sha1(?))", "system:id")['json'];

        $this->installationId = json_decode($json, true)
            or throw new \Exception("Registry `system:id` not found.", 500);
    }

    /**
     * Take value associated with $name, increment it by 1 and save it to DB.
     * Return incremented value.
     *
     * This method is secured against multiple concurrent increments.
     *
     * Example:
     *   $c=$api->registry->increment("cms.viewCounter"); // $c=1
     *   $c=$api->registry->increment("cms.viewCounter"); // $c=2
     *   $c=$api->registry->increment("cms.viewCounter"); // $c=3
     *
     *   $c=$api->registry->increment("cms.newCounter", 100); // $c=100
     *   $c=$api->registry->increment("cms.newCounter", 100); // $c=101
     *
     * @access public
     * @param string $name to increment
     * @param int $startFrom default number to start from (first returned value will be $startFrom). Default: 1
     * @return int incremented value
     */
    public function increment(string $name, int $startFrom = 1): int
    {
        global $api;

        $q = '
            INSERT INTO registry (`name`, `json`)
            VALUES (?, @sequence:=?)
            ON DUPLICATE KEY UPDATE `json`=@sequence:=`json` + 1
            ';
        $api->db->query($q, $name, $startFrom);

        return intval($api->db->query('SELECT @sequence as `val`')['val']);
    }

    /**
     * Obtain an inter-script lock on the resource using MySQL locking.
     *
     * A lock is released explicitly by executing $this->releaseLock() or
     * implicitly when your session terminates (either normally or
     * abnormally).
     *
     * Examples:
     * 
     * if ($api->registry->acquireLock("cms.installation", 2)) {
     *   // installation lock obtained within 2 seconds
     * }
     *
     * if ($api->registry->acquireLock("cms.myResource.31", "+1 minute")) {
     *    throw new Exception("Already locked by other database connection!");
     * }
     * $api->registry->releaseLock("cms.myResource.31");
     * 
     * DANGER: If the lock was obtained by other connection, the PHP script
     * will pause until the lock is released or until $timeout is reached.
     * Use $timeout=0 to fail immediatelly if the lock is not available.
     * 
     * WARNING: This lock is bound to database connection. The same PHP script run
     * having the same database connection can obtain multiple locks on the same
     * resource and is not blocked by itself. It blocks only _other_ scripts
     * having open different database connection.
     * 
     * @see https://mariadb.com/kb/en/get_lock/
     * @access public
     * @param string $name unique ID of the resource
     * @param int|string $timeout INT: timeout seconds, STRING: strtotime() compatible string
     * @return int|false FALSE: if lock failed to be obtained; INT: unix timeout time if this lock
     */
    public function acquireLock(string $name, int|string $timeout = 0): int|false
    {
        global $api;

        // MySQL: A lock obtained with GET_LOCK() is released
        // explicitly by executing RELEASE_LOCK() or implicitly when
        // your session terminates (either normally or
        // abnormally).
        $timeoutSec = is_numeric($timeout) ? intval($timeout) : (strtotime($timeout) - time());
        $q = "SELECT GET_LOCK(?, ?) as ret;";
        $res = $api->db->query(
            $q,
            $this->installationId . ':' . $name,
            $timeoutSec
        );
        return $res['ret'] ?: false;
    }

    /**
     * Release the lock obtained by $api->registry->acquireLock() call.
     *
     * @access public
     * @param string $name unique ID of the resource
     * @return void
     */
    public function releaseLock(string $name): void
    {
        global $api;

        $q = "SELECT RELEASE_LOCK(?) as ret;";
        $api->db->query($q, $this->installationId . ':' . $name);
    }

    /**
     * Get setting.
     * 
     * Example:
     * 
     * $title=$api->registry->get("cms.title");
     * $title=$api->registry->get("cms.title", "default value");
     * 
     * If you need to ensure that the returned value is of a specific type use
     * 
     *   $api->registry->getString(...);
     *   $api->registry->getInt(...);
     *   $api->registry->getFloat(...);
     *   $api->registry->getArray(...);
     *
     * @param string $name - name of the setting
     * @param string|int|float|array<mixed>|null $default - default value if setting is not found
     * @return string|int|float|array<mixed>|null JSON deserialized stored value
     */
    public function get(string $name, string|int|float|array|null $default = null): string|int|float|array|null {
        global $api;

        if ($name === 'system:id') { // quick return for installation ID
            return $this->installationId;
        }

        $res = $api->db->query("SELECT `json` FROM registry WHERE `hash`=unhex(sha1(?))", $name);
        return !empty($res['json']) ? json_decode($res['json'], true) : $default;
    }

    /**
     * Return registry stored string value.
     * 
     * Ensures that the returned value is of type string otherwise PHP will throw an error.
     * 
     * Use for strong type checking.
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    public function getString(string $name, string $default = ''): string {
        /* PHP's strong return type checks will take care of checking the value */
        /** @phpstan-ignore-next-line */ 
        return $this->get($name, $default);
    }

    /**
     * Return registry stored integer value.
     * 
     * Ensures that the returned value is of type integer otherwise PHP will throw an error.
     * 
     * Use for strong type checking.
     *
     * @param string $name
     * @param integer $default
     * @return integer
     */
    public function getInt(string $name, int $default = 0): int {
        /* PHP's strong return type checks will take care of checking the value */
         /** @phpstan-ignore-next-line */
        return $this->get($name, $default);
    }

    /**
     * Return registry stored float value.
     * 
     * Ensures that the returned value is of type float otherwise PHP will throw an error.
     * 
     * Use for strong type checking.
     * 
     * @param string $name
     * @param float $default
     * @return float
     */
    public function getFloat(string $name, float $default = 0.0): float {
        /* PHP's strong return type checks will take care of checking the value */
        /** @phpstan-ignore-next-line */
        return $this->get($name, $default);
    }

    /**
     * Return registry stored array value.
     * 
     * Ensures that the returned value is of type array otherwise PHP will throw an error.
     * 
     * Use for strong type checking.
     *
     * @param string $name
     * @param array<mixed> $default
     * @return array<mixed>
     */
    public function getArray(string $name, array $default = []): array {
        /* PHP's strong return type checks will take care of checking the value */
        /** @phpstan-ignore-next-line */
        return $this->get($name, $default);
    }

    /**
     * Set setting.
     *
     * @access public
     * @param string $name
     * @param string|int|float|array<mixed>|null $value - if null unset the record from DB
     * @return string|int|float|array<mixed>|null $value
     */
    public function set(string $name, string|int|float|array|null $value = null): string|int|float|array|null
    {
        global $api;

        if (strlen($name) > 255) {
            throw new \Exception("Registry name `$name` is >255 characters.", 500);
        }

        if ($value === null) {
            $this->unset($name);
            return null;
        }

        // $api->db->query("REPLACE INTO registry SET `name`=" . $api->db->escapeString($name, true) . ", `val`=" . $valueSQL);
        $q = "
            INSERT INTO 
                registry (`name`, `json`, `hash`) 
            VALUES 
                (?, ?, unhex(sha1(?))) 
            ON DUPLICATE KEY UPDATE 
                `json`= values(`json`);";
        $res = $api->db->query($q, $name, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $name);
        if ($res === false) {
            throw new \Exception("Cannot save Registry value for `$name`", 500);
        }
        return $value;
    }

    /**
     * Remove $name (set it to null).
     *
     * @access public
     * @param string $name
     * @return void
     */
    public function unset(string $name): void
    {
        global $api;
        $api->db->query("DELETE FROM registry WHERE `hash`=unhex(sha1(?))", $name);
    }
}
