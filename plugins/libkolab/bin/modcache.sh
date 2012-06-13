#!/usr/bin/env php
<?php

/**
 * Kolab storage cache modification script
 *
 * @version 3.0
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

define('INSTALL_PATH', realpath('.') . '/' );
ini_set('display_errors', 1);

if (!file_exists(INSTALL_PATH . 'program/include/clisetup.php'))
    die("Execute this from the Roundcube installation dir!\n\n");

require_once INSTALL_PATH . 'program/include/clisetup.php';

function print_usage()
{
	print "Usage:  modcache.sh [OPTIONS] ACTION [ARGS...]\n";
	print "-a, --all      Clear/expunge all caches\n";
	print "-h, --host     IMAP host name\n";
	print "-u, --user     IMAP user name\n";
	print "-t, --type     Object types to clear/expunge cache\n";
	print "-l, --limit    Limit the number of records to be expunged\n";
}

// read arguments
$opts = get_opt(array(
    'a' => 'all',
    'h' => 'host',
    'u' => 'user',
    'p' => 'password',
    't' => 'type',
    'l' => 'limit',
    'v' => 'verbose',
));

$action = $opts[0];

$rcmail = rcmail::get_instance();


/*
 * Script controller
 */
switch (strtolower($action)) {

/*
 * Clear/expunge all cache records
 */
case 'expunge':
    $expire = strtotime(!empty($opts[1]) ? $opts[1] : 'now - 10 days');
    $sql_add = " AND created <= '" . date('Y-m-d 00:00:00', $expire) . "'";
    if ($opts['limit']) {
        $sql_add .= ' LIMIT ' . intval($opts['limit']);
    }

case 'clear':
    // connect to database
    $db = $rcmail->get_dbh();
    $db->db_connect('w');
    if (!$db->is_connected() || $db->is_error())
        die("No DB connection\n");

    $folder_types = $opts['type'] ? explode(',', $opts['type']) : array('contact','distribution-list','event','task','configuration');
    $folder_types_db = array_map(array($db, 'quote'), $folder_types);

    if ($opts['all']) {
        $sql_query = "DELETE FROM kolab_cache WHERE type IN (" . join(',', $folder_types_db) . ")";
    }
    else if ($opts['user']) {
        $sql_query = "DELETE FROM kolab_cache WHERE type IN (" . join(',', $folder_types_db) . ") AND resource LIKE ?";
    }

    if ($sql_query) {
        $db->query($sql_query . $sql_add, resource_prefix($opts).'%');
        echo $db->affected_rows() . " records deleted from 'kolab_cache'\n";
    }
    break;


/*
 * Prewarm cache by synchronizing objects for the given user
 */
case 'prewarm':
    // TODO: implement this
    break;


/*
 * Unknown action => show usage
 */
default:
    print_usage();
    exit;
}


/**
 * Compose cache resource URI prefix for the given user credentials
 */
function resource_prefix($opts)
{
    return 'imap://' . urlencode($opts['user']) . '@' . $opts['host'] . '/';
}


/**
 * 
 */
function authenticate(&$opts)
{
    global $rcmail;
    
    // prompt for password
    if (empty($opts['password']) && $opts['user']) {
        $opts['password'] = prompt_silent("Password: ");
    }

    $auth = $rcmail->plugins->exec_hook('authenticate', array(
        'host' => trim($opts['host']),
        'user' => trim($opts['user']),
        'pass' => $opts['password'],
        'cookiecheck' => false,
        'valid' => !empty($opts['user']) && !empty($opts['host']),
    ));

    if ($auth['valid']) {
        if (!$rcmail->login($auth['user'], $auth['pass'], $auth['host'])) {
            die("Login to IMAP server failed!\n");
        }
        else if ($opts['verbose']) {
            echo "IMAP login succeeded.\n";
        }
    }
    else {
        die("Invalid login credentials!\n");
    }

    return $auth['valid'];
}

