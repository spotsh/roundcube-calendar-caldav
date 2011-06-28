<?php

/**
 * Kolab core library
 * 
 * Plugin to setup a basic environment for interaction with a Kolab server.
 * Other Kolab-related plugins will depend on it and can use the static API rcube_core
 *
 * Copyright (C) 2011, Kolab Systems AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * @author Thomas Bruederli <roundcube@gmail.com>
 *
 */
class kolab_core extends rcube_plugin
{
    /**
     * Required startup method of a Roundcube plugin
     */
    public function init()
    {
        // load local config
        $this->load_config();

        // extend include path to load bundled Horde classes
        $include_path = $this->home . PATH_SEPARATOR . ini_get('include_path');
        set_include_path($include_path);
    }
}

