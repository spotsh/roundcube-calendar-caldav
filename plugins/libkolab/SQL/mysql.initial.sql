/**
 * libkolab database schema
 *
 * @version @package_version@
 * @author Thomas Bruederli
 * @licence GNU AGPL
 **/

DROP TABLE IF EXISTS `kolab_cache`;

CREATE TABLE `kolab_cache` (
  `resource` VARCHAR(255) CHARACTER SET ascii NOT NULL,
  `type` VARCHAR(32) CHARACTER SET ascii NOT NULL,
  `msguid` BIGINT UNSIGNED NOT NULL,
  `uid` VARCHAR(128) CHARACTER SET ascii NOT NULL,
  `created` DATETIME DEFAULT NULL,
  `changed` DATETIME DEFAULT NULL,
  `data` TEXT NOT NULL,
  `xml` TEXT NOT NULL,
  `dtstart` DATETIME,
  `dtend` DATETIME,
  `tags` VARCHAR(255) NOT NULL,
  `words` TEXT NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  PRIMARY KEY(`resource`,`type`,`msguid`),
  INDEX `resource_filename` (`resource`, `filename`)
) /*!40000 ENGINE=INNODB */ /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;

INSERT INTO `system` (`name`, `value`) VALUES ('libkolab-version', '2013041900');
