<?php

/* Configuration for libkolab */

// Enable caching of Kolab objects in local database
$rcmail_config['kolab_cache'] = true;

// Specify format version to write Kolab objects (must be a string value!)
$rcmail_config['kolab_format_version']  = '3.0';

// Optional override of the URL to read and trigger Free/Busy information of Kolab users
// Defaults to https://<imap-server->/freebusy
$rcmail_config['kolab_freebusy_server'] = 'https://<some-host>/<freebusy-path>';

// Enables listing of only subscribed folders. This e.g. will limit
// folders in calendar view or available addressbooks
$rcmail_config['kolab_use_subscriptions'] = false;

// Enables the use of displayname folder annotations as introduced in KEP:?
// for displaying resource folder names (experimental!)
$rcmail_config['kolab_custom_display_names'] = false;

// Configuration of HTTP requests.
// See http://pear.php.net/manual/en/package.http.http-request2.config.php
// for list of supported configuration options (array keys)
$rcmail_config['kolab_http_request'] = array();

// When kolab_cache is enabled Roundcube's messages cache will be redundant
// when working on kolab folders. Here we can:
// 2 - bypass messages/indexes cache completely
// 1 - bypass only messages, but use index cache
$rcmail_config['kolab_messages_cache_bypass'] = 0;
