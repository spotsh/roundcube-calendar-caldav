<?php

/**
 * Kolab configuration storage.
 *
 * Plugin to use Kolab server as a configuration storage. Provides an API to handle
 * configuration according to http://wiki.kolab.org/KEP:9.
 *
 * Copyright (C) 2011, Kolab Systems AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * @author Machniak Aleksander <machniak@kolabsys.com>
 *
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

        $this->require_plugin('kolab_folders');

        // load dependencies
        require_once 'Horde/Util.php';
        require_once 'Horde/Kolab/Format.php';
        require_once 'Horde/Kolab/Format/XML.php';
        require_once $this->home . '/lib/configuration.php';
        require_once $this->home . '/lib/kolab_configuration.php';

        String::setDefaultCharset('UTF-8');

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
