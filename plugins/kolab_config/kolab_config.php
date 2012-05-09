<?php

/**
 * Kolab configuration storage.
 *
 * Plugin to use Kolab server as a configuration storage. Provides an API to handle
 * configuration according to http://wiki.kolab.org/KEP:9.
 *
 * @version @package_version@
 * @author Machniak Aleksander <machniak@kolabsys.com>
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

class kolab_config extends rcube_plugin
{
    public $task = 'utils';

    private $config;
    private $enabled;

    /**
     * Required startup method of a Roundcube plugin
     */
    public function init()
    {
        $rcmail = rcmail::get_instance();

        // Register spellchecker dictionary handlers
        if (strtolower($rcmail->config->get('spellcheck_dictionary')) != 'shared') {
            $this->add_hook('spell_dictionary_save', array($this, 'dictionary_save'));
            $this->add_hook('spell_dictionary_get', array($this, 'dictionary_get'));
        }
/*
        // Register addressbook saved searches handlers
        $this->add_hook('saved_search_create', array($this, 'saved_search_create'));
        $this->add_hook('saved_search_delete', array($this, 'saved_search_delete'));
        $this->add_hook('saved_search_list', array($this, 'saved_search_list'));
        $this->add_hook('saved_search_get', array($this, 'saved_search_get'));
*/
    }

    /**
     * Initializes config object and dependencies
     */
    private function load()
    {
        if ($this->config)
            return;

        return;  // CURRENTLY DISABLED until libkolabxml has support for config objects

        $this->require_plugin('libkolab');

        $this->config = new kolab_configuration();

        // check if configuration folder exist
        if (strlen($this->config->dir)) {
            $this->enabled = true;
        }
    }

    /**
     * Saves spellcheck dictionary.
     *
     * @param array $args Hook arguments
     *
     * @return array Hook arguments
     */
    public function dictionary_save($args)
    {
        $this->load();

        if (!$this->enabled) {
            return $args;
        }

        $lang = $args['language'];
        $dict = $this->dict;

        $dict['type']     = 'dictionary';
        $dict['language'] = $args['language'];
        $dict['e']        = $args['dictionary'];

        if (empty($dict['e'])) {
            // Delete the object
            $this->config->del($dict);
        }
        else {
            // Update the object
            $this->config->set($dict);
        }

        $args['abort'] = true;

	    return $args;
    }

    /**
     * Returns spellcheck dictionary.
     *
     * @param array $args Hook arguments
     *
     * @return array Hook arguments
     */
    public function dictionary_get($args)
    {
        $this->load();

        if (!$this->enabled) {
            return $args;
        }

        $lang = $args['language'];
        $this->dict = $this->config->get('dictionary.'.$lang);

        if (!empty($this->dict)) {
            $args['dictionary'] = $this->dict['e'];
        }

        $args['abort'] = true;

	    return $args;
    }
}
