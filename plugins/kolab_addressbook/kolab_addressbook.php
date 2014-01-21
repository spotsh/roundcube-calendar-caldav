<?php

/**
 * Kolab address book
 *
 * Sample plugin to add a new address book source with data from Kolab storage
 * It provides also a possibilities to manage contact folders
 * (create/rename/delete/acl) directly in Addressbook UI.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
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

class kolab_addressbook extends rcube_plugin
{
    public $task = 'mail|settings|addressbook|calendar';

    private $sources;
    private $rc;
    private $ui;

    const GLOBAL_FIRST = 0;
    const PERSONAL_FIRST = 1;
    const GLOBAL_ONLY = 2;
    const PERSONAL_ONLY = 3;

    /**
     * Startup method of a Roundcube plugin
     */
    public function init()
    {
        require_once(dirname(__FILE__) . '/lib/rcube_kolab_contacts.php');

        $this->rc = rcube::get_instance();

        // load required plugin
        $this->require_plugin('libkolab');

        // register hooks
        $this->add_hook('addressbooks_list', array($this, 'address_sources'));
        $this->add_hook('addressbook_get', array($this, 'get_address_book'));
        $this->add_hook('config_get', array($this, 'config_get'));

        if ($this->rc->task == 'addressbook') {
            $this->add_texts('localization');
            $this->add_hook('contact_form', array($this, 'contact_form'));

            // Plugin actions
            $this->register_action('plugin.book', array($this, 'book_actions'));
            $this->register_action('plugin.book-save', array($this, 'book_save'));

            // Load UI elements
            if ($this->api->output->type == 'html') {
                $this->load_config();
                require_once($this->home . '/lib/kolab_addressbook_ui.php');
                $this->ui = new kolab_addressbook_ui($this);
            }
        }
        else if ($this->rc->task == 'settings') {
            $this->add_texts('localization');
            $this->add_hook('preferences_list', array($this, 'prefs_list'));
            $this->add_hook('preferences_save', array($this, 'prefs_save'));
        }
    }


    /**
     * Handler for the addressbooks_list hook.
     *
     * This will add all instances of available Kolab-based address books
     * to the list of address sources of Roundcube.
     * This will also hide some addressbooks according to kolab_addressbook_prio setting.
     *
     * @param array $p Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function address_sources($p)
    {
        $abook_prio = $this->addressbook_prio();
        $undelete   = $this->rc->config->get('undo_timeout');

        // Disable all global address books
        // Assumes that all non-kolab_addressbook sources are global
        if ($abook_prio == self::PERSONAL_ONLY) {
            $p['sources'] = array();
        }

        $sources = array();
        $names   = array();

        foreach ($this->_list_sources() as $abook_id => $abook) {
            $name = kolab_storage::folder_displayname($abook->get_name(), $names);

            // register this address source
            $sources[$abook_id] = array(
                'id'       => $abook_id,
                'name'     => $name,
                'readonly' => $abook->readonly,
                'editable' => $abook->editable,
                'groups'   => $abook->groups,
                'undelete' => $abook->undelete && $undelete,
                'realname' => rcube_charset::convert($abook->get_realname(), 'UTF7-IMAP'), // IMAP folder name
                'class_name' => $abook->get_namespace(),
                'carddavurl' => $abook->get_carddav_url(),
                'kolab'    => true,
            );
        }

        // Add personal address sources to the list
        if ($abook_prio == self::PERSONAL_FIRST) {
            // $p['sources'] = array_merge($sources, $p['sources']);
            // Don't use array_merge(), because if you have folders name
            // that resolve to numeric identifier it will break output array keys
            foreach ($p['sources'] as $idx => $value)
                $sources[$idx] = $value;
            $p['sources'] = $sources;
        }
        else {
            // $p['sources'] = array_merge($p['sources'], $sources);
            foreach ($sources as $idx => $value)
                $p['sources'][$idx] = $value;
        }

        return $p;
    }


    /**
     * Sets autocomplete_addressbooks option according to
     * kolab_addressbook_prio setting extending list of address sources
     * to be used for autocompletion.
     */
    public function config_get($args)
    {
        if ($args['name'] != 'autocomplete_addressbooks') {
            return $args;
        }

        $abook_prio = $this->addressbook_prio();
        // here we cannot use rc->config->get()
        $sources    = $GLOBALS['CONFIG']['autocomplete_addressbooks'];

        // Disable all global address books
        // Assumes that all non-kolab_addressbook sources are global
        if ($abook_prio == self::PERSONAL_ONLY) {
            $sources = array();
        }

        if (!is_array($sources)) {
            $sources = array();
        }

        $kolab_sources = array();
        foreach (array_keys($this->_list_sources()) as $abook_id) {
            if (!in_array($abook_id, $sources))
                $kolab_sources[] = $abook_id;
        }

        // Add personal address sources to the list
        if (!empty($kolab_sources)) {
            if ($abook_prio == self::PERSONAL_FIRST) {
                $sources = array_merge($kolab_sources, $sources);
            }
            else {
                $sources = array_merge($sources, $kolab_sources);
            }
        }

        $args['result'] = $sources;

        return $args;
    }


    /**
     * Getter for the rcube_addressbook instance
     *
     * @param array $p Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function get_address_book($p)
    {
        if ($p['id']) {
            $this->_list_sources();
            if ($this->sources[$p['id']]) {
                $p['instance'] = $this->sources[$p['id']];
            }
        }

        return $p;
    }


    private function _list_sources()
    {
        // already read sources
        if (isset($this->sources))
            return $this->sources;

        $this->sources = array();

        $abook_prio = $this->addressbook_prio();

        // Personal address source(s) disabled?
        if ($abook_prio == self::GLOBAL_ONLY) {
            return $this->sources;
        }

        // get all folders that have "contact" type
        $folders = kolab_storage::sort_folders(kolab_storage::get_folders('contact'));

        if (PEAR::isError($folders)) {
            rcube::raise_error(array(
              'code' => 600, 'type' => 'php',
              'file' => __FILE__, 'line' => __LINE__,
              'message' => "Failed to list contact folders from Kolab server:" . $folders->getMessage()),
            true, false);
        }
        else {
            // we need at least one folder to prevent from errors in Roundcube core
            // when there's also no sql nor ldap addressbook (Bug #2086)
            if (empty($folders)) {
                if ($folder = kolab_storage::create_default_folder('contact')) {
                    $folders = array(new kolab_storage_folder($folder, 'contact'));
                }
            }

            // convert to UTF8 and sort
            $names = array();
            foreach ($folders as $folder) {
                // create instance of rcube_contacts
                $abook_id = kolab_storage::folder_id($folder->name);
                $abook = new rcube_kolab_contacts($folder->name);
                $this->sources[$abook_id] = $abook;
            }
        }

        return $this->sources;
    }


    /**
     * Plugin hook called before rendering the contact form or detail view
     *
     * @param array $p Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function contact_form($p)
    {
        // none of our business
        if (!is_object($GLOBALS['CONTACTS']) || !is_a($GLOBALS['CONTACTS'], 'rcube_kolab_contacts'))
            return $p;

        // extend the list of contact fields to be displayed in the 'personal' section
        if (is_array($p['form']['personal'])) {
            $p['form']['personal']['content']['profession']    = array('size' => 40);
            $p['form']['personal']['content']['children']      = array('size' => 40);
            $p['form']['personal']['content']['freebusyurl']   = array('size' => 40);
            $p['form']['personal']['content']['pgppublickey']  = array('size' => 70);
            $p['form']['personal']['content']['pkcs7publickey'] = array('size' => 70);

            // re-order fields according to the coltypes list
            $p['form']['contact']['content']  = $this->_sort_form_fields($p['form']['contact']['content']);
            $p['form']['personal']['content'] = $this->_sort_form_fields($p['form']['personal']['content']);

            /* define a separate section 'settings'
            $p['form']['settings'] = array(
                'name'    => $this->gettext('settings'),
                'content' => array(
                    'freebusyurl'  => array('size' => 40, 'visible' => true),
                    'pgppublickey' => array('size' => 70, 'visible' => true),
                    'pkcs7publickey' => array('size' => 70, 'visible' => false),
                )
            );
            */
        }

        return $p;
    }


    private function _sort_form_fields($contents)
    {
      $block    = array();
      $contacts = reset($this->sources);

      foreach (array_keys($contacts->coltypes) as $col) {
          if (isset($contents[$col]))
              $block[$col] = $contents[$col];
      }

      return $block;
    }


    /**
     * Handler for user preferences form (preferences_list hook)
     *
     * @param array $args Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function prefs_list($args)
    {
        if ($args['section'] != 'addressbook') {
            return $args;
        }

        $ldap_public = $this->rc->config->get('ldap_public');
        $abook_type  = $this->rc->config->get('address_book_type');

        // Hide option if there's no global addressbook
        if (empty($ldap_public) || $abook_type != 'ldap') {
            return $args;
        }

        // Check that configuration is not disabled
        $dont_override = (array) $this->rc->config->get('dont_override', array());
        $prio          = $this->addressbook_prio();

        if (!in_array('kolab_addressbook_prio', $dont_override)) {
            // Load localization
            $this->add_texts('localization');

            $field_id = '_kolab_addressbook_prio';
            $select   = new html_select(array('name' => $field_id, 'id' => $field_id));

            $select->add($this->gettext('globalfirst'), self::GLOBAL_FIRST);
            $select->add($this->gettext('personalfirst'), self::PERSONAL_FIRST);
            $select->add($this->gettext('globalonly'), self::GLOBAL_ONLY);
            $select->add($this->gettext('personalonly'), self::PERSONAL_ONLY);

            $args['blocks']['main']['options']['kolab_addressbook_prio'] = array(
                'title' => html::label($field_id, Q($this->gettext('addressbookprio'))),
                'content' => $select->show($prio),
            );
        }

        return $args;
    }

    /**
     * Handler for user preferences save (preferences_save hook)
     *
     * @param array $args Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function prefs_save($args)
    {
        if ($args['section'] != 'addressbook') {
            return $args;
        }

        // Check that configuration is not disabled
        $dont_override = (array) $this->rc->config->get('dont_override', array());
        $key           = 'kolab_addressbook_prio';

        if (!in_array('kolab_addressbook_prio', $dont_override) || !isset($_POST['_'.$key])) {
            $args['prefs'][$key] = (int) get_input_value('_'.$key, RCUBE_INPUT_POST);
        }

        return $args;
    }


    /**
     * Handler for plugin actions
     */
    public function book_actions()
    {
        $action = trim(get_input_value('_act', RCUBE_INPUT_GPC));

        if ($action == 'create') {
            $this->ui->book_edit();
        }
        else if ($action == 'edit') {
            $this->ui->book_edit();
        }
        else if ($action == 'delete') {
            $this->book_delete();
        }
    }


    /**
     * Handler for address book create/edit form submit
     */
    public function book_save()
    {
        $prop = array(
            'name'    => trim(get_input_value('_name', RCUBE_INPUT_POST)),
            'oldname' => trim(get_input_value('_oldname', RCUBE_INPUT_POST, true)), // UTF7-IMAP
            'parent'  => trim(get_input_value('_parent', RCUBE_INPUT_POST, true)), // UTF7-IMAP
            'type'    => 'contact',
            'subscribed' => true,
        );

        $result = $error = false;
        $type = strlen($prop['oldname']) ? 'update' : 'create';
        $prop = $this->rc->plugins->exec_hook('addressbook_'.$type, $prop);

        if (!$prop['abort']) {
            if ($newfolder = kolab_storage::folder_update($prop)) {
                $folder = $newfolder;
                $result = true;
            }
            else {
                $error = kolab_storage::$last_error;
            }
        }
        else {
            $result = $prop['result'];
            $folder = $prop['name'];
        }

        if ($result) {
            $storage = $this->rc->get_storage();
            $delimiter = $storage->get_hierarchy_delimiter();
            $kolab_folder = new rcube_kolab_contacts($folder);

            // create display name for the folder (see self::address_sources())
            if (strpos($folder, $delimiter)) {
                $names = array();
                foreach ($this->_list_sources() as $abook) {
                    $realname = $abook->get_realname();
                    // The list can be not updated yet, handle old folder name
                    if ($type == 'update' && $realname == $prop['oldname']) {
                        $abook    = $kolab_folder;
                        $realname = $folder;
                    }

                    $name = kolab_storage::folder_displayname($abook->get_name(), $names);

                    if ($realname == $folder) {
                        break;
                    }
                }
            }
            else {
                $name = $kolab_folder->get_name();
            }

            $this->rc->output->show_message('kolab_addressbook.book'.$type.'d', 'confirmation');
            $this->rc->output->command('set_env', 'delimiter', $delimiter);
            $this->rc->output->command('book_update', array(
                'id'       => kolab_storage::folder_id($folder),
                'name'     => $name,
                'readonly' => false,
                'editable' => true,
                'groups'   => true,
                'realname' => rcube_charset::convert($folder, 'UTF7-IMAP'), // IMAP folder name
                'class_name' => $kolab_folder->get_namespace(),
                'kolab'    => true,
            ), kolab_storage::folder_id($prop['oldname']));

            $this->rc->output->send('iframe');
        }

        if (!$error)
            $error = $plugin['message'] ? $plugin['message'] : 'kolab_addressbook.book'.$type.'error';

        $this->rc->output->show_message($error, 'error');
        // display the form again
        $this->ui->book_edit();
    }


    /**
     * Handler for address book delete action (AJAX)
     */
    private function book_delete()
    {
        $folder = trim(get_input_value('_source', RCUBE_INPUT_GPC, true, 'UTF7-IMAP'));

        if (kolab_storage::folder_delete($folder)) {
            $storage = $this->rc->get_storage();
            $delimiter = $storage->get_hierarchy_delimiter();

            $this->rc->output->show_message('kolab_addressbook.bookdeleted', 'confirmation');
            $this->rc->output->set_env('pagecount', 0);
            $this->rc->output->command('set_rowcount', rcmail_get_rowcount_text(new rcube_result_set()));
            $this->rc->output->command('set_env', 'delimiter', $delimiter);
            $this->rc->output->command('list_contacts_clear');
            $this->rc->output->command('book_delete_done', kolab_storage::folder_id($folder));
        }
        else {
            $this->rc->output->show_message('kolab_addressbook.bookdeleteerror', 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Returns value of kolab_addressbook_prio setting
     */
    private function addressbook_prio()
    {
        // Load configuration
        if (!$this->config_loaded) {
            $this->load_config();
            $this->config_loaded = true;
        }

        $abook_prio = (int) $this->rc->config->get('kolab_addressbook_prio');

        // Make sure any global addressbooks are defined
        if ($abook_prio == 0 || $abook_prio == 2) {
            $ldap_public = $this->rc->config->get('ldap_public');
            $abook_type  = $this->rc->config->get('address_book_type');

            if (empty($ldap_public) || $abook_type != 'ldap') {
                $abook_prio = 1;
            }
        }

        return $abook_prio;
    }
}
