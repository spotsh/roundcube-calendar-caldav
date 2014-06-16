/**
 * Roundcube Calendar
 *
 * Plugin to add a calendar to Roundcube.
 *
 * @version @package_version@
 * @author Lazlo Westerhof
 * @author Thomas Bruederli
 * @url http://rc-calendar.lazlo.me
 * @licence GNU AGPL
 * @copyright (c) 2010 Lazlo Westerhof - Netherlands
 *
 **/

CREATE TABLE IF NOT EXISTS `ical_props` (
  `obj_id` int(11) NOT NULL,
  `obj_type` enum('ical','vevent','vtodo','') NOT NULL,
  `url` varchar(255) NOT NULL,
  `user` varchar(255) DEFAULT NULL,
  `pass` varchar(1024) DEFAULT NULL,
  `last_change` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `obj_id` (`obj_id`,`obj_type`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;