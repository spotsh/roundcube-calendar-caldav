<?php

/**
 * Z-Push configuration utility for Kolab accounts
 *
 * @version 0.2
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_zpush extends rcube_plugin
{
    public $task = 'settings';
    public $urlbase;
    
    private $rc;
    private $ui;
    private $cache;
    private $devices;
    private $folders;
    private $folders_meta;
    private $root_meta;
    
    const ROOT_MAILBOX = 'INBOX';
    const CTYPE_KEY = '/shared/vendor/kolab/folder-type';
    const ACTIVESYNC_KEY = '/private/vendor/kolab/activesync';

    /**
     * Plugin initialization.
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();
        
        $this->require_plugin('jqueryui');
        $this->add_texts('localization/', true);
        
        $this->include_script('kolab_zpush.js');
        
        $this->register_action('plugin.zpushconfig', array($this, 'config_view'));
        $this->register_action('plugin.zpushjson', array($this, 'json_command'));
        
        if ($this->rc->action == 'plugin.zpushconfig')
          $this->require_plugin('kolab_core');
    }


    /**
     * Establish IMAP connection
     */
    public function init_imap()
    {
        $storage = $this->rc->get_storage();

        $this->cache = $this->rc->get_cache('zpush', 'db', 900);
        $this->cache->expunge();

        if ($meta = $storage->get_metadata(self::ROOT_MAILBOX, self::ACTIVESYNC_KEY)) {
            // clear cache if device config changed
            if (($oldmeta = $this->cache->read('devicemeta')) && $oldmeta != $meta)
                $this->cache->remove();

            $this->root_meta = $this->unserialize_metadata($meta[self::ROOT_MAILBOX][self::ACTIVESYNC_KEY]);
            $this->cache->remove('devicemeta');
            $this->cache->write('devicemeta', $meta);
        }
    }


    /**
     * Handle JSON requests
     */
    public function json_command()
    {
        $storage = $this->rc->get_storage();
        $cmd     = get_input_value('cmd', RCUBE_INPUT_GPC);
        $imei    = get_input_value('id', RCUBE_INPUT_GPC);

        switch ($cmd) {
        case 'load':
            $result = array();
            $devices = $this->list_devices();
            if ($device = $devices[$imei]) {
                $result['id'] = $imei;
                $result['devicealias'] = $device['ALIAS'];
                $result['syncmode'] = intval($device['MODE']);
                $result['laxpic'] = intval($device['LAXPIC']);
                $result['subscribed'] = array();

                foreach ($this->folders_meta() as $folder => $meta) {
                    if ($meta[$imei]['S'])
                        $result['subscribed'][$folder] = intval($meta[$imei]['S']);
                }

                $this->rc->output->command('plugin.zpush_data_ready', $result);
            }
            else {
                $this->rc->output->show_message($this->gettext('devicenotfound'), 'error');
            }
            break;

        case 'save':
            $devices = $this->list_devices();
            $syncmode = intval(get_input_value('syncmode', RCUBE_INPUT_POST));
            $devicealias = get_input_value('devicealias', RCUBE_INPUT_POST, true);
            $laxpic = intval(get_input_value('laxpic', RCUBE_INPUT_POST));
            $subsciptions = get_input_value('subscribed', RCUBE_INPUT_POST);
            $err = false;
            
            if ($device = $devices[$imei]) {
                // update device config if changed
                if ($devicealias != $this->root_meta['DEVICE'][$imei]['ALIAS']  ||
                       $syncmode != $this->root_meta['DEVICE'][$imei]['MODE']   ||
                       $laxpic   != $this->root_meta['DEVICE'][$imei]['LAXPIC'] ||
                       $subsciptions[self::ROOT_MAILBOX] != $this->root_meta['FOLDER'][$imei]['S']) {
                    $this->root_meta['DEVICE'][$imei]['MODE'] = $syncmode;
                    $this->root_meta['DEVICE'][$imei]['ALIAS'] = $devicealias;
                    $this->root_meta['DEVICE'][$imei]['LAXPIC'] = $laxpic;
                    $this->root_meta['FOLDER'][$imei]['S'] = intval($subsciptions[self::ROOT_MAILBOX]);

                    $err = !$storage->set_metadata(self::ROOT_MAILBOX,
                        array(self::ACTIVESYNC_KEY => $this->serialize_metadata($this->root_meta)));

                    // update cached meta data
                    if (!$err) {
                        $this->cache->remove('devicemeta');
                        $this->cache->write('devicemeta', $storage->get_metadata(self::ROOT_MAILBOX, self::ACTIVESYNC_KEY));
                    }
                }
                // iterate over folders list and update metadata if necessary
                foreach ($this->folders_meta() as $folder => $meta) {
                    // skip root folder (already handled above)
                    if ($folder == self::ROOT_MAILBOX)
                        continue;
                    
                    if ($subsciptions[$folder] != $meta[$imei]['S']) {
                        $meta[$imei]['S'] = intval($subsciptions[$folder]);
                        $this->folders_meta[$folder] = $meta;
                        unset($meta['TYPE']);
                        
                        // read metadata first
                        $folderdata = $storage->get_metadata($folder, array(self::ACTIVESYNC_KEY));
                        if ($asyncdata = $folderdata[$folder][self::ACTIVESYNC_KEY])
                            $metadata = $this->unserialize_metadata($asyncdata);
                        $metadata['FOLDER'] = $meta;

                        $err |= !$storage->set_metadata($folder, array(self::ACTIVESYNC_KEY => $this->serialize_metadata($metadata)));
                    }
                }
                
                // update cache
                $this->cache->remove('folders');
                $this->cache->write('folders', $this->folders_meta);
                
                $this->rc->output->command('plugin.zpush_save_complete', array('success' => !$err, 'id' => $imei, 'devicealias' => Q($devicealias)));
            }
            
            if ($err)
                $this->rc->output->show_message($this->gettext('savingerror'), 'error');
            else
                $this->rc->output->show_message($this->gettext('successfullysaved'), 'confirmation');
            
            break;

        case 'delete':
            $this->init_imap();
            $devices = $this->list_devices();
            
            if ($device = $devices[$imei]) {
                unset($this->root_meta['DEVICE'][$imei], $this->root_meta['FOLDER'][$imei]);

                // update annotation and cached meta data
                if ($success = $storage->set_metadata(self::ROOT_MAILBOX, array(self::ACTIVESYNC_KEY => $this->serialize_metadata($this->root_meta)))) {
                    $this->cache->remove('devicemeta');
                    $this->cache->write('devicemeta', $storage->get_metadata(self::ROOT_MAILBOX, self::ACTIVESYNC_KEY));

                    // remove device annotation in every folder
                    foreach ($this->folders_meta() as $folder => $meta) {
                        // skip root folder (already handled above)
                        if ($folder == self::ROOT_MAILBOX)
                            continue;

                        if (isset($meta[$imei])) {
                            $type = $meta['TYPE'];  // remember folder type
                            unset($meta[$imei], $meta['TYPE']);

                            // read metadata first and update FOLDER property
                            $folderdata = $storage->get_metadata($folder, array(self::ACTIVESYNC_KEY));
                            if ($asyncdata = $folderdata[$folder][self::ACTIVESYNC_KEY])
                                $metadata = $this->unserialize_metadata($asyncdata);
                            $metadata['FOLDER'] = $meta;

                            if ($storage->set_metadata($folder, array(self::ACTIVESYNC_KEY => $this->serialize_metadata($metadata)))) {
                                $this->folders_meta[$folder] = $metadata;
                                $this->folders_meta[$folder]['TYPE'] = $type;
                            }
                        }
                    }

                    // update cache
                    $this->cache->remove('folders');
                    $this->cache->write('folders', $this->folders_meta);
                }
            }

            if ($success) {
                $this->rc->output->show_message($this->gettext('successfullydeleted'), 'confirmation');
                $this->rc->output->redirect(array('action' => 'plugin.zpushconfig'));  // reload UI
            }
            else
                $this->rc->output->show_message($this->gettext('savingerror'), 'error');

            break;
        }

        $this->rc->output->send();
    }


    /**
     * Render main UI for device configuration
     */
    public function config_view()
    {
        require_once $this->home . '/kolab_zpush_ui.php';
        
        $storage = $this->rc->get_storage();
        
        // checks if IMAP server supports any of METADATA, ANNOTATEMORE, ANNOTATEMORE2
        if (!($storage->get_capability('METADATA') || $storage->get_capability('ANNOTATEMORE') || $storage->get_capability('ANNOTATEMORE2'))) {
            $this->rc->output->show_message($this->gettext('notsupported'), 'error');
        }
        
        $this->ui = new kolab_zpush_ui($this);
        
        $this->register_handler('plugin.devicelist', array($this->ui, 'device_list'));
        $this->register_handler('plugin.deviceconfigform', array($this->ui, 'device_config_form'));
        $this->register_handler('plugin.foldersubscriptions', array($this->ui, 'folder_subscriptions'));
        
        $this->rc->output->set_env('devicecount', count($this->list_devices()));
        $this->rc->output->send('kolab_zpush.config');
    }


    /**
     * List known devices
     *
     * @return array Device list as hash array
     */
    public function list_devices()
    {
        if (!isset($this->devices)) {
            $this->devices = (array)$this->root_meta['DEVICE'];
        }
        
        return $this->devices;
    }


    /**
     * Get list of all folders available for sync
     *
     * @return array List of mailbox folders
     */
    public function list_folders()
    {
        if (!isset($this->folders)) {
            // read cached folder meta data
            if ($cached_folders = $this->cache->read('folders')) {
                $this->folders_meta = $cached_folders;
                $this->folders = array_keys($this->folders_meta);
            }
            // fetch folder data from server
            else {
                $storage       = $this->rc->get_storage();
                $this->folders = $storage->list_folders();

                foreach ($this->folders as $folder) {
                    $folderdata = $storage->get_metadata($folder, array(self::ACTIVESYNC_KEY, self::CTYPE_KEY));
                    $foldertype = explode('.', $folderdata[$folder][self::CTYPE_KEY]);

                    if ($asyncdata = $folderdata[$folder][self::ACTIVESYNC_KEY]) {
                        $metadata = $this->unserialize_metadata($asyncdata);
                        $this->folders_meta[$folder] = $metadata['FOLDER'];
                    }
                    $this->folders_meta[$folder]['TYPE'] = !empty($foldertype[0]) ? $foldertype[0] : 'mail';
                }
                
                // cache it!
                $this->cache->write('folders', $this->folders_meta);
            }
        }

        return $this->folders;
    }

    /**
     * Getter for folder metadata
     *
     * @return array Hash array with meta data for each folder
     */
    public function folders_meta()
    {
        if (!isset($this->folders_meta))
            $this->list_folders();
        
        return $this->folders_meta;
    }

    /**
     * Helper method to decode saved IMAP metadata
     */
    private function unserialize_metadata($str)
    {
        if (!empty($str))
            return @json_decode(base64_decode($str), true);

        return null;
    }

    /**
     * Helper method to encode IMAP metadata for saving
     */
    private function serialize_metadata($data)
    {
        if (is_array($data))
            return base64_encode(json_encode($data));

        return '';
    }

}
