<?php

/**
 * Kolab core library
 *
 * Plugin to setup a basic environment for interaction with a Kolab server.
 * Other Kolab-related plugins will depend on it and can use the static API rcube_core
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
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

        // Register password reset hook
        $this->add_hook('password_change', array($this, 'password_change'));
    }

    /**
     * Resets auth session data after password change
     */
    public function password_change($args)
    {
        rcmail::get_instance()->session->remove('__auth');

        return $args;
    }
}
