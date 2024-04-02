create table registry (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary Key: Unique registry ID.',
    `hash` BINARY(20) NOT NULL DEFAULT (UNHEX(SHA1(`name`))) COMMENT 'unhex(sha1(name))',
    `name` VARCHAR(255) NOT NULL COMMENT 'Name of the registry item.',
    `json` TEXT NOT NULL COMMENT 'JSON serialized value of the registry item.',
    `created` INT(10) UNSIGNED DEFAULT NULL COMMENT 'Auto updated by trigger. Date and time the registry item was created.',
    `modified` INT(10) UNSIGNED DEFAULT NULL COMMENT 'Auto updated by trigger. Date and time the registry item was last modified.',
    PRIMARY KEY (`id`),
    UNIQUE KEY `hash` (`hash`),
    UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Stores configuration data in a key-value pair format.';


CREATE TRIGGER before_update_registry
BEFORE UPDATE ON registry
FOR EACH ROW
SET 
NEW.modified = UNIX_TIMESTAMP(),
NEW.created = IFNULL(OLD.created, UNIX_TIMESTAMP());

-- insert installation unique id
INSERT INTO 
    registry (hash, name, json) 
VALUES (
    UNHEX(SHA1('system:id')), 
    'system:id', 
    CONCAT('"', UUID(), '"')
);
