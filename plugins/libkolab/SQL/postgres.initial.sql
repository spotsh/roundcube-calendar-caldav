/**
 * libkolab database schema
 *
 * @version @package_version@
 * @author Sidlyarenko Sergey
 * @licence GNU AGPL
 **/

DROP TABLE IF EXISTS kolab_cache;

CREATE TABLE kolab_cache (
  resource character varying(255) NOT NULL,
  type character varying(32) NOT NULL,
  msguid NUMERIC(20) NOT NULL,
  uid character varying(128) NOT NULL,
  created timestamp without time zone DEFAULT NULL,
  changed timestamp without time zone DEFAULT NULL,
  data text NOT NULL,
  xml text NOT NULL,
  dtstart timestamp without time zone,
  dtend timestamp without time zone,
  tags character varying(255) NOT NULL,
  words text NOT NULL,
  filename character varying(255) DEFAULT NULL,
  PRIMARY KEY(resource, type, msguid)
);

CREATE INDEX kolab_cache_resource_filename_idx ON kolab_cache (resource, filename);


INSERT INTO system (name, value) VALUES ('libkolab-version', '2013041900');
