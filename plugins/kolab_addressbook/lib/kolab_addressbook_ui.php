<?php

/**
 * Kolab address book UI
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
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
class kolab_addressbook_ui
{
    private $plugin;
    private $rc;

    /**
     * Class constructor
     *
     * @param kolab_addressbook $plugin Plugin object
     */
    public function __construct($plugin)
    {
        $this->rc     = rcube::get_instance();
        $this->plugin = $plugin;

        $this->init_ui();
    }

    /**
     * Adds folders management functionality to Addressbook UI
     */
    private function init_ui()
    {
        if (!empty($this->rc->action) && !preg_match('/^plugin\.book/', $this->rc->action)) {
            return;
        }

        // Include script
        $this->plugin->include_script('kolab_addressbook.js');

        if (empty($this->rc->action)) {
            // Include stylesheet (for directorylist)
            $this->plugin->include_stylesheet($this->plugin->local_skin_path().'/kolab_addressbook.css');

            // Add actions on address books
            $options = array('book-create', 'book-edit', 'book-delete');
            $idx     = 0;

            foreach ($options as $command) {
                $content = html::tag('li', $idx ? null : array('class' => 'separator_above'),
                    $this->plugin->api->output->button(array(
                        'label'    => 'kolab_addressbook.'.str_replace('-', '', $command),
                        'domain'   => $this->ID,
                        'classact' => 'active',
                        'command'  => $command
                )));
                $this->plugin->api->add_content($content, 'groupoptions');
                $idx++;
            }

            // Link to Settings/Folders
            $content = html::tag('li', array('class' => 'separator_above'),
                $this->plugin->api->output->button(array(
                    'label'    => 'managefolders',
                    'type'     => 'link',
                    'classact' => 'active',
                    'command'  => 'folders',
                    'task'     => 'settings',
            )));
            $this->plugin->api->add_content($content, 'groupoptions');

            $this->rc->output->add_label('kolab_addressbook.bookdeleteconfirm',
                'kolab_addressbook.bookdeleting');
        }
        // book create/edit form
        else {
            $this->rc->output->add_label('kolab_addressbook.nobooknamewarning',
                'kolab_addressbook.booksaving');
        }
    }


    /**
     * Handler for address book create/edit action
     */
    public function book_edit()
    {
        $this->rc->output->add_handler('bookdetails', array($this, 'book_form'));
        $this->rc->output->send('kolab_addressbook.bookedit');
    }


    /**
     * Handler for 'bookdetails' object returning form content for book create/edit
     *
     * @param array $attr Object attributes
     *
     * @return string HTML output
     */
    public function book_form($attrib)
    {
        $action = trim(get_input_value('_act', RCUBE_INPUT_GPC));
        $folder = trim(get_input_value('_source', RCUBE_INPUT_GPC, true)); // UTF8

        $hidden_fields[] = array('name' => '_source', 'value' => $folder);

        $folder  = rcube_charset::convert($folder, RCMAIL_CHARSET, 'UTF7-IMAP');
        $storage = $this->rc->get_storage();
        $delim   = $storage->get_hierarchy_delimiter();

        if ($this->rc->action == 'plugin.book-save') {
            // save error
            $name      = trim(get_input_value('_name', RCUBE_INPUT_GPC, true)); // UTF8
            $old       = trim(get_input_value('_oldname', RCUBE_INPUT_GPC, true)); // UTF7-IMAP
            $path_imap = trim(get_input_value('_parent', RCUBE_INPUT_GPC, true)); // UTF7-IMAP

            $hidden_fields[] = array('name' => '_oldname', 'value' => $old);

            $folder = $old;
        }
        else if ($action == 'edit') {
            $path_imap = explode($delim, $folder);
            $name      = rcube_charset::convert(array_pop($path_imap), 'UTF7-IMAP');
            $path_imap = implode($path_imap, $delim);
        }
        else { // create
            $path_imap = $folder;
            $name      = '';
            $folder    = '';
        }

        // Store old name, get folder options
        if (strlen($folder)) {
            $hidden_fields[] = array('name' => '_oldname', 'value' => $folder);

            $options = $storage->folder_info($folder);
        }

        $form   = array();

        // General tab
        $form['props'] = array(
            'name' => $this->rc->gettext('properties'),
        );

        if (!empty($options) && ($options['norename'] || $options['protected'])) {
            $foldername = Q(str_replace($delim, ' &raquo; ', kolab_storage::object_name($folder)));
        }
        else {
            $foldername = new html_inputfield(array('name' => '_name', 'id' => '_name', 'size' => 30));
            $foldername = $foldername->show($name);
        }

        $form['props']['fieldsets']['location'] = array(
            'name'  => $this->rc->gettext('location'),
            'content' => array(
                'name' => array(
                    'label' => $this->plugin->gettext('bookname'),
                    'value' => $foldername,
                ),
            ),
        );

        if (!empty($options) && ($options['norename'] || $options['protected'])) {
            // prevent user from moving folder
            $hidden_fields[] = array('name' => '_parent', 'value' => $path_imap);
        }
        else {
            $select = kolab_storage::folder_selector('contact', array('name' => '_parent'), $folder);

            $form['props']['fieldsets']['location']['content']['path'] = array(
                'label' => $this->plugin->gettext('parentbook'),
                'value' => $select->show(strlen($folder) ? $path_imap : ''),
            );
        }

        // Allow plugins to modify address book form content (e.g. with ACL form)
        $plugin = $this->rc->plugins->exec_hook('addressbook_form',
            array('form' => $form, 'options' => $options, 'name' => $folder));

        $form = $plugin['form'];

        // Set form tags and hidden fields
        list($form_start, $form_end) = $this->get_form_tags($attrib, 'plugin.book-save', null, $hidden_fields);

        unset($attrib['form']);

        // return the complete edit form as table
        $out = "$form_start\n";

        // Create form output
        foreach ($form as $tab) {
            if (!empty($tab['fieldsets']) && is_array($tab['fieldsets'])) {
                $content = '';
                foreach ($tab['fieldsets'] as $fieldset) {
                    $subcontent = $this->get_form_part($fieldset);
                    if ($subcontent) {
                        $content .= html::tag('fieldset', null, html::tag('legend', null, Q($fieldset['name'])) . $subcontent) ."\n";
                    }
                }
            }
            else {
                $content = $this->get_form_part($tab);
            }

            if ($content) {
                $out .= html::tag('fieldset', null, html::tag('legend', null, Q($tab['name'])) . $content) ."\n";
            }
        }

        $out .= "\n$form_end";

        return $out;
    }


    private function get_form_part($form)
    {
        $content = '';

        if (is_array($form['content']) && !empty($form['content'])) {
            $table = new html_table(array('cols' => 2, 'class' => 'propform'));
            foreach ($form['content'] as $col => $colprop) {
                $colprop['id'] = '_'.$col;
                $label = !empty($colprop['label']) ? $colprop['label'] : rcube_label($col);

                $table->add('title', sprintf('<label for="%s">%s</label>', $colprop['id'], Q($label)));
                $table->add(null, $colprop['value']);
            }
            $content = $table->show();
        }
        else {
            $content = $form['content'];
        }

        return $content;
    }


    private function get_form_tags($attrib, $action, $id = null, $hidden = null)
    {
        $form_start = $form_end = '';

        $request_key = $action . (isset($id) ? '.'.$id : '');
        $form_start = $this->rc->output->request_form(array(
            'name'    => 'form',
            'method'  => 'post',
            'task'    => $this->rc->task,
            'action'  => $action,
            'request' => $request_key,
            'noclose' => true,
        ) + $attrib);

        if (is_array($hidden)) {
            foreach ($hidden as $field) {
                $hiddenfield = new html_hiddenfield($field);
                $form_start .= $hiddenfield->show();
            }
        }

        $form_end = !strlen($attrib['form']) ? '</form>' : '';

        $EDIT_FORM = !empty($attrib['form']) ? $attrib['form'] : 'form';
        $this->rc->output->add_gui_object('editform', $EDIT_FORM);

        return array($form_start, $form_end);
    }

}
