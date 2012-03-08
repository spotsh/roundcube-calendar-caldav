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
            return;

        $rcmail = rcmail::get_instance();
        self::$config = $rcmail->config;
        self::$imap = $rcmail->get_storage();
        self::$ready = class_exists('kolabformat') && $rcmail->storage_connect() &&
            (self::$imap->get_capability('METADATA') || self::$imap->get_capability('ANNOTATEMORE') || self::$imap->get_capability('ANNOTATEMORE2'));

        if (self::$ready) {
            // set imap options
            self::$imap->set_options(array(
                'skip_deleted' => true,
                'threading' => false,
            ));
            self::$imap->set_pagesize(9999);
        }
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
        self::setup();
        $folders = array();

        if (self::$ready) {
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
     * @return object Kolab_Folder  The folder object
     */
    public static function get_folder($folder)
    {
        self::setup();
        return self::$ready ? new kolab_storage_folder($folder, null, self::$imap) : null;
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
        // TODO: implement this


        // Build SELECT field of parent folder
        $select = new html_select($attrs);
        $select->add('---', '');


        return $select;
    }
}
