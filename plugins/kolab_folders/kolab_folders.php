<?php

/**
 * Type-aware folder management/listing for Kolab
 *
 * @version 0.3
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 *
 * Copyright (C) 2011, Kolab Systems AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

class kolab_folders extends rcube_plugin
{
    public $task = '?(?!login).*';

    public $types = array('mail', 'event', 'journal', 'task', 'note', 'contact', 'configuration');
    public $mail_types = array('drafts', 'sentitems', 'outbox', 'wastebasket', 'junkemail');
    private $rc;

    const CTYPE_KEY = '/shared/vendor/kolab/folder-type';


    /**
     * Plugin initialization.
     */
    function init()
    {
        $this->rc = rcmail::get_instance();

        // Folder listing hooks
        $this->add_hook('mailboxes_list', array($this, 'mailboxes_list'));

        // Folder manager hooks
        $this->add_hook('folder_form', array($this, 'folder_form'));
        $this->add_hook('folder_update', array($this, 'folder_save'));
        $this->add_hook('folder_create', array($this, 'folder_save'));
        $this->add_hook('folder_delete', array($this, 'folder_save'));
        $this->add_hook('folder_rename', array($this, 'folder_save'));
        $this->add_hook('folders_list', array($this, 'folders_list'));
    }

    /**
     * Handler for mailboxes_list hook. Enables type-aware lists filtering.
     */
    function mailboxes_list($args)
    {
        if (!$this->metadata_support()) {
            return $args;
        }

        $filter = $args['filter'];

        // all-folders request, use core method
        if (!$filter) {
            return $args;
        }

        // get folders types
        $folderdata = $this->get_folder_type_list($args['root'].$args['name']);

        if (!is_array($folderdata)) {
            $args['folders'] = false;
            return $args;
        }

        $regexp = '/^' . preg_quote($filter, '/') . '(\..+)?$/';

        // In some conditions we can skip LIST command (?)
        if ($args['mode'] == 'LIST' && $filter != 'mail'
            && $args['root'] == '' && $args['name'] == '*'
        ) {
            foreach ($folderdata as $folder => $data) {
                if (!preg_match($regexp, $data[kolab_folders::CTYPE_KEY])) {
                    unset ($folderdata[$folder]);
                }
            }
            $args['folders'] = array_keys($folderdata);
            return $args;
        }

        // Get folders list
        if ($args['mode'] == 'LIST') {
            $args['folders'] = $this->rc->imap->conn->listMailboxes($args['root'], $args['name']);
        }
        else {
            $args['folders'] = $this->list_subscribed($args['root'], $args['name']);
        }

        // In case of an error, return empty list
        if (!is_array($args['folders'])) {
            $args['folders'] = array();
            return $args;
        }

        // Filter folders list
        foreach ($args['folders'] as $idx => $folder) {
            $data = $folderdata[$folder];
            // Empty data => mail
            if ($filter == 'mail' && empty($data)) {
                continue;
            }
            if (empty($data) || !preg_match($regexp, $data[kolab_folders::CTYPE_KEY])) {
                unset($args['folders'][$idx]);
            }
        }

        return $args;
    }

    /**
     * Handler for folders_list hook. Add css classes to folder rows.
     */
    function folders_list($args)
    {
        if (!$this->metadata_support()) {
            return $args;
        }

        $table = $args['table'];

        // get folders types
        $folderdata = $this->get_folder_type_list('*');

        if (!is_array($folderdata)) {
            return $args;
        }

        // Add type-based style for table rows
        // See kolab_folders::folder_class_name()
        for ($i=1, $cnt=$table->size(); $i<=$cnt; $i++) {
            $attrib = $table->get_row_attribs($i);
            $folder = $attrib['foldername']; // UTF7-IMAP
            $data   = $folderdata[$folder];

            if (!empty($data))
                $type = $data[kolab_folders::CTYPE_KEY];

            if (!$type)
                $type = 'mail';

            $class_name = self::folder_class_name($type);

            $attrib['class'] = trim($attrib['class'] . ' ' . $class_name);
            $table->set_row_attribs($attrib, $i);
        }

        return $args;
    }

    /**
     * Handler for folder info/edit form (folder_form hook).
     * Adds folder type selector.
     */
    function folder_form($args)
    {
        if (!$this->metadata_support()) {
            return $args;
        }
        // load translations
        $this->add_texts('localization/', false);

        // INBOX folder is of type mail.inbox and this cannot be changed
        if ($args['name'] == 'INBOX') {
            $args['form']['props']['fieldsets']['settings']['content']['foldertype'] = array(
                'label' => $this->gettext('folderctype'),
                'value' => sprintf('%s (%s)', $this->gettext('foldertypemail'), $this->gettext('inbox')),
            );

            return $args;
        }

        if ($args['options']['is_root']) {
            return $args;
        }

        $mbox = strlen($args['name']) ? $args['name'] : $args['parent_name'];

        if (isset($_POST['_ctype'])) {
            $new_ctype   = trim(get_input_value('_ctype', RCUBE_INPUT_POST));
            $new_subtype = trim(get_input_value('_subtype', RCUBE_INPUT_POST));
        }

        // Get type of the folder or the parent
        if (strlen($mbox)) {
            list($ctype, $subtype) = $this->get_folder_type($mbox);
            if (strlen($args['parent_name']) && $subtype == 'default')
                $subtype = ''; // there can be only one
        }

        if (!$ctype) {
            $ctype = 'mail';
        }

        // Don't allow changing type of shared folder, according to ACL
        if (strlen($mbox)) {
            $options = $this->rc->imap->mailbox_info($mbox);
            if ($options['namespace'] != 'personal' && !in_array('a', $options['rights'])) {
                if (in_array($ctype, $this->types))
                    $value = $this->gettext('foldertype'.$ctype);
                else
                    $value = $ctype;
                if ($subtype)
                    $value .= ' ('. ($subtype == 'default' ? $this->gettext('default') : $subtype) .')';

                $args['form']['props']['fieldsets']['settings']['content']['foldertype'] = array(
                    'label' => $this->gettext('folderctype'),
                    'value' => $value,
                );

                return $args;
            }
        }

        // Add javascript script to the client
        $this->include_script('kolab_folders.js');

        // build type SELECT fields
        $type_select = new html_select(array('name' => '_ctype', 'id' => '_ctype'));
        $sub_select  = new html_select(array('name' => '_subtype', 'id' => '_subtype'));

        foreach ($this->types as $type) {
            $type_select->add($this->gettext('foldertype'.$type), $type);
        }
        // add non-supported type
        if (!in_array($ctype, $this->types)) {
            $type_select->add($ctype, $ctype);
        }

        $sub_select->add('', '');
        $sub_select->add($this->gettext('default'), 'default');
        foreach ($this->mail_types as $type) {
            $sub_select->add($this->gettext($type), $type);
        }

        $args['form']['props']['fieldsets']['settings']['content']['foldertype'] = array(
            'label' => $this->gettext('folderctype'),
            'value' => $type_select->show(isset($new_ctype) ? $new_ctype : $ctype)
                . $sub_select->show(isset($new_subtype) ? $new_subtype : $subtype),
        );

        return $args;
    }

    /**
     * Handler for folder update/create action (folder_update/folder_create hook).
     */
    function folder_save($args)
    {
        // Folder actions from folders list
        if (empty($args['record'])) {
            // Just clear Horde folders cache and return
            $this->clear_folders_cache();
            return $args;
        }

        // Folder create/update with form
        $ctype     = trim(get_input_value('_ctype', RCUBE_INPUT_POST));
        $subtype   = trim(get_input_value('_subtype', RCUBE_INPUT_POST));
        $mbox      = $args['record']['name'];
        $old_mbox  = $args['record']['oldname'];
        $subscribe = $args['record']['subscribe'];

        if (empty($ctype)) {
            return $args;
        }

        // load translations
        $this->add_texts('localization/', false);

        // Skip folder creation/rename in core
        // @TODO: Maybe we should provide folder_create_after and folder_update_after hooks?
        //        Using create_mailbox/rename_mailbox here looks bad
        $args['abort']  = true;

        // There can be only one default folder of specified type
        if ($subtype == 'default') {
            $default = $this->get_default_folder($ctype);

            if ($default !== null && $old_mbox != $default) {
                $args['result'] = false;
                $args['message'] = $this->gettext('defaultfolderexists');
                return $args;
            }
        }
        // Subtype sanity-checks
        else if ($subtype && ($ctype != 'mail' || !in_array($subtype, $this->mail_types))) {
            $subtype = '';
        }

        $ctype .= $subtype ? '.'.$subtype : '';

        // Create folder
        if (!strlen($old_mbox)) {
            // By default don't subscribe to non-mail folders
            if ($subscribe)
                $subscribe = (bool) preg_match('/^mail/', $ctype);

            $result = $this->rc->imap->create_mailbox($mbox, $subscribe);
            // Set folder type
            if ($result) {
                $this->set_folder_type($mbox, $ctype);
            }
        }
        // Rename folder
        else {
            if ($old_mbox != $mbox) {
                $result = $this->rc->imap->rename_mailbox($old_mbox, $mbox);
            }
            else {
                $result = true;
            }

            if ($result) {
                list($oldtype, $oldsubtype) = $this->get_folder_type($mbox);
                $oldtype .= $oldsubtype ? '.'.$oldsubtype : '';

                if ($ctype != $oldtype) {
                    $this->set_folder_type($mbox, $ctype);
                }
            }
        }

        // Clear Horde folders cache
        if ($result) {
            $this->clear_folders_cache();
        }

        $args['record']['class'] = self::folder_class_name($ctype);
        $args['record']['subscribe'] = $subscribe;
        $args['result'] = $result;

        return $args;
    }

    /**
     * Checks if IMAP server supports any of METADATA, ANNOTATEMORE, ANNOTATEMORE2
     *
     * @return boolean 
     */
    function metadata_support()
    {
        return $this->rc->imap->get_capability('METADATA') ||
            $this->rc->imap->get_capability('ANNOTATEMORE') ||
            $this->rc->imap->get_capability('ANNOTATEMORE2');
    }

    /**
     * Checks if IMAP server supports any of METADATA, ANNOTATEMORE, ANNOTATEMORE2
     *
     * @param string $folder Folder name
     *
     * @return array Folder content-type
     */
    function get_folder_type($folder)
    {
        $folderdata = $this->rc->imap->get_metadata($folder, array(kolab_folders::CTYPE_KEY));

        return explode('.', $folderdata[$folder][kolab_folders::CTYPE_KEY]);
    }

    /**
     * Sets folder content-type.
     *
     * @param string $folder Folder name
     * @param string $type   Content type
     *
     * @return boolean True on success
     */
    function set_folder_type($folder, $type='mail')
    {
        return $this->rc->imap->set_metadata($folder, array(kolab_folders::CTYPE_KEY => $type));
    }

    /**
     * Returns list of subscribed folders (directly from IMAP server)
     *
     * @param string $root Optional root folder
     * @param string $name Optional name pattern
     *
     * @return array List of mailboxes/folders
     */
    private function list_subscribed($root='', $name='*')
    {
        $imap = $this->rc->imap;

        // Code copied from rcube_imap::_list_mailboxes()
        // Server supports LIST-EXTENDED, we can use selection options
        // #1486225: Some dovecot versions returns wrong result using LIST-EXTENDED
        if (!$this->rc->config->get('imap_force_lsub') && $imap->get_capability('LIST-EXTENDED')) {
            // This will also set mailbox options, LSUB doesn't do that
            $a_folders = $imap->conn->listMailboxes($root, $name,
                NULL, array('SUBSCRIBED'));

            // remove non-existent folders
            if (is_array($a_folders) && $name = '*') {
                foreach ($a_folders as $idx => $folder) {
                    if ($imap->conn->data['LIST'] && ($opts = $imap->conn->data['LIST'][$folder])
                        && in_array('\\NonExistent', $opts)
                    ) {
                        $imap->conn->unsubscribe($folder);
                        unset($a_folders[$idx]);
                    }
                }
            }
        }
        // retrieve list of folders from IMAP server using LSUB
        else {
            $a_folders = $imap->conn->listSubscribed($root, $name);

            // unsubscribe non-existent folders, remove from the list
            if (is_array($a_folders) && $name == '*') {
                foreach ($a_folders as $idx => $folder) {
                    if ($imap->conn->data['LIST'] && ($opts = $imap->conn->data['LIST'][$folder])
                        && in_array('\\Noselect', $opts)
                    ) {
                        // Some servers returns \Noselect for existing folders
                        if (!$imap->mailbox_exists($folder)) {
                            $imap->conn->unsubscribe($folder);
                            unset($a_folders[$idx]);
                        }
                    }
                }
            }
        }

        return $a_folders;
    }

    /**
     * Returns list of folder(s) type(s)
     *
     * @param string $mbox Folder name or pattern
     *
     * @return array List of folders data, indexed by folder name
     */
    function get_folder_type_list($mbox)
    {
        // Use mailboxes. prefix so the cache will be cleared by core
        // together with other mailboxes-related cache data
        $cache_key = 'mailboxes.types.'.$mbox;

        // get cached metadata
        $metadata = $this->rc->imap->get_cache($cache_key);
        if (is_array($metadata)) {
            return $metadata;
        }

        $metadata = $this->rc->imap->get_metadata($mbox, kolab_folders::CTYPE_KEY);

        if (!is_array($metadata)) {
            return false;
        }

        // write mailboxlist to cache
        $this->rc->imap->update_cache($cache_key, $metadata);

        return $metadata;
    }

    /**
     * Returns the name of default folder
     *
     * @param string $type Folder type
     *
     * @return string Folder name
     */
    function get_default_folder($type)
    {
        $folderdata = $this->get_folder_type_list('*');

        if (!is_array($folderdata)) {
            return null;
        }

        $type     .= '.default';
        $namespace = $this->rc->imap->get_namespace();
        $delimiter = $this->rc->imap->get_hierarchy_delimiter();

        foreach ($folderdata as $folder => $data) {
            if ($data[kolab_folders::CTYPE_KEY] != $type) {
                unset ($folderdata[$folder]);
                continue;
            }

            // folder found, check if it is in personal namespace
            $fname = $folder . $delimiter;

            if (!empty($namespace['other'])) {
                foreach ($namespace['other'] as $item) {
                    if ($item[0] === $fname) {
                        unset ($folderdata[$folder]);
                        continue 2;
                    }
                }
            }
            if (!empty($namespace['shared'])) {
                foreach ($namespace['shared'] as $item) {
                    if ($item[0] === $fname) {
                        unset ($folderdata[$folder]);
                        continue 2;
                    }
                }
            }

            // There can be only one default folder of specified type
            return $folder;
        }

        return null;
    }

    /**
     * Returns CSS class name for specified folder type
     *
     * @param string $type Folder type
     *
     * @return string Class name
     */
    static function folder_class_name($type)
    {
        list($ctype, $subtype) = explode('.', $type);

        $class[] = 'type-' . ($ctype ? $ctype : 'mail');

        if ($subtype)
            $class[] = 'subtype-' . $subtype;

        return implode(' ', $class);
    }

    /**
     * Clear Horde's folder cache. See Kolab_List::singleton().
     */
    private function clear_folders_cache()
    {
        unset($_SESSION['horde_session_objects']['kolab_folderlist']);
    }
}
