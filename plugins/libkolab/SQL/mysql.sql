/**
 * libkolab database schema
 *
 * @version @package_version@
 * @author Thomas Bruederli
 * @licence GNU AGPL
 **/

CREATE TABLE `kolab_cache` (
  `resource` VARCHAR(255) CHARACTER SET ascii NOT NULL,
  `type` VARCHAR(32) CHARACTER SET ascii NOT NULL,
  `msguid` BIGINT UNSIGNED NOT NULL,
  `uid` VARCHAR(128) CHARACTER SET ascii NOT NULL,
  `data` TEXT NOT NULL,
  `xml` TEXT NOT NULL,
  `dtstart` DATETIME,
  `dtend` DATETIME,
  `tags` VARCHAR(255) NOT NULL,
  PRIMARY KEY(`resource`,`type`,`msguid`)
) /*!40000 ENGINE=INNODB */ /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;
