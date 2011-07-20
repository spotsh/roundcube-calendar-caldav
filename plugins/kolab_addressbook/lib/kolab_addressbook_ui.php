<?php

/**
 * Kolab address book UI
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011, Kolab Systems AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
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
        $this->rc     = rcmail::get_instance();
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
        $name   = trim(get_input_value('_name', RCUBE_INPUT_GPC, true)); // UTF8
        $old    = trim(get_input_value('_oldname', RCUBE_INPUT_GPC, true)); // UTF7-IMAP
        $path_imap = trim(get_input_value('_parent', RCUBE_INPUT_GPC, true)); // UTF7-IMAP

        $hidden_fields[] = array('name' => '_source', 'value' => $folder);

        $folder = rcube_charset_convert($folder, RCMAIL_CHARSET, 'UTF7-IMAP');
        $delim  = $_SESSION['imap_delimiter'];
        $form   = array();

        if ($this->rc->action == 'plugin.book-save') {
            // save error
            $path_imap = $folder;
            $hidden_fields[] = array('name' => '_oldname', 'value' => $old);

            if (strlen($old)) {
                $this->rc->imap_connect();
                $options = $this->rc->imap->mailbox_info($old);
            }
        }
        else if ($action == 'edit') {
            $path_imap = explode($delim, $folder);
            $name      = rcube_charset_convert(array_pop($path_imap), 'UTF7-IMAP');
            $path_imap = implode($path_imap, $delim);

            $this->rc->imap_connect();
            $options = $this->rc->imap->mailbox_info($folder);

            $hidden_fields[] = array('name' => '_oldname', 'value' => $folder);
        }
        else {
            $path_imap = $folder;
            $name      = '';
        }

        // General tab
        $form['props'] = array(
            'name' => $this->rc->gettext('properties'),
        );

        $foldername = new html_inputfield(array('name' => '_name', 'id' => '_name', 'size' => 30));
        $foldername = $foldername->show($name);

        $form['props']['fieldsets']['location'] = array(
            'name'  => $this->rc->gettext('location'),
            'content' => array(
                'name' => array(
                    'label' => $this->plugin->gettext('bookname'),
                    'value' => $foldername,
                ),
            ),
        );

        if (!empty($options) && ($options['norename'] || $options['namespace'] != 'personal')) {
            // prevent user from moving folder
            $hidden_fields[] = array('name' => '_parent', 'value' => $path_imap);
        }
        else {
            $select = rcube_kolab::folder_selector('contact', array('name' => '_parent'));

            $form['props']['fieldsets']['location']['content']['path'] = array(
                'label' => $this->plugin->gettext('parentbook'),
                'value' => $select->show($path_imap),
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
            $table = new html_table(array('cols' => 2));
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
