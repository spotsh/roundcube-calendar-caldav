/**
 * Roundcube Calendar Kolab backend
 *
 * @version 0.3 beta
 * @author Thomas Bruederli
 * @licence GNU GPL
 **/

CREATE TABLE `kolab_alarms` (
  `event_id` VARCHAR(255) NOT NULL,
  `notifyat` DATETIME DEFAULT NULL,
  `dismissed` TINYINT(3) UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY(`event_id`)
) /*!40000 ENGINE=INNODB */;
