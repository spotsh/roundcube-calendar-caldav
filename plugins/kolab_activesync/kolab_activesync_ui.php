<?php

/**
 * ActiveSync configuration user interface builder
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
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
 */

class kolab_activesync_ui
{
    private $rc;
    private $plugin;
    public  $device = array();

    public function __construct($plugin)
    {
        $this->plugin    = $plugin;
        $this->rc        = rcube::get_instance();
        $skin_path       = $this->plugin->local_skin_path() . '/';
        $this->skin_path = 'plugins/kolab_activesync/' . $skin_path;

        $this->plugin->include_stylesheet($skin_path . 'config.css');
        $this->rc->output->include_script('list.js');
    }

    public function device_list($attrib = array())
    {
        $attrib += array('id' => 'devices-list');

        $devices = $this->plugin->list_devices();
        $table   = new html_table();

        foreach ($devices as $id => $device) {
            $name = $device['ALIAS'] ? $device['ALIAS'] : $id;
            $table->add_row(array('id' => 'rcmrow' . $id));
            $table->add(null, html::span('devicealias', Q($name)) . html::span('devicetype', Q($device['TYPE'])));
        }

        $this->rc->output->add_gui_object('devicelist', $attrib['id']);
        $this->rc->output->set_env('devicecount', count($devices));

        return $table->show($attrib);
    }


    public function device_config_form($attrib = array())
    {
        $table = new html_table(array('cols' => 2));

        $field_id = 'config-device-alias';
        $input = new html_inputfield(array('name' => 'devicealias', 'id' => $field_id, 'size' => 40));
        $table->add('title', html::label($field_id, $this->plugin->gettext('devicealias')));
        $table->add(null, $input->show($this->device['ALIAS'] ? $this->device['ALIAS'] : $this->device['_id']));
/*
        $field_id = 'config-device-mode';
        $select = new html_select(array('name' => 'syncmode', 'id' => $field_id));
        $select->add(array($this->plugin->gettext('modeauto'), $this->plugin->gettext('modeflat'), $this->plugin->gettext('modefolder')), array('-1', '0', '1'));
        $table->add('title', html::label($field_id, $this->plugin->gettext('syncmode')));
        $table->add(null, $select->show('-1'));

        $field_id = 'config-device-laxpic';
        $checkbox = new html_checkbox(array('name' => 'laxpic', 'value' => '1', 'id' => $field_id));
        $table->add('title', $this->plugin->gettext('imageformat'));
        $table->add(null, html::label($field_id, $checkbox->show() . ' ' . $this->plugin->gettext('laxpiclabel')));
*/
        if ($attrib['form']) {
            $this->rc->output->add_gui_object('editform', $attrib['form']);
        }

        return $table->show($attrib);
    }


    public function folder_subscriptions($attrib = array())
    {
        if (!$attrib['id'])
            $attrib['id'] = 'foldersubscriptions';

        // group folders by type (show only known types)
        $folder_groups = array('mail' => array(), 'contact' => array(), 'event' => array(), 'task' => array());
        $folder_types  = $this->plugin->list_types();
        $imei          = $this->device['_id'];
        $subscribed    = array();

        if ($imei) {
            $folder_meta = $this->plugin->folder_meta();
        }

        foreach ($this->plugin->list_folders() as $folder) {
            if ($folder_types[$folder]) {
                list($type, ) = explode('.', $folder_types[$folder]);
            }
            else {
                $type = 'mail';
            }

            if (is_array($folder_groups[$type])) {
                $folder_groups[$type][] = $folder;

                if (!empty($folder_meta) && ($meta = $folder_meta[$folder])
                    && $meta['FOLDER'] && $meta['FOLDER'][$imei]['S']
                ) {
                    $subscribed[$folder] = intval($meta['FOLDER'][$imei]['S']);
                }
            }
        }

        // build block for every folder type
        foreach ($folder_groups as $type => $group) {
            if (empty($group)) {
                continue;
            }
            $attrib['type'] = $type;
            $html .= html::div('subscriptionblock',
                html::tag('h3', $type, $this->plugin->gettext($type)) .
                $this->folder_subscriptions_block($group, $attrib, $subscribed));
        }

        $this->rc->output->add_gui_object('subscriptionslist', $attrib['id']);

        return html::div($attrib, $html);
    }

    public function folder_subscriptions_block($a_folders, $attrib, $subscribed)
    {
        $alarms = ($attrib['type'] == 'event' || $attrib['type'] == 'task');

        $table = new html_table(array('cellspacing' => 0));
        $table->add_header('subscription', $attrib['syncicon'] ? html::img(array('src' => $this->skin_path . $attrib['syncicon'], 'title' => $this->plugin->gettext('synchronize'))) : '');
        if ($alarms) {
            $table->add_header('alarm', $attrib['alarmicon'] ? html::img(array('src' => $this->skin_path . $attrib['alarmicon'], 'title' => $this->plugin->gettext('withalarms'))) : '');
        }
        $table->add_header('foldername', $this->plugin->gettext('folder'));

        $checkbox_sync  = new html_checkbox(array('name' => 'subscribed[]', 'class' => 'subscription'));
        $checkbox_alarm = new html_checkbox(array('name' => 'alarm[]', 'class' => 'alarm'));

        $names = array();
        foreach ($a_folders as $folder) {
            $foldername = $origname = preg_replace('/^INBOX &raquo;\s+/', '', kolab_storage::object_name($folder));

            // find folder prefix to truncate (the same code as in kolab_addressbook plugin)
            for ($i = count($names)-1; $i >= 0; $i--) {
                if (strpos($foldername, $names[$i].' &raquo; ') === 0) {
                    $length = strlen($names[$i].' &raquo; ');
                    $prefix = substr($foldername, 0, $length);
                    $count  = count(explode(' &raquo; ', $prefix));
                    $foldername = str_repeat('&nbsp;&nbsp;', $count-1) . '&raquo; ' . substr($foldername, $length);
                    break;
                }
            }

            $names[] = $origname;
            $classes = array('mailbox');

            if ($folder_class = $this->rc->folder_classname($folder)) {
                $foldername = html::quote($this->rc->gettext($folder_class));
                $classes[] = $folder_class;
            }

            $folder_id = 'rcmf' . html_identifier($folder);
            $padding = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);

            $table->add_row();
            $table->add('subscription', $checkbox_sync->show(
                !empty($subscribed[$folder]) ? $folder : null,
                array('value' => $folder, 'id' => $folder_id)));

            if ($alarms) {
                $table->add('alarm', $checkbox_alarm->show(
                    intval($subscribed[$folder]) > 1 ? $folder : null,
                    array('value' => $folder, 'id' => $folder_id.'_alarm')));
            }

            $table->add(join(' ', $classes), html::label($folder_id, $padding . $foldername));
        }

        return $table->show();
    }
}
