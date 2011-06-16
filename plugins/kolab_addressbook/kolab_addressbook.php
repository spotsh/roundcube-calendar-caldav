<?php

require_once(dirname(__FILE__) . '/rcube_kolab_contacts.php');

/**
 * Kolab address book
 *
 * Sample plugin to add a new address book source with data from Kolab storage
 * This is work-in-progress for the Roundcube+Kolab integration.
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
    private $folders;
    private $sources;
    private $rc;

    const GLOBAL_FIRST = 0;
    const PERSONAL_FIRST = 1;
    const GLOBAL_ONLY = 2;
    const PERSONAL_ONLY = 3;

    /**
     * Startup method of a Roundcube plugin
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();

        // load required plugin
        $this->require_plugin('kolab_core');

        // register hooks
        $this->add_hook('addressbooks_list', array($this, 'address_sources'));
        $this->add_hook('addressbook_get', array($this, 'get_address_book'));

        if ($this->rc->task == 'addressbook') {
            $this->add_texts('localization');
            $this->add_hook('contact_form', array($this, 'contact_form'));
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
        foreach ($this->_list_sources() as $abook_id => $abook) {
            // register this address source
            $sources[$abook_id] = array(
                'id' => $abook_id,
                'name' => $abook->get_name(),
                'readonly' => $abook->readonly,
                'groups' => $abook->groups,
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
            foreach ($this->folders as $c_folder) {
                // create instance of rcube_contacts
                $abook_id = rcube_kolab::folder_id($c_folder->name);
                $abook = new rcube_kolab_contacts($c_folder->name);
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

        // Load localization and configuration
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

}
