<?php

/**
 * Kolab storage class providing static methods to access groupware objects on a Kolab server.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
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
    const CTYPE_KEY_PRIVATE = '/private/vendor/kolab/folder-type';
    const COLOR_KEY_SHARED  = '/shared/vendor/kolab/color';
    const COLOR_KEY_PRIVATE = '/private/vendor/kolab/color';
    const NAME_KEY_SHARED   = '/shared/vendor/kolab/displayname';
    const NAME_KEY_PRIVATE  = '/private/vendor/kolab/displayname';
    const UID_KEY_SHARED    = '/shared/vendor/kolab/uniqueid';
    const UID_KEY_PRIVATE   = '/private/vendor/kolab/uniqueid';
    const UID_KEY_CYRUS     = '/shared/vendor/cmu/cyrus-imapd/uniqueid';

    public static $version = '3.0';
    public static $last_error;

    private static $ready = false;
    private static $subscriptions;
    private static $states;
    private static $config;
    private static $imap;

    // Default folder names
    private static $default_folders = array(
        'event'         => 'Calendar',
        'contact'       => 'Contacts',
        'task'          => 'Tasks',
        'note'          => 'Notes',
        'file'          => 'Files',
        'configuration' => 'Configuration',
        'journal'       => 'Journal',
        'mail.inbox'       => 'INBOX',
        'mail.drafts'      => 'Drafts',
        'mail.sentitems'   => 'Sent',
        'mail.wastebasket' => 'Trash',
        'mail.outbox'      => 'Outbox',
        'mail.junkemail'   => 'Junk',
    );


    /**
     * Setup the environment needed by the libs
     */
    public static function setup()
    {
        if (self::$ready)
            return true;

        $rcmail = rcube::get_instance();
        self::$config = $rcmail->config;
        self::$version = strval($rcmail->config->get('kolab_format_version', self::$version));
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
        else if (!class_exists('kolabformat')) {
            rcube::raise_error(array(
                'code' => 900, 'type' => 'php',
                'message' => "required kolabformat module not found"
            ), true);
        }
        else {
            rcube::raise_error(array(
                'code' => 900, 'type' => 'php',
                'message' => "IMAP server doesn't support METADATA or ANNOTATEMORE"
            ), true);
        }

        return self::$ready;
    }


    /**
     * Get a list of storage folders for the given data type
     *
     * @param string Data type to list folders for (contact,distribution-list,event,task,note)
     * @param boolean Enable to return subscribed folders only (null to use configured subscription mode)
     *
     * @return array List of Kolab_Folder objects (folder names in UTF7-IMAP)
     */
    public static function get_folders($type, $subscribed = null)
    {
        $folders = $folderdata = array();

        if (self::setup()) {
            foreach ((array)self::list_folders('', '*', $type, $subscribed, $folderdata) as $foldername) {
                $folders[$foldername] = new kolab_storage_folder($foldername, $folderdata[$foldername]);
            }
        }

        return $folders;
    }

    /**
     * Getter for the storage folder for the given type
     *
     * @param string Data type to list folders for (contact,distribution-list,event,task,note)
     * @return object kolab_storage_folder  The folder object
     */
    public static function get_default_folder($type)
    {
        if (self::setup()) {
            foreach ((array)self::list_folders('', '*', $type . '.default', false, $folderdata) as $foldername) {
                return new kolab_storage_folder($foldername, $folderdata[$foldername]);
            }
        }

        return null;
    }


    /**
     * Getter for a specific storage folder
     *
     * @param string  IMAP folder to access (UTF7-IMAP)
     * @return object kolab_storage_folder  The folder object
     */
    public static function get_folder($folder)
    {
        return self::setup() ? new kolab_storage_folder($folder) : null;
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
        foreach ((array)self::list_folders('', '*', $type) as $foldername) {
            if (!$folder)
                $folder = new kolab_storage_folder($foldername);
            else
                $folder->set_folder($foldername);

            if ($object = $folder->get_object($uid, '*'))
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
        // clear cached entries first
        if ($folder = self::get_folder($name))
            $folder->cache->purge();

        $success = self::$imap->delete_folder($name);
        self::$last_error = self::$imap->get_error_str();

        return $success;
    }

    /**
     * Creates IMAP folder
     *
     * @param string $name       Folder name (UTF7-IMAP)
     * @param string $type       Folder type
     * @param bool   $subscribed Sets folder subscription
     * @param bool   $active     Sets folder state (client-side subscription)
     *
     * @return bool True on success, false on failure
     */
    public static function folder_create($name, $type = null, $subscribed = false, $active = false)
    {
        self::setup();

        if ($saved = self::$imap->create_folder($name, $subscribed)) {
            // set metadata for folder type
            if ($type) {
                $saved = self::set_folder_type($name, $type);

                // revert if metadata could not be set
                if (!$saved) {
                    self::$imap->delete_folder($name);
                }
                // activate folder
                else if ($active) {
                    self::set_state($name, true);
                }
            }
        }

        if ($saved) {
            return true;
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

        $active = self::folder_is_active($oldname);
        $success = self::$imap->rename_folder($oldname, $newname);
        self::$last_error = self::$imap->get_error_str();

        // pass active state to new folder name
        if ($success && $active) {
            self::set_state($oldnam, false);
            self::set_state($newname, true);
        }

        return $success;
    }


    /**
     * Rename or Create a new IMAP folder.
     *
     * Does additional checks for permissions and folder name restrictions
     *
     * @param array Hash array with folder properties and metadata
     *  - name:       Folder name
     *  - oldname:    Old folder name when changed
     *  - parent:     Parent folder to create the new one in
     *  - type:       Folder type to create
     *  - subscribed: Subscribed flag (IMAP subscription)
     *  - active:     Activation flag (client-side subscription)
     * @return mixed New folder name or False on failure
     */
    public static function folder_update(&$prop)
    {
        self::setup();

        $folder    = rcube_charset::convert($prop['name'], RCUBE_CHARSET, 'UTF7-IMAP');
        $oldfolder = $prop['oldname']; // UTF7
        $parent    = $prop['parent']; // UTF7
        $delimiter = self::$imap->get_hierarchy_delimiter();

        if (strlen($oldfolder)) {
            $options = self::$imap->folder_info($oldfolder);
        }

        if (!empty($options) && ($options['norename'] || $options['protected'])) {
        }
        // sanity checks (from steps/settings/save_folder.inc)
        else if (!strlen($folder)) {
            self::$last_error = 'cannotbeempty';
            return false;
        }
        else if (strlen($folder) > 128) {
            self::$last_error = 'nametoolong';
            return false;
        }
        else {
            // these characters are problematic e.g. when used in LIST/LSUB
            foreach (array($delimiter, '%', '*') as $char) {
                if (strpos($folder, $char) !== false) {
                    self::$last_error = 'forbiddencharacter';
                    return false;
                }
            }
        }

        if (!empty($options) && ($options['protected'] || $options['norename'])) {
            $folder = $oldfolder;
        }
        else if (strlen($parent)) {
            $folder = $parent . $delimiter . $folder;
        }
        else {
            // add namespace prefix (when needed)
            $folder = self::$imap->mod_folder($folder, 'in');
        }

        // Check access rights to the parent folder
        if (strlen($parent) && (!strlen($oldfolder) || $oldfolder != $folder)) {
            $parent_opts = self::$imap->folder_info($parent);
            if ($parent_opts['namespace'] != 'personal'
                && (empty($parent_opts['rights']) || !preg_match('/[ck]/', implode($parent_opts['rights'])))
            ) {
                self::$last_error = 'No permission to create folder';
                return false;
          }
        }

        // update the folder name
        if (strlen($oldfolder)) {
            if ($oldfolder != $folder) {
                $result = self::folder_rename($oldfolder, $folder);
          }
          else
              $result = true;
        }
        // create new folder
        else {
            $result = self::folder_create($folder, $prop['type'], $prop['subscribed'], $prop['active']);
        }

        if ($result) {
            self::set_folder_props($folder, $prop);
        }

        return $result ? $folder : false;
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

        // find custom display name in folder METADATA
        if ($name = self::custom_displayname($folder)) {
            return $name;
        }

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
        $folder = html::quote($folder);
        $folder = str_replace(html::quote($delim), ' &raquo; ', $folder);

        if ($prefix)
            $folder = html::quote($prefix) . ' ' . $folder;

        if (!$folder_ns)
            $folder_ns = 'personal';

        return $folder;
    }

    /**
     * Get custom display name (saved in metadata) for the given folder
     */
    public static function custom_displayname($folder)
    {
      // find custom display name in folder METADATA
      if (self::$config->get('kolab_custom_display_names', true)) {
          $metadata = self::$imap->get_metadata($folder, array(self::NAME_KEY_PRIVATE, self::NAME_KEY_SHARED));
          if (($name = $metadata[$folder][self::NAME_KEY_PRIVATE]) || ($name = $metadata[$folder][self::NAME_KEY_SHARED])) {
              return $name;
          }
      }

      return false;
    }

    /**
     * Helper method to generate a truncated folder name to display
     */
    public static function folder_displayname($origname, &$names)
    {
        $name = $origname;

        // find folder prefix to truncate
        for ($i = count($names)-1; $i >= 0; $i--) {
            if (strpos($name, $names[$i] . ' &raquo; ') === 0) {
                $length = strlen($names[$i] . ' &raquo; ');
                $prefix = substr($name, 0, $length);
                $count  = count(explode(' &raquo; ', $prefix));
                $name   = str_repeat('&nbsp;&nbsp;&nbsp;', $count-1) . '&raquo; ' . substr($name, $length);
                break;
            }
        }
        $names[] = $origname;

        return $name;
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
        $folders = self::get_folders($type, false);

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

            $names[$name] = self::object_name($name);
        }

        // Make sure parent folder is listed (might be skipped e.g. if it's namespace root)
        if ($p_len && !isset($names[$parent])) {
            $names[$parent] = self::object_name($parent);
        }

        // Sort folders list
        asort($names, SORT_LOCALE_STRING);

        // Build SELECT field of parent folder
        $attrs['is_escaped'] = true;
        $select = new html_select($attrs);
        $select->add('---', '');

        $listnames = array();
        foreach (array_keys($names) as $imap_name) {
            $name = $origname = $names[$imap_name];

            // find folder prefix to truncate
            for ($i = count($listnames)-1; $i >= 0; $i--) {
                if (strpos($name, $listnames[$i].' &raquo; ') === 0) {
                    $length = strlen($listnames[$i].' &raquo; ');
                    $prefix = substr($name, 0, $length);
                    $count  = count(explode(' &raquo; ', $prefix));
                    $name   = str_repeat('&nbsp;&nbsp;', $count-1) . '&raquo; ' . substr($name, $length);
                    break;
                }
            }

            $listnames[] = $origname;
            $select->add($name, $imap_name);
        }

        return $select;
    }


    /**
     * Returns a list of folder names
     *
     * @param string  Optional root folder
     * @param string  Optional name pattern
     * @param string  Data type to list folders for (contact,distribution-list,event,task,note,mail)
     * @param boolean Enable to return subscribed folders only (null to use configured subscription mode)
     * @param array   Will be filled with folder-types data
     *
     * @return array List of folders
     */
    public static function list_folders($root = '', $mbox = '*', $filter = null, $subscribed = null, &$folderdata = array())
    {
        if (!self::setup()) {
            return null;
        }

        // use IMAP subscriptions
        if ($subscribed === null && self::$config->get('kolab_use_subscriptions')) {
            $subscribed = true;
        }

        if (!$filter) {
            // Get ALL folders list, standard way
            if ($subscribed) {
                return self::$imap->list_folders_subscribed($root, $mbox);
            }
            else {
                return self::$imap->list_folders($root, $mbox);
            }
        }

        $prefix = $root . $mbox;
        $regexp = '/^' . preg_quote($filter, '/') . '(\..+)?$/';

        // get folders types
        $folderdata = self::folders_typedata($prefix);

        if (!is_array($folderdata)) {
            return array();
        }

        // In some conditions we can skip LIST command (?)
        if (!$subscribed && $filter != 'mail' && $prefix == '*') {
            foreach ($folderdata as $folder => $type) {
                if (!preg_match($regexp, $type)) {
                    unset($folderdata[$folder]);
                }
            }
            return array_keys($folderdata);
        }

        // Get folders list
        if ($subscribed) {
            $folders = self::$imap->list_folders_subscribed($root, $mbox);
        }
        else {
            $folders = self::$imap->list_folders($root, $mbox);
        }

        // In case of an error, return empty list (?)
        if (!is_array($folders)) {
            return array();
        }

        // Filter folders list
        foreach ($folders as $idx => $folder) {
            $type = $folderdata[$folder];

            if ($filter == 'mail' && empty($type)) {
                continue;
            }
            if (empty($type) || !preg_match($regexp, $type)) {
                unset($folders[$idx]);
            }
        }

        return $folders;
    }


    /**
     * Sort the given list of kolab folders by namespace/name
     *
     * @param array List of kolab_storage_folder objects
     * @return array Sorted list of folders
     */
    public static function sort_folders($folders)
    {
        $delimiter = self::$imap->get_hierarchy_delimiter();
        $nsnames = array('personal' => array(), 'shared' => array(), 'other' => array());
        foreach ($folders as $folder) {
            $folders[$folder->name] = $folder;
            $ns = $folder->get_namespace();
            $level = count(explode($delimiter, $folder->name));
            $nsnames[$ns][$folder->name] = sprintf('%02d-%s', $level, strtolower(html_entity_decode(self::object_name($folder->name, $ns), ENT_COMPAT, RCUBE_CHARSET)));  // decode &raquo;
        }

        $names = array();
        foreach ($nsnames as $ns => $dummy) {
            asort($nsnames[$ns], SORT_LOCALE_STRING);
            $names += $nsnames[$ns];
        }

        $out = array();
        foreach ($names as $utf7name => $name) {
            $out[] = $folders[$utf7name];
        }

        return $out;
    }


    /**
     * Returns folder types indexed by folder name
     *
     * @param string $prefix Folder prefix (Default '*' for all folders)
     *
     * @return array|bool List of folders, False on failure
     */
    public static function folders_typedata($prefix = '*')
    {
        if (!self::setup()) {
            return false;
        }

        $folderdata = self::$imap->get_metadata($prefix, array(self::CTYPE_KEY, self::CTYPE_KEY_PRIVATE));

        if (!is_array($folderdata)) {
            return false;
        }

        return array_map(array('kolab_storage', 'folder_select_metadata'), $folderdata);
    }


    /**
     * Callback for array_map to select the correct annotation value
     */
    public static function folder_select_metadata($types)
    {
        if (!empty($types[self::CTYPE_KEY_PRIVATE])) {
            return $types[self::CTYPE_KEY_PRIVATE];
        }
        else if (!empty($types[self::CTYPE_KEY])) {
            list($ctype, $suffix) = explode('.', $types[self::CTYPE_KEY]);
            return $ctype;
        }
        return null;
    }


    /**
     * Returns type of IMAP folder
     *
     * @param string $folder Folder name (UTF7-IMAP)
     *
     * @return string Folder type
     */
    public static function folder_type($folder)
    {
        self::setup();

        $metadata = self::$imap->get_metadata($folder, array(self::CTYPE_KEY, self::CTYPE_KEY_PRIVATE));

        if (!is_array($metadata)) {
            return null;
        }

        if (!empty($metadata[$folder])) {
            return self::folder_select_metadata($metadata[$folder]);
        }

        return 'mail';
    }


    /**
     * Sets folder content-type.
     *
     * @param string $folder Folder name
     * @param string $type   Content type
     *
     * @return boolean True on success
     */
    public static function set_folder_type($folder, $type='mail')
    {
        self::setup();

        list($ctype, $subtype) = explode('.', $type);

        $success = self::$imap->set_metadata($folder, array(self::CTYPE_KEY => $ctype, self::CTYPE_KEY_PRIVATE => $subtype ? $type : null));

        if (!$success)  // fallback: only set private annotation
            $success |= self::$imap->set_metadata($folder, array(self::CTYPE_KEY_PRIVATE => $type));

        return $success;
    }


    /**
     * Check subscription status of this folder
     *
     * @param string $folder Folder name
     *
     * @return boolean True if subscribed, false if not
     */
    public static function folder_is_subscribed($folder)
    {
        if (self::$subscriptions === null) {
            self::setup();
            self::$subscriptions = self::$imap->list_folders_subscribed();
        }

        return in_array($folder, self::$subscriptions);
    }


    /**
     * Change subscription status of this folder
     *
     * @param string $folder Folder name
     *
     * @return True on success, false on error
     */
    public static function folder_subscribe($folder)
    {
        self::setup();

        if (self::$imap->subscribe($folder)) {
            self::$subscriptions === null;
            return true;
        }

        return false;
    }


    /**
     * Change subscription status of this folder
     *
     * @param string $folder Folder name
     *
     * @return True on success, false on error
     */
    public static function folder_unsubscribe($folder)
    {
        self::setup();

        if (self::$imap->unsubscribe($folder)) {
            self::$subscriptions === null;
            return true;
        }

        return false;
    }


    /**
     * Check activation status of this folder
     *
     * @param string $folder Folder name
     *
     * @return boolean True if active, false if not
     */
    public static function folder_is_active($folder)
    {
        $active_folders = self::get_states();

        return in_array($folder, $active_folders);
    }


    /**
     * Change activation status of this folder
     *
     * @param string $folder Folder name
     *
     * @return True on success, false on error
     */
    public static function folder_activate($folder)
    {
        return self::set_state($folder, true);
    }


    /**
     * Change activation status of this folder
     *
     * @param string $folder Folder name
     *
     * @return True on success, false on error
     */
    public static function folder_deactivate($folder)
    {
        return self::set_state($folder, false);
    }


    /**
     * Return list of active folders
     */
    private static function get_states()
    {
        if (self::$states !== null) {
            return self::$states;
        }

        $rcube   = rcube::get_instance();
        $folders = $rcube->config->get('kolab_active_folders');

        if ($folders !== null) {
            self::$states = !empty($folders) ? explode('**', $folders) : array();
        }
        // for backward-compatibility copy server-side subscriptions to activation states
        else {
            self::setup();
            if (self::$subscriptions === null) {
                self::$subscriptions = self::$imap->list_folders_subscribed();
            }
            self::$states = self::$subscriptions;
            $folders = implode(self::$states, '**');
            $rcube->user->save_prefs(array('kolab_active_folders' => $folders));
        }

        return self::$states;
    }


    /**
     * Update list of active folders
     */
    private static function set_state($folder, $state)
    {
        self::get_states();

        // update in-memory list
        $idx = array_search($folder, self::$states);
        if ($state && $idx === false) {
            self::$states[] = $folder;
        }
        else if (!$state && $idx !== false) {
            unset(self::$states[$idx]);
        }

        // update user preferences
        $folders = implode(self::$states, '**');
        $rcube   = rcube::get_instance();
        return $rcube->user->save_prefs(array('kolab_active_folders' => $folders));
    }

    /**
     * Creates default folder of specified type
     * To be run when none of subscribed folders (of specified type) is found
     *
     * @param string $type  Folder type
     * @param string $props Folder properties (color, etc)
     *
     * @return string Folder name
     */
    public static function create_default_folder($type, $props = array())
    {
        if (!self::setup()) {
            return;
        }

        $folders = self::$imap->get_metadata('*', array(kolab_storage::CTYPE_KEY_PRIVATE));

        // from kolab_folders config
        $folder_type  = strpos($type, '.') ? str_replace('.', '_', $type) : $type . '_default';
        $default_name = self::$config->get('kolab_folders_' . $folder_type);
        $folder_type  = str_replace('_', '.', $folder_type);

        // check if we have any folder in personal namespace
        // folder(s) may exist but not subscribed
        foreach ($folders as $f => $data) {
            if (strpos($data[self::CTYPE_KEY_PRIVATE], $type) === 0) {
                $folder = $f;
                break;
            }
        }

        if (!$folder) {
            if (!$default_name) {
                $default_name = self::$default_folders[$type];
            }

            if (!$default_name) {
                return;
            }

            $folder = rcube_charset::convert($default_name, RCUBE_CHARSET, 'UTF7-IMAP');
            $prefix = self::$imap->get_namespace('prefix');

            // add personal namespace prefix if needed
            if ($prefix && strpos($folder, $prefix) !== 0 && $folder != 'INBOX') {
                $folder = $prefix . $folder;
            }

            if (!self::$imap->folder_exists($folder)) {
                if (!self::$imap->folder_create($folder)) {
                    return;
                }
            }

            self::set_folder_type($folder, $folder_type);
        }

        self::folder_subscribe($folder);

        if ($props['active']) {
            self::set_state($folder, true);
        }

        if (!empty($props)) {
            self::set_folder_props($folder, $props);
        }

        return $folder;
    }

    /**
     * Sets folder metadata properties
     *
     * @param string $folder Folder name
     * @param array  $prop   Folder properties
     */
    public static function set_folder_props($folder, &$prop)
    {
        if (!self::setup()) {
            return;
        }

        // TODO: also save 'showalarams' and other properties here
        $ns        = self::$imap->folder_namespace($folder);
        $supported = array(
            'color'       => array(self::COLOR_KEY_SHARED, self::COLOR_KEY_PRIVATE),
            'displayname' => array(self::NAME_KEY_SHARED, self::NAME_KEY_PRIVATE),
        );

        foreach ($supported as $key => $metakeys) {
            if (array_key_exists($key, $prop)) {
                $meta_saved = false;
                if ($ns == 'personal')  // save in shared namespace for personal folders
                    $meta_saved = self::$imap->set_metadata($folder, array($metakeys[0] => $prop[$key]));
                if (!$meta_saved)    // try in private namespace
                    $meta_saved = self::$imap->set_metadata($folder, array($metakeys[1] => $prop[$key]));
                if ($meta_saved)
                    unset($prop[$key]);  // unsetting will prevent fallback to local user prefs
            }
        }
    }

}
