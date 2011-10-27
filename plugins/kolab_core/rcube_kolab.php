<?php

/**
 * Utilities providing Kolab functionality using Kolab_* classes
 * from the Horde project.
 *
 * @author Thomas Bruederli <roundcube@gmail.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011, Kolab Systems AG <contact@kolabsys.com>
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

ini_set('error_reporting', E_ALL&~(E_DEPRECATED | E_NOTICE));

require_once 'Horde/Kolab/Storage/List.php';
require_once 'Horde/Kolab/Format.php';
require_once 'Horde/Auth.php';
require_once 'Horde/Auth/kolab.php';
require_once 'Horde/Perms.php';

/**
 * Glue class to handle access to the Kolab data using the Kolab_* classes
 * from the Horde project.
 */
class rcube_kolab
{
    public static $last_error;

    private static $horde_auth;
    private static $config;
    private static $ready = false;
    private static $list;
    private static $cache;


    /**
     * Setup the environment needed by the Kolab_* classes to access Kolab data
     */
    public static function setup()
    {
        global $conf;

        // setup already done
        if (self::$horde_auth)
            return;

        $rcmail = rcmail::get_instance();

        // get password of logged user
        $pwd = $rcmail->decrypt($_SESSION['password']);

        // load ldap credentials from local config
        $conf['kolab'] = (array) $rcmail->config->get('kolab');

        // Set global Horde config (e.g. Cache settings)
        if (!empty($conf['kolab']['global'])) {
            $conf = array_merge($conf, $conf['kolab']['global']);
            unset($conf['kolab']['global']);
        }

        // Set Horde configuration (for cache db)
        $dsnw = $rcmail->config->get('db_dsnw');
        $dsnr = $rcmail->config->get('db_dsnr');

        $conf['sql'] = MDB2::parseDSN($dsnw);
        $conf['sql']['charset'] = 'utf-8';

        if (!empty($dsnr) && $dsnr != $dsnw) {
            $conf['sql']['read'] = MDB2::parseDSN($dsnr);
            $conf['sql']['read']['charset'] = 'utf-8';
            $conf['sql']['splitread'] = true;
        }

        // Re-set LDAP/IMAP host config
        $ldap = array('server' => 'ldap://' . $_SESSION['imap_host'] . ':389');
        $imap = array('server' => $_SESSION['imap_host'], 'port' => $_SESSION['imap_port']);
        $freebusy = array('server' => 'https://' . $_SESSION['imap_host'] . '/freebusy');

        $conf['kolab']['ldap'] = array_merge($ldap, (array)$conf['kolab']['ldap']);
        $conf['kolab']['imap'] = array_merge($imap, (array)$conf['kolab']['imap']);
        $conf['kolab']['freebusy'] = array_merge($freebusy, (array)$conf['kolab']['freebusy']);
        self::$config = &$conf;

        // pass the current IMAP authentication credentials to the Horde auth system
        self::$horde_auth = Auth::singleton('kolab');

        $username    = $_SESSION['username'];
        $credentials = array('password' => $pwd);

        // Hack proxy auth for "Login As" feature of kolab_auth plugin
        if (!empty($_SESSION['kolab_auth_admin'])) {
            $username = $_SESSION['kolab_auth_admin'];
            $conf['kolab']['imap']['user'] = $_SESSION['username'];
            $conf['kolab']['imap']['authuser'] = $rcmail->decrypt($_SESSION['kolab_auth_login']);
            $conf['kolab']['imap']['password'] = $rcmail->decrypt($_SESSION['kolab_auth_password']);
            $conf['kolab']['user_mail'] = $_SESSION['username'];
        }

        if (self::$horde_auth->isAuthenticated()) {
            self::$ready = true;
        }
        else if (self::$horde_auth->authenticate($username, $credentials, false)) {
            // we could use Auth::setAuth() here, but it requires the whole bunch
            // of includes and global objects, do it as simple as possible
            $_SESSION['__auth'] = array(
                'authenticated' => true,
                'userId' => $_SESSION['username'],
                'timestamp' => time(),
                'remote_addr' => $_SERVER['REMOTE_ADDR'],
            );
            Auth::setCredential('password', $pwd);
            self::$ready = true;
        }
        else {
            raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => sprintf("Unable to authenticate user %s!", $_SESSION['username'])),
                true, true);
        }

        // Register shutdown function for saving cache/session objects
        $rcmail->add_shutdown_function(array('rcube_kolab', 'shutdown'));

        NLS::setCharset('UTF-8');
        String::setDefaultCharset('UTF-8');
    }

    /**
     * Get instance of Kolab_List object
     *
     * @return object Kolab_List Folders list object
     */
    public static function get_folders_list()
    {
        self::setup();

        if (self::$list)
            return self::$list;

        if (!self::$ready)
            return null;

        $rcmail = rcmail::get_instance();
        $imap_cache = $rcmail->config->get('imap_cache');

        if ($imap_cache) {
            self::$cache = $rcmail->get_cache('IMAP', $imap_cache);
            self::$list  = self::$cache->get('mailboxes.kolab');

            // Disable Horde folders caching, we're using our own cache
            self::$config['kolab']['imap']['cache_folders'] = false;
        }

        if (empty(self::$list)) {
            self::$list = Kolab_List::singleton();
        }

        return self::$list;
    }

    /**
     * Get instance of a Kolab (XML) format object
     *
     * @param string Data type (contact,event,task,note)
     *
     * @return object Horde_Kolab_Format_XML The format object
     */
    public static function get_format($type)
    {
      self::setup();
      return Horde_Kolab_Format::factory('XML', $type);
    }

    /**
     * Get a list of storage folders for the given data type
     *
     * @param string Data type to list folders for (contact,event,task,note)
     *
     * @return array List of Kolab_Folder objects (folder names in UTF7-IMAP)
     */
    public static function get_folders($type)
    {
        $kolab = self::get_folders_list();
        return self::$ready ? $kolab->getByType($type) : array();
    }

    /**
     * Getter for a specific storage folder
     *
     * @param string  IMAP folder to access (UTF7-IMAP)
     * @return object Kolab_Folder  The folder object
     */
    public static function get_folder($folder)
    {
        $kolab = self::get_folders_list();
        return self::$ready ? $kolab->getFolder($folder) : null;
    }

    /**
     * Checks if the given folder is subscribed.
     * Much nicer would be if the Kolab_Folder object could tell this information
     *
     * @param string Full IMAP folder name
     * @return boolean True if in the list of subscribed folders, False if not
     */
    public static function is_subscribed($folder)
    {
      static $subscribed;  // local cache

      if (!$subscribed) {
        $rcmail = rcmail::get_instance();
        // try without connection first (list could be served from cache)
        $subscribed = $rcmail->imap ? $rcmail->imap->list_mailboxes() : array();

        // now really get the list from the IMAP server
        if (empty($subscribed) || $subscribed == array('INBOX')) {
          $rcmail->imap_connect();
          $subscribed = $rcmail->imap->list_mailboxes();
        }
      }

      return in_array($folder, $subscribed);
    }

    /**
     * Get storage object for read/write access to the Kolab backend
     *
     * @param string IMAP folder to access (UTF7-IMAP)
     * @param string Object type to deal with (leave empty for auto-detection using annotations)
     *
     * @return object Kolab_Data The data storage object
     */
    public static function get_storage($folder, $data_type = null)
    {
        $kolab = self::get_folders_list();
        return self::$ready ? $kolab->getFolder($folder)->getData($data_type) : null;
    }

    /**
     * Compose an URL to query the free/busy status for the given user
     */
    public static function get_freebusy_url($email)
    {
        return unslashify(self::$config['kolab']['freebusy']['server']) . '/' . $email . '.ifb';
    }

    /**
     * Do session/cache operations on shutdown
     */
    public static function shutdown()
    {
        if (self::$ready) {
            // Horde's shutdown function doesn't work with Roundcube session
            // save Horde_Kolab_Session object in session here
            require_once 'Horde/SessionObjects.php';
            $session = Horde_SessionObjects::singleton();
            $kolab   = Horde_Kolab_Session::singleton();
            $session->overwrite('kolab_session', $kolab, false);

            // Write Kolab_List object to cache
            if (self::$cache && self::$list) {
                self::$cache->set('mailboxes.kolab', self::$list);
            }
        }
    }

    /**
     * Creates folder ID from folder name
     *
     * @param string $folder Folder name (UTF7-IMAP)
     *
     * @return string Folder ID string
     */
    public static function folder_id($folder)
    {
        return asciiwords(strtr($folder, '/.', '--'));
    }

    /**
     * Deletes IMAP folder
     *
     * @param string $name Folder name (UTF7-IMAP)
     *
     * @return bool True on success, false on failure
     */
    public static function folder_delete($name)
    {
        $kolab = self::get_folders_list();

        $folder = $kolab->getFolder($name);
        $result = $folder->delete();

        if (is_object($result) && is_a($result, 'PEAR_Error')) {
            self::$last_error = $result->getMessage();
            return false;
        }

        return true;
    }

    /**
     * Creates IMAP folder
     *
     * @param string $name    Folder name (UTF7-IMAP)
     * @param string $type    Folder type
     * @param bool   $default True if older is default (for specified type)
     *
     * @return bool True on success, false on failure
     */
    public static function folder_create($name, $type=null, $default=false)
    {
        $kolab = self::get_folders_list();

        $folder = new Kolab_Folder();
        $folder->setList($kolab);
        $folder->setFolder($name);

        $result = $folder->save(array(
            'type' => $type,
            'default' => $default,
        ));

        if (is_object($result) && is_a($result, 'PEAR_Error')) {
            self::$last_error = $result->getMessage();
            return false;
        }

        return true;
    }

    /**
     * Renames IMAP folder
     *
     * @param string $oldname Old folder name (UTF7-IMAP)
     * @param string $newname New folder name (UTF7-IMAP)
     *
     * @return bool True on success, false on failure
     */
    public static function folder_rename($oldname, $newname)
    {
        $kolab = self::get_folders_list();

        $folder = $kolab->getFolder($oldname);
        $folder->setFolder($newname);

        $result = $kolab->rename($folder);
        if (is_object($result) && is_a($result, 'PEAR_Error')) {
            self::$last_error = $result->getMessage();
            return false;
        }

        // @TODO: Horde doesn't update subfolders cache nor subscriptions
        //        but we cannot use Roundcube imap object here, because
        //        when two connections are used in one request and we have
        //        multi-server configuration, updating the cache after all
        //        would get wrong information (e.g. annotations)

        // Reset the List object and cache
        $kolab = null;
        if (self::$cache) {
            self::$list = null;
            self::$cache->remove('mailboxes', true);
        }

        return true;
    }

    /**
     * Getter for human-readable name of Kolab object (folder)
     * See http://wiki.kolab.org/UI-Concepts/Folder-Listing for reference
     *
     * @param string $folder    IMAP folder name (UTF7-IMAP)
     * @param string $folder_ns Will be set to namespace name of the folder
     *
     * @return string Name of the folder-object
     */
    public static function object_name($folder, &$folder_ns=null)
    {
        $rcmail = rcmail::get_instance();
        $rcmail->imap_init();

        $namespace = $rcmail->imap->get_namespace();
        $found     = false;

        if (!empty($namespace['shared'])) {
            foreach ($namespace['shared'] as $ns) {
                if (strlen($ns[0]) && strpos($folder, $ns[0]) === 0) {
                    $prefix = '';
                    $folder = substr($folder, strlen($ns[0]));
                    $delim  = $ns[1];
                    $found  = true;
                    $folder_ns = 'shared';
                    break;
                }
            }
        }
        if (!$found && !empty($namespace['other'])) {
            foreach ($namespace['other'] as $ns) {
                if (strlen($ns[0]) && strpos($folder, $ns[0]) === 0) {
                    // remove namespace prefix
                    $folder = substr($folder, strlen($ns[0]));
                    $delim  = $ns[1];
                    // get username
                    $pos    = strpos($folder, $delim);
                    if ($pos) {
                        $prefix = '('.substr($folder, 0, $pos).') ';
                        $folder = substr($folder, $pos+1);
                    }
                    else {
                        $prefix = '('.$folder.')';
                        $folder = '';
                    }
                    $found  = true;
                    $folder_ns = 'other';
                    break;
                }
            }
        }
        if (!$found && !empty($namespace['personal'])) {
            foreach ($namespace['personal'] as $ns) {
                if (strlen($ns[0]) && strpos($folder, $ns[0]) === 0) {
                    // remove namespace prefix
                    $folder = substr($folder, strlen($ns[0]));
                    $prefix = '';
                    $delim  = $ns[1];
                    $found  = true;
                    break;
                }
            }
        }

        if (empty($delim))
            $delim = $rcmail->imap->get_hierarchy_delimiter();

        $folder = rcube_charset_convert($folder, 'UTF7-IMAP');
        $folder = str_replace($delim, ' &raquo; ', $folder);

        if ($prefix)
            $folder = $prefix . ' ' . $folder;

        if (!$folder_ns)
            $folder_ns = 'personal';

        return $folder;
    }

    /**
     * Getter for the name of the namespace to which the IMAP folder belongs
     *
     * @param string $folder    IMAP folder name (UTF7-IMAP)
     *
     * @return string Name of the namespace (personal, other, shared)
     */
    public static function folder_namespace($folder)
    {
        $rcmail = rcmail::get_instance();
        $rcmail->imap_init();

        $namespace = $rcmail->imap->get_namespace();

        if (!empty($namespace)) {
            foreach ($namespace as $nsname => $nsvalue) {
                if (in_array($nsname, array('personal', 'other', 'shared')) && !empty($nsvalue)) {
                    foreach ($nsvalue as $ns) {
                        if (strlen($ns[0]) && strpos($folder, $ns[0]) === 0) {
                            return $namespace = $nsname;
                        }
                    }
                }
            }
        }

        return 'personal';
    }

    /**
     * Creates a SELECT field with folders list
     *
     * @param string $type    Folder type
     * @param array  $attrs   SELECT field attributes (e.g. name)
     * @param string $current The name of current folder (to skip it)
     *
     * @return html_select SELECT object
     */
    public static function folder_selector($type, $attrs, $current = '')
    {
        // get all folders of specified type
        $folders = self::get_folders($type);

        $delim = $_SESSION['imap_delimiter'];
        $names = array();
        $len   = strlen($current);

        if ($len && ($rpos = strrpos($current, $delim))) {
            $parent = substr($current, 0, $rpos);
            $p_len  = strlen($parent);
        }

        // Filter folders list
        foreach ($folders as $c_folder) {
            $name = $c_folder->name;
            // skip current folder and it's subfolders
            if ($len && ($name == $current || strpos($name, $current.$delim) === 0)) {
                continue;
            }

            // always show the parent of current folder
            if ($p_len && $name == $parent) { }
            // skip folders where user have no rights to create subfolders
            else if ($c_folder->getOwner() != $_SESSION['username']) {
                $rights = $c_folder->getMyRights();
                if (PEAR::IsError($rights) || !preg_match('/[ck]/', $rights)) {
                    continue;
                }
            }

            $names[$name] = rcube_charset_convert($name, 'UTF7-IMAP');
        }

        // Make sure parent folder is listed (might be skipped e.g. if it's namespace root)
        if ($p_len && !isset($names[$parent])) {
            $names[$parent] = rcube_charset_convert($parent, 'UTF7-IMAP');
        }

        // Sort folders list
        asort($names, SORT_LOCALE_STRING);

        $folders = array_keys($names);
        $names   = array();

        // Build SELECT field of parent folder
        $select = new html_select($attrs);
        $select->add('---', '');

        foreach ($folders as $name) {
            $imap_name = $name;
            $name      = $origname = self::object_name($name);

            // find folder prefix to truncate
            for ($i = count($names)-1; $i >= 0; $i--) {
                if (strpos($name, $names[$i].' &raquo; ') === 0) {
                    $length = strlen($names[$i].' &raquo; ');
                    $prefix = substr($name, 0, $length);
                    $count  = count(explode(' &raquo; ', $prefix));
                    $name   = str_repeat('&nbsp;&nbsp;', $count-1) . '&raquo; ' . substr($name, $length);
                    break;
                }
            }

            $names[] = $origname;

            $select->add($name, $imap_name);
        }

        return $select;
    }

}
