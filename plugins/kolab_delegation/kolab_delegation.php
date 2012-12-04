<?php

/**
 * Delegation configuration utility for Kolab accounts
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
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

class kolab_delegation extends rcube_plugin
{
    public $task = 'login|mail|settings';

    private $rc;
    private $engine;


    /**
     * Plugin initialization.
     */
    public function init()
    {
        $this->rc = rcube::get_instance();

        $this->require_plugin('libkolab');
        $this->require_plugin('kolab_auth');

        $this->add_hook('login_after', array($this, 'login_hook'));
        $this->add_hook('ready',       array($this, 'ready_hook'));

        if ($this->rc->task == 'settings') {
            $this->register_action('plugin.delegation',              array($this, 'controller_ui'));
            $this->register_action('plugin.delegation-delete',       array($this, 'controller_action'));
            $this->register_action('plugin.delegation-save',         array($this, 'controller_action'));
            $this->register_action('plugin.delegation-autocomplete', array($this, 'controller_action'));

            if ($this->rc->action == 'plugin.delegation' || empty($_REQUEST['_framed'])) {
                $this->add_texts('localization/', array('tabtitle', 'delegatedeleteconfirm', 'savingdata'));
                $this->include_script('kolab_delegation.js');
                $skin_path = $this->local_skin_path();
                $this->include_stylesheet("$skin_path/style.css");
            }
        }
    }

    /**
     * Engine object getter
     */
    private function engine()
    {
        if (!$this->engine) {
            require_once $this->home . '/kolab_delegation_engine.php';

            $this->load_config();
            $this->engine = new kolab_delegation_engine();
        }

        return $this->engine;
    }

    /**
     * On-login action
     */
    public function login_hook($args)
    {
        // Fetch all delegators from LDAP who assigned the
        // current user as their delegate and create identities
        //  a) if identity with delegator's email exists, continue
        //  b) create identity ($delegate on behalf of $delegator
        //        <$delegator-email>) for new delegators
        //  c) remove all other identities which do not match the user's primary
        //       or alias email if 'kolab_delegation_purge_identities' is set.

        $engine     = $this->engine();
        $delegators = $engine->list_delegators();

        if (empty($delegators)) {
            return $args;
        }

        $storage    = $this->rc->get_storage();
        $other_ns   = $storage->get_namespace('other');
        $folders    = $storage->list_folders();
        $identities = $this->rc->user->list_identities();

        // convert identities to simpler format for faster access
        foreach ($identities as $idx => $ident) {
            // get user name from default identity
            if (!$idx) {
                $default = array(
                    'name'           => $ident['name'],
//                    'organization'   => $ident['organization'],
//                    'signature'      => $ident['signature'],
//                    'html_signature' => $ident['html_signature'],
                );
            }
            $identities[$idx] = $ident['email'];
        }

        // for every delegator...
        foreach ($delegators as $delegator) {
            $email_arr = (array)$delegator['email'];
            $diff      = array_intersect($identities, $email_arr);

            // identity with delegator's email already exist, do nothing
            if (count($diff)) {
                continue;
            }

            // create identities for delegator emails
            foreach ($email_arr as $email) {
                $default['email'] = $email;
                $this->rc->user->insert_identity($default);
            }

            // IMAP folders shared by new delegators shall be subscribed on login,
            // as well as existing subscriptions of previously shared folders shall
            // be removed. I suppose the latter one is already done in Roundcube.

            // for every accessible folder...
            foreach ($folders as $folder) {
                // for every 'other' namespace root...
                foreach ($other_ns as $ns) {
                    $prefix = $ns[0] . $delegator['imap_uid'];
                    // subscribe delegator's folder
                    if ($folder === $prefix || strpos($folder, $prefix . substr($ns[0], -1)) === 0) {
                        $storage->subscribe($folder);
                    }
                }
            }
        }

        return $args;
    }

    /**
     * On-ready action
     */
    public function ready_hook($args)
    {
        // Checking for new messages shall be extended to Inbox folders of all
        // delegators if 'check_all_folders' is set to false.

        if ($this->rc->task != 'mail') {
            return $args;
        }

        if ($this->rc->action != 'refresh') {
            return $args;
        }

        if ($this->rc->config->get('check_all_folders')) {
            return $args;
        }

        $engine = $this->engine();

        // @TODO
        // new plugin API hook required in core

        return $args;
    }

    /**
     * Delegation UI handler
     */
    public function controller_ui()
    {
        // main interface (delegates list)
        if (empty($_REQUEST['_framed'])) {
            $this->register_handler('plugin.delegatelist', array($this, 'delegate_list'));

            $this->rc->output->include_script('list.js');
            $this->rc->output->send('kolab_delegation.settings');
        }
        // delegate frame
        else {
            $this->register_handler('plugin.delegateform', array($this, 'delegate_form'));
            $this->register_handler('plugin.delegatefolders', array($this, 'delegate_folders'));

            $this->rc->output->set_env('autocomplete_max', (int)$this->rc->config->get('autocomplete_max', 15));
            $this->rc->output->set_env('autocomplete_min_length', $this->rc->config->get('autocomplete_min_length'));
            $this->rc->output->add_label('autocompletechars', 'autocompletemore');

            $this->rc->output->send('kolab_delegation.editform');
        }
    }

    /**
     * Delegation action handler
     */
    public function controller_action()
    {
        $this->add_texts('localization/', true);

        $engine = $this->engine();

        // Delegate delete
        if ($this->rc->action == 'plugin.delegation-delete') {
            $id      = get_input_value('id', RCUBE_INPUT_GPC);
            $success = $engine->delegate_delete($id, true);

            if ($success) {
                $this->rc->output->show_message($this->gettext('deletesuccess'), 'confirmation');
                $this->rc->output->command('plugin.delegate_save_complete', array('deleted' => $id));
            }
            else {
                $this->rc->output->show_message($this->gettext('deleteerror'), 'error');
            }
        }
        // Delegate add/update
        else if ($this->rc->action == 'plugin.delegation-save') {
            $id  = get_input_value('id', RCUBE_INPUT_GPC);
            $acl = get_input_value('folders', RCUBE_INPUT_GPC);

            // update
            if ($id) {
                $delegate = $engine->delegate_get($id);
                $success  = $engine->delegate_acl_update($delegate['uid'], $acl);

                if ($success) {
                    $this->rc->output->show_message($this->gettext('updatesuccess'), 'confirmation');
                    $this->rc->output->command('plugin.delegate_save_complete', array('updated' => $id));
                }
                else {
                    $this->rc->output->show_message($this->gettext('updateerror'), 'error');
                }
            }
            // new
            else {
                $login    = get_input_value('newid', RCUBE_INPUT_GPC);
                $delegate = $engine->delegate_get_by_name($login);
                $success  = $engine->delegate_add($delegate, $acl);

                if ($success) {
                    $this->rc->output->show_message($this->gettext('createsuccess'), 'confirmation');
                    $this->rc->output->command('plugin.delegate_save_complete', array(
                        'created' => $delegate['ID'],
                        'name'    => $delegate['name'],
                    ));
                }
                else {
                    $this->rc->output->show_message($this->gettext('createerror'), 'error');
                }
            }
        }
        // Delegate autocompletion
        else if ($this->rc->action == 'plugin.delegation-autocomplete') {
            $search = get_input_value('_search', RCUBE_INPUT_GPC, true);
            $sid    = get_input_value('_id', RCUBE_INPUT_GPC);
            $users  = $engine->list_users($search);

            $this->rc->output->command('ksearch_query_results', $users, $search, $sid);
        }

        $this->rc->output->send();
    }

    /**
     * Template object of delegates list
     */
    public function delegate_list($attrib = array())
    {
        $attrib += array('id' => 'delegate-list');

        $engine = $this->engine();
        $list   = $engine->list_delegates();
        $table  = new html_table();

        // sort delegates list
        asort($list, SORT_LOCALE_STRING);

        foreach ($list as $id => $delegate) {
            $name = $id;
            $table->add_row(array('id' => 'rcmrow' . $id));
            $table->add(null, Q($delegate));
        }

        $this->rc->output->add_gui_object('delegatelist', $attrib['id']);
        $this->rc->output->set_env('delegatecount', count($list));

        return $table->show($attrib);
    }

    /**
     * Template object of delegate form
     */
    public function delegate_form($attrib = array())
    {
        $engine   = $this->engine();
        $table    = new html_table(array('cols' => 2));
        $id       = get_input_value('_id', RCUBE_INPUT_GPC);
        $field_id = 'delegate';

        if ($id) {
            $delegate = $engine->delegate_get($id);
        }

        if ($delegate) {
            $input = new html_hiddenfield(array('name' => $field_id, 'id' => $field_id, 'size' => 40));
            $input = Q($delegate['name']) . $input->show($id);

            $this->rc->output->set_env('active_delegate', $id);
            $this->rc->output->command('parent.enable_command','delegate-delete', true);
        }
        else {
            $input = new html_inputfield(array('name' => $field_id, 'id' => $field_id, 'size' => 40));
            $input = $input->show();
        }

        $table->add('title', html::label($field_id, $this->gettext('delegate')));
        $table->add(null, $input);

        if ($attrib['form']) {
            $this->rc->output->add_gui_object('editform', $attrib['form']);
        }

        return $table->show($attrib);
    }

    /**
     * Template object of folders list
     */
    public function delegate_folders($attrib = array())
    {
        if (!$attrib['id']) {
            $attrib['id'] = 'delegatefolders';
        }

        $engine = $this->engine();
        $id     = get_input_value('_id', RCUBE_INPUT_GPC);

        if ($id) {
            $delegate = $engine->delegate_get($id);
        }

        $folder_data   = $engine->list_folders($delegate['uid']);
        $rights        = array();
        $folders       = array();
        $folder_groups = array();

        foreach ($folder_data as $folder_name => $folder) {
            $folder_groups[$folder['type']][] = $folder_name;
            $rights[$folder_name] = $folder['rights'];
        }

        // build block for every folder type
        foreach ($folder_groups as $type => $group) {
            if (empty($group)) {
                continue;
            }
            $attrib['type'] = $type;
            $html .= html::div('foldersblock',
                html::tag('h3', $type, $this->gettext($type)) .
                    $this->delegate_folders_block($group, $attrib, $rights));
        }

        $this->rc->output->add_gui_object('folderslist', $attrib['id']);

        return html::div($attrib, $html);
    }

    /**
     * List of folders in specified group
     */
    private function delegate_folders_block($a_folders, $attrib, $rights)
    {
        $read_ico  = $attrib['readicon'] ? html::img(array('src' => $this->skin_path . $attrib['readicon'], 'title' => $this->gettext('read'))) : '';
        $write_ico = $attrib['writeicon'] ? html::img(array('src' => $this->skin_path . $attrib['writeicon'], 'title' => $this->gettext('write'))) : '';

        $table = new html_table(array('cellspacing' => 0));
        $table->add_header('read', $read_ico);
        $table->add_header('write', $write_ico);
        $table->add_header('foldername', $this->rc->gettext('folder'));

        $checkbox_read  = new html_checkbox(array('name' => 'read[]', 'class' => 'read'));
        $checkbox_write = new html_checkbox(array('name' => 'write[]', 'class' => 'write'));

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
            $table->add('read', $checkbox_read->show(
                $rights[$folder] >= kolab_delegation_engine::ACL_READ ? $folder : null,
                array('value' => $folder)));
            $table->add('write', $checkbox_write->show(
                $rights[$folder] >= kolab_delegation_engine::ACL_WRITE ? $folder : null,
                array('value' => $folder, 'id' => $folder_id)));

            $table->add(join(' ', $classes), html::label($folder_id, $padding . $foldername));
        }

        return $table->show();
    }
}
