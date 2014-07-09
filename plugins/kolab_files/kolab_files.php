<?php

/**
 * Kolab files storage
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2013, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_files extends rcube_plugin
{
    // all task excluding 'login' and 'logout'
    public $task = '?(?!login|logout).*';

    public $rc;
    public $home;
    private $engine;

    public function init()
    {
        $this->rc = rcube::get_instance();

        // Register hooks
        $this->add_hook('refresh', array($this, 'refresh'));

        // Plugin actions for other tasks
        $this->register_action('plugin.kolab_files', array($this, 'actions'));

        // Register task
        $this->register_task('files');

        // Register plugin task actions
        $this->register_action('index', array($this, 'actions'));
        $this->register_action('prefs', array($this, 'actions'));
        $this->register_action('open',  array($this, 'actions'));

        // we use libkolab::http_request() from libkolab with its configuration
        $this->require_plugin('libkolab');

        // Load UI from startup hook
        $this->add_hook('startup', array($this, 'startup'));
    }

    /**
     * Creates kolab_files_engine instance
     */
    private function engine()
    {
        if ($this->engine === null) {
            // the files module can be enabled/disabled by the kolab_auth plugin
            if ($this->rc->config->get('kolab_files_disabled') || !$this->rc->config->get('kolab_files_enabled', true)) {
                return $this->engine = false;
            }

            $this->load_config();

            $url = $this->rc->config->get('kolab_files_url');

            if (!$url) {
                return $this->engine = false;
            }

            require_once $this->home . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'kolab_files_engine.php';

            $this->engine = new kolab_files_engine($this, $url);
        }

        return $this->engine;
    }

    /**
     * Startup hook handler, initializes/enables Files UI
     */
    public function startup($args)
    {
        // call this from startup to give a chance to set
        // kolab_files_enabled/disabled in kolab_auth plugin
        $this->ui();
    }

    /**
     * Adds elements of files API user interface
     */
    private function ui()
    {
        if ($this->rc->output->type != 'html') {
            return;
        }

        if ($engine = $this->engine()) {
            $engine->ui();
        }
    }

    /**
     * Refresh hook handler
     */
    public function refresh($args)
    {
        // Here we are refreshing API session, so when we need it
        // the session will be active
        if ($engine = $this->engine()) {
            $this->rc->output->set_env('files_token', $engine->get_api_token());
        }

        return $args;
    }

    /**
     * Engine actions handler
     */
    public function actions()
    {
        if ($engine = $this->engine()) {
            $engine->actions();
        }
    }
}
