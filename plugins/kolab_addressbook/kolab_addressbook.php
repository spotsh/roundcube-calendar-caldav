<?php

/**
 * Kolab address book 0.5
 *
 * Sample plugin to add a new address book source with data from Kolab storage
 * It provides also a possibilities to manage contact folders
 * (create/rename/delete/acl) directly in Addressbook UI.
 *
 * @author Thomas Bruederli <roundcube@gmail.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
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
class kolab_addressbook extends rcube_plugin
{
    public $task = 'mail|settings|addressbook';

    private $folders;
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

        $this->rc = rcmail::get_instance();

        // load required plugin
        $this->require_plugin('kolab_core');

        // register hooks
        $this->add_hook('addressbooks_list', array($this, 'address_sources'));
        $this->add_hook('addressbook_get', array($this, 'get_address_book'));

        if ($this->rc->task == 'addressbook') {
            $this->add_texts('localization');
            $this->add_hook('contact_form', array($this, 'contact_form'));

            // Plugin actions
            $this->register_action('plugin.book', array($this, 'book_actions'));
            $this->register_action('plugin.book-save', array($this, 'book_save'));

            // Load UI elements
            if ($this->api->output->type == 'html') {
                require_once($this->home . '/lib/kolab_addressbook_ui.php');
                $this->ui = new kolab_addressbook_ui($this);
            }
        }
        else if ($this->rc->task == 'settings') {
            $this->add_texts('localization');
            $this->add_hook('preferences_list', array($this, 'prefs_list'));
            $this->add_hook('preferences_save', array($this, 'prefs_save'));
        }
        // extend list of address sources to be used for autocompletion
        else if ($this->rc->task == 'mail' && $this->rc->action == 'autocomplete') {
            $this->autocomplete_sources();
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
        // Load configuration
        $this->load_config();

        $abook_prio = (int) $this->rc->config->get('kolab_addressbook_prio');

        // Disable all global address books
        // Assumes that all non-kolab_addressbook sources are global
        if ($abook_prio == self::PERSONAL_ONLY) {
            $p['sources'] = array();
        }

        $sources = array();
        $names   = array();

        foreach ($this->_list_sources() as $abook_id => $abook) {
            $name = $origname = $abook->get_name();

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

            // register this address source
            $sources[$abook_id] = array(
                'id'       => $abook_id,
                'name'     => $name,
                'readonly' => $abook->readonly,
                'editable' => $abook->editable,
                'groups'   => $abook->groups,
                'realname' => rcube_charset_convert($abook->get_realname(), 'UTF7-IMAP'), // IMAP folder name
                'class_name' => $abook->get_namespace(),
                'kolab'    => true,
            );
        }

        // Add personal address sources to the list
        if ($abook_prio == self::PERSONAL_FIRST) {
            $p['sources'] = array_merge($sources, $p['sources']);
        }
        else {
            $p['sources'] = array_merge($p['sources'], $sources);
        }

        return $p;
    }


    /**
     * Setts autocomplete_addressbooks option according to
     * kolab_addressbook_prio setting.
     */
    public function autocomplete_sources()
    {
        // Load configuration
        $this->load_config();

        $abook_prio = (int) $this->rc->config->get('kolab_addressbook_prio');
        $sources    = (array) $this->rc->config->get('autocomplete_addressbooks', array());

        // Disable all global address books
        // Assumes that all non-kolab_addressbook sources are global
        if ($abook_prio == self::PERSONAL_ONLY) {
            $sources = array();
        }

        $kolab_sources = array();
        foreach ($this->_list_sources() as $abook_id => $abook) {
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

            $this->rc->config->set('autocomplete_addressbooks', $sources);
        }
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
        $this->_list_sources();

        if ($this->sources[$p['id']]) {
            $p['instance'] = $this->sources[$p['id']];
        }

        return $p;
    }


    private function _list_sources()
    {
        // already read sources
        if (isset($this->sources))
            return $this->sources;

        $this->sources = array();

        // Load configuration
        $this->load_config();

        $abook_prio = (int) $this->rc->config->get('kolab_addressbook_prio');

        // Personal address source(s) disabled?
        if ($abook_prio == self::GLOBAL_ONLY) {
            return $this->sources;
        }

        // get all folders that have "contact" type
        $this->folders = rcube_kolab::get_folders('contact');

        if (PEAR::isError($this->folders)) {
            raise_error(array(
              'code' => 600, 'type' => 'php',
              'file' => __FILE__, 'line' => __LINE__,
              'message' => "Failed to list contact folders from Kolab server:" . $this->folders->getMessage()),
            true, false);
        }
        else {
            // convert to UTF8 and sort
            $names = array();
            foreach ($this->folders as $c_folder)
                $names[$c_folder->name] = rcube_charset_convert($c_folder->name, 'UTF7-IMAP');

            asort($names, SORT_LOCALE_STRING);

            foreach ($names as $utf7name => $name) {
                // create instance of rcube_contacts
                $abook_id = rcube_kolab::folder_id($utf7name);
                $abook = new rcube_kolab_contacts($utf7name);
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
        if (!is_a($GLOBALS['CONTACTS'], 'rcube_kolab_contacts'))
            return $p;

        // extend the list of contact fields to be displayed in the 'personal' section
        if (is_array($p['form']['personal'])) {
            $p['form']['contact']['content']['officelocation'] = array('size' => 40);
            $p['form']['personal']['content']['initials']      = array('size' => 6);
            $p['form']['personal']['content']['profession']    = array('size' => 40);
            $p['form']['personal']['content']['children']      = array('size' => 40);
            $p['form']['personal']['content']['pgppublickey']  = array('size' => 40);
            $p['form']['personal']['content']['freebusyurl']   = array('size' => 40);

            // re-order fields according to the coltypes list
            $p['form']['contact']['content']  = $this->_sort_form_fields($p['form']['contact']['content']);
            $p['form']['personal']['content'] = $this->_sort_form_fields($p['form']['personal']['content']);

            /* define a separate section 'settings'
            $p['form']['settings'] = array(
                'name'    => $this->gettext('settings'),
                'content' => array(
                    'pgppublickey' => array('size' => 40, 'visible' => true),
                    'freebusyurl'  => array('size' => 40, 'visible' => true),
                )
            );
            */
        }

        return $p;
    }


    private function _sort_form_fields($contents)
    {
      $block = array();
      $contacts = reset($this->sources);
      foreach ($contacts->coltypes as $col => $prop) {
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

        // Load configuration
        $this->load_config();

        // Load localization
        $this->add_texts('localization');

        // Check that configuration is not disabled
        $dont_override  = (array) $this->rc->config->get('dont_override', array());

        if (!in_array('kolab_addressbook_prio', $dont_override)) {
            $field_id = '_kolab_addressbook_prio';
            $select   = new html_select(array('name' => $field_id, 'id' => $field_id));

            $select->add($this->gettext('globalfirst'), self::GLOBAL_FIRST);
            $select->add($this->gettext('personalfirst'), self::PERSONAL_FIRST);
            $select->add($this->gettext('globalonly'), self::GLOBAL_ONLY);
            $select->add($this->gettext('personalonly'), self::PERSONAL_ONLY);

            $args['blocks']['main']['options']['kolab_addressbook_prio'] = array(
                'title' => html::label($field_id, Q($this->gettext('addressbookprio'))),
                'content' => $select->show((int)$this->rc->config->get('kolab_addressbook_prio')),
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

        // Load configuration
        $this->load_config();

        // Check that configuration is not disabled
        $dont_override  = (array) $this->rc->config->get('dont_override', array());

        if (!in_array('kolab_addressbook_prio', $dont_override)) {
            $key = 'kolab_addressbook_prio';
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
        $folder    = trim(get_input_value('_name', RCUBE_INPUT_POST, true, 'UTF7-IMAP'));
        $oldfolder = trim(get_input_value('_oldname', RCUBE_INPUT_POST, true)); // UTF7-IMAP
        $path      = trim(get_input_value('_parent', RCUBE_INPUT_POST, true)); // UTF7-IMAP
        $delimiter = $_SESSION['imap_delimiter'];

        // sanity checks (from steps/settings/save_folder.inc)
        if (!strlen($folder)) {
            $error = rcube_label('cannotbeempty');
        }
        else if (strlen($name) > 128) {
            $error = rcube_label('nametoolong');
        }
        else {
            // these characters are problematic e.g. when used in LIST/LSUB
            foreach (array($delimiter, '%', '*') as $char) {
                if (strpos($name, $delimiter) !== false) {
                    $error = rcube_label('forbiddencharacter') . " ($char)";
                    break;
                }
            }
        }

        if (!$error) {
            // @TODO: $options
            $options = array();
            if ($options['protected'] || $options['norename']) {
                $folder = $oldfolder;
            }
            else if (strlen($path)) {
                $folder = $path . $delimiter . $folder;
            }
            else {
                // add namespace prefix (when needed)
                $this->rc->imap_init();
                $folder = $this->rc->imap->mod_mailbox($folder, 'in');
            }

            // update the folder name
            if (strlen($oldfolder)) {
                $type = 'update';
                $plugin = $this->rc->plugins->exec_hook('addressbook_update', array(
                    'name' => $folder, 'oldname' => $oldfolder));

                if (!$plugin['abort']) {
                    if ($oldfolder != $folder)
                        $result = rcube_kolab::folder_rename($oldfolder, $folder);
                    else
                        $result = true;
                }
                else {
                    $result = $plugin['result'];
                }
            }
            // create new folder
            else {
                $type = 'create';
                $plugin = $this->rc->plugins->exec_hook('addressbook_create', array('name' => $folder));

                $folder = $plugin['name'];

                if (!$plugin['abort']) {
                    $result = rcube_kolab::folder_create($folder, 'contact', false);
                }
                else {
                    $result = $plugin['result'];
                }
            }
        }

        if ($result) {
            $kolab_folder = new rcube_kolab_contacts($folder);

            // create display name for the folder (see self::address_sources())
            if (strpos($folder, $delimiter)) {
                $names = array();
                foreach ($this->_list_sources() as $abook_id => $abook) {
                    $realname = $abook->get_realname();
                    // The list can be not updated yet, handle old folder name
                    if ($type == 'update' && $realname == $oldfolder) {
                        $abook    = $kolab_folder;
                        $realname = $folder;
                    }

                    $name = $origname = $abook->get_name();

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
                'id'       => rcube_kolab::folder_id($folder),
                'name'     => $name,
                'readonly' => false,
                'editable' => true,
                'groups'   => true,
                'realname' => rcube_charset_convert($folder, 'UTF7-IMAP'), // IMAP folder name
                'class_name' => $kolab_folder->get_namespace(),
                'kolab'    => true,
            ), rcube_kolab::folder_id($oldfolder));

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

        if (rcube_kolab::folder_delete($folder)) {
            $this->rc->output->show_message('kolab_addressbook.bookdeleted', 'confirmation');
            $this->rc->output->set_env('pagecount', 0);
            $this->rc->output->command('set_rowcount', rcmail_get_rowcount_text(new rcube_result_set()));
            $this->rc->output->command('list_contacts_clear');
            $this->rc->output->command('book_delete_done', rcube_kolab::folder_id($folder));
        }
        else {
            $this->rc->output->show_message('kolab_addressbook.bookdeleteerror', 'error');
        }

        $this->rc->output->send();
    }
}
