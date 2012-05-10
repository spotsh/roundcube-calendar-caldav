<?php

/**
 * Kolab storage class providing static methods to access groupware objects on a Kolab server.
 *
 * @version @package_version@
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

class kolab_storage
{
    const CTYPE_KEY = '/shared/vendor/kolab/folder-type';
    const SERVERSIDE_SUBSCRIPTION = 0;
    const CLIENTSIDE_SUBSCRIPTION = 1;

    public static $last_error;

    private static $ready = false;
    private static $config;
    private static $cache;
    private static $imap;


    /**
     * Setup the environment needed by the libs
     */
    public static function setup()
    {
        if (self::$ready)
            return true;

        $rcmail = rcube::get_instance();
        self::$config = $rcmail->config;
        self::$imap = $rcmail->get_storage();
        self::$ready = class_exists('kolabformat') &&
            (self::$imap->get_capability('METADATA') || self::$imap->get_capability('ANNOTATEMORE') || self::$imap->get_capability('ANNOTATEMORE2'));

        if (self::$ready) {
            // set imap options
            self::$imap->set_options(array(
                'skip_deleted' => true,
                'threading' => false,
            ));
            self::$imap->set_pagesize(9999);
        }

        return self::$ready;
    }


    /**
     * Get a list of storage folders for the given data type
     *
     * @param string Data type to list folders for (contact,distribution-list,event,task,note)
     *
     * @return array List of Kolab_Folder objects (folder names in UTF7-IMAP)
     */
    public static function get_folders($type)
    {
        $folders = array();

        if (self::setup()) {
            foreach ((array)self::$imap->list_folders('', '*', $type) as $foldername) {
                $folders[$foldername] = new kolab_storage_folder($foldername, self::$imap);
            }
        }

        return $folders;
    }


    /**
     * Getter for a specific storage folder
     *
     * @param string  IMAP folder to access (UTF7-IMAP)
     * @return object kolab_storage_folder  The folder object
     */
    public static function get_folder($folder)
    {
        return self::setup() ? new kolab_storage_folder($folder, null, self::$imap) : null;
    }


    /**
     * Getter for a single Kolab object, identified by its UID.
     * This will search all folders storing objects of the given type.
     *
     * @param string Object UID
     * @param string Object type (contact,distribution-list,event,task,note)
     * @return array The Kolab object represented as hash array or false if not found
     */
    public static function get_object($uid, $type)
    {
        self::setup();
        $folder = null;
        foreach ((array)self::$imap->list_folders('', '*', $type) as $foldername) {
            if (!$folder)
                $folder = new kolab_storage_folder($foldername, self::$imap);
            else
                $folder->set_folder($foldername);

            if ($object = $folder->get_object($uid))
                return $object;
        }

        return false;
    }


    /**
     *
     */
    public static function get_freebusy_server()
    {
        return unslashify(self::$config->get('kolab_freebusy_server', 'https://' . $_SESSION['imap_host'] . '/freebusy'));
    }


    /**
     * Compose an URL to query the free/busy status for the given user
     */
    public static function get_freebusy_url($email)
    {
        return self::get_freebusy_server() . '/' . $email . '.ifb';
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
        return asciiwords(strtr($folder, '/.-', '___'));
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
        self::setup();

        $success = self::$imap->delete_folder($name);
        self::$last_error = self::$imap->get_error_str();

        return $success;
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
        self::setup();

        if (self::$imap->create_folder($name)) {
            // set metadata for folder type
            $ctype = $type . ($default ? '.default' : '');
            $saved = self::$imap->set_metadata($name, array(self::CTYPE_KEY => $ctype));

            if ($saved)
                return true;
            else  // revert if metadata could not be set
                self::$imap->delete_folder($name);
        }

        self::$last_error = self::$imap->get_error_str();
        return false;
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
        self::setup();

        $success = self::$imap->rename_folder($oldname, $newname);
        self::$last_error = self::$imap->get_error_str();

        return $success;
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
        self::setup();

        $found     = false;
        $namespace = self::$imap->get_namespace();

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
            $delim = self::$imap->get_hierarchy_delimiter();

        $folder = rcube_charset::convert($folder, 'UTF7-IMAP');
        $folder = str_replace($delim, ' &raquo; ', $folder);

        if ($prefix)
            $folder = $prefix . ' ' . $folder;

        if (!$folder_ns)
            $folder_ns = 'personal';

        return $folder;
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

        $delim = self::$imap->get_hierarchy_delimiter();
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
            else if ($c_folder->get_owner() != $_SESSION['username']) {
                $rights = $c_folder->get_myrights();
                if (!preg_match('/[ck]/', $rights)) {
                    continue;
                }
            }

            $names[$name] = rcube_charset::convert($name, 'UTF7-IMAP');
        }

        // Make sure parent folder is listed (might be skipped e.g. if it's namespace root)
        if ($p_len && !isset($names[$parent])) {
            $names[$parent] = rcube_charset::convert($parent, 'UTF7-IMAP');
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
