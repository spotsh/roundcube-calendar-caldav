/**
 * RoundCube Calendar
 *
 * Plugin to add a calendar to RoundCube.
 *
 * @version 0.3 beta
 * @author Lazlo Westerhof
 * @author Albert Lee
 * @author Aleksander Machniak <machniak@kolabsys.com>
 * @url http://rc-calendar.lazlo.me
 * @licence GNU GPL
 * @copyright (c) 2010 Lazlo Westerhof - Netherlands
 *
 **/


CREATE SEQUENCE calendar_ids
    INCREMENT BY 1
    NO MAXVALUE
    NO MINVALUE
    CACHE 1;

CREATE TABLE calendars (
    calendar_id integer DEFAULT nextval('calendar_ids'::regclass) NOT NULL,
    user_id integer NOT NULL
        REFERENCES users (user_id) ON UPDATE CASCADE ON DELETE CASCADE,
    name varchar(255) NOT NULL,
    color varchar(8) NOT NULL,
    showalarms smallint NOT NULL DEFAULT 1,
    PRIMARY KEY (calendar_id)
);


CREATE SEQUENCE event_ids
    INCREMENT BY 1
    NO MAXVALUE
    NO MINVALUE
    CACHE 1;

CREATE TABLE events (
    event_id integer DEFAULT nextval('event_ids'::regclass) NOT NULL,
    calendar_id integer NOT NULL
        REFERENCES calendars (calendar_id) ON UPDATE CASCADE ON DELETE CASCADE,
    recurence_id integer NOT NULL DEFAULT 0,
    uid varchar(255) NOT NULL DEFAULT '',
    created timestamp without time zone DEFAULT now() NOT NULL,
    changed timestamp without time zone DEFAULT now(),
    "start" timestamp without time zone DEFAULT now() NOT NULL,
    "end" timestamp without time zone DEFAULT now() NOT NULL,
    recurrence varchar(255) DEFAULT NULL,
    title character varying(255) NOT NULL,
    description text NOT NULL,
    location character varying(255) NOT NULL,
    categories character varying(255) NOT NULL,
    all_day smallint NOT NULL DEFAULT 0,
    free_busy smallint NOT NULL DEFAULT 0,
    priority smallint NOT NULL DEFAULT 0,
    sensitivity smallint NOT NULL DEFAULT 0,
    alarms varchar(255) DEFAULT NULL,
    attendees text DEFAULT NULL,
    notifyat timestamp without time zone DEFAULT NULL
    PRIMARY KEY (event_id)
);


CREATE SEQUENCE attachment_ids
    INCREMENT BY 1
    NO MAXVALUE
    NO MINVALUE
    CACHE 1;

CREATE TABLE attachments (
    attachment_id integer DEFAULT nextval('attachment_ids'::regclass) NOT NULL,
    event_id integer NOT NULL
        REFERENCES  events (event_id) ON DELETE CASCADE ON UPDATE CASCADE,
    filename varchar(255) NOT NULL DEFAULT '',
    mimetype varchar(255) NOT NULL DEFAULT '',
    size integer NOT NULL DEFAULT 0,
    data text NOT NULL DEFAULT '',
    PRIMARY KEY (attachment_id)
);
