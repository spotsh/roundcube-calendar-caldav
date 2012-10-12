<?php

/**
 * Sample plugin to configure TinyMCE editor
 *
 * Copyright (C) 2011-2012, Kolab Systems AG <contact@kolabsys.com>
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
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 */
class tinymce_config extends rcube_plugin
{
  public $task = 'mail|settings';

  function init()
  {
    $this->add_hook('html_editor', array($this, 'config'));
  }

  function config($args)
  {
    $rcmail = rcmail::get_instance();

    $config = array(
        'forced_root_block' => '',
        'force_p_newlines' => false,
        'force_br_newlines' => true,
    );

    $script = sprintf('$.extend(window.rcmail_editor_settings, %s);', json_encode($config));

    $rcmail->output->add_script($script, 'docready');
  }
}

