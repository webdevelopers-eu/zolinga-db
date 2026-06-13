-- ----------------------------------------------------------------------------
-- Registry (system-wide key-value store)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `registry` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Primary Key: Unique registry ID.',
  `hash` binary(20) NOT NULL DEFAULT unhex(sha(`name`)) COMMENT 'unhex(sha1(name))',
  `name` varchar(255) NOT NULL COMMENT 'Name of the registry item.',
  `json` text NOT NULL COMMENT 'JSON serialized value of the registry item.',
  `created` int(10) unsigned DEFAULT NULL COMMENT 'Auto updated by trigger. Date and time the registry item was created.',
  `modified` int(10) unsigned DEFAULT NULL COMMENT 'Auto updated by trigger. Date and time the registry item was last modified.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `hash` (`hash`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='Stores configuration data in a key-value pair format.';

-- ========

DROP TRIGGER IF EXISTS `before_update_registry`;

CREATE TRIGGER `before_update_registry`
BEFORE UPDATE ON registry
FOR EACH ROW
SET 
NEW.modified = UNIX_TIMESTAMP(),
NEW.created = IFNULL(OLD.created, UNIX_TIMESTAMP()) ;;
DELIMITER ;
