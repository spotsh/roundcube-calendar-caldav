<?php

/**
 * Kolab configuration storage handler.
 *
 * Copyright (C) 2011, Kolab Systems AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * @author Machniak Aleksander <machniak@kolabsys.com>
 */
class kolab_configuration
{
    public  $dir;
    private $rc;
    private $imap_data = array();

    /**
     * Class constructor. Establishes IMAP connection.
     */
    public function __construct()
    {
        $this->rc = rcmail::get_instance();

        // Connect to IMAP
        $this->rc->imap_connect();

        // Get default configuration directory
        // @TODO: handle many config directories, according to KEP:9
        $dirs = $this->rc->imap->list_unsubscribed('', '*', 'configuration');
        $this->dir = array_shift($dirs);
    }

    /**
     * Returns configuration object
     *
     * @param string $type Configuration object type
     *
     * @return array Configuration object
     */
    public function get($type)
    {
        // If config folder exists
        if (isset($this->dir[0])) {
            // search by object type
            // @TODO: configuration folders shouldn't be big, consider
            // caching of all objects, then if we extend cache with object type
            // column we could simply search in cache.
            // @TODO: handle many config directories, according to KEP:9
            $ctype  = 'application/x-vnd.kolab.configuration' . ($type ? '.'.$type : '');
            $search = 'HEADER X-Kolab-Type ' . $ctype;

            $this->set_imap_props();
            $this->rc->imap->search($this->dir, $search, 'US-ASCII', 'date');

            $list = $this->rc->imap->list_headers($this->dir, 1, 'date', 'DESC');
            $this->reset_imap_props();

            foreach ($list as $idx => $obj) {
                // @TODO: maybe we could skip parsing the message structure and
                // get second part's body directly
                $msg = new rcube_message($obj->uid);
                $xml = null;

                // get XML part
                foreach ((array)$msg->attachments as $part) {
                    if ($part->mimetype == 'application/xml') {
                        if (!$part->body)
                            $part->body = $msg->get_part_content($part->mime_id);
                        $xml = $part->body;
                        break;
                    }
                }

                // that shouldn't happen
                if ($xml === null) {
                    continue;
                }

                // Load XML object parser
                $handler = Horde_Kolab_Format::factory('XML', 'configuration', array('subtype' => $type));
                if (is_object($handler) && is_a($handler, 'PEAR_Error')) {
                    return null;
                }

                // XML-to-array
                $object = $handler->load($xml);

                if (is_object($object) && is_a($object, 'PEAR_Error')) {
                    return null;
                }

                $object['mailbox'] = $this->dir;
                $object['msguid']  = $obj->uid;

                return $object;
            }
        }
    }

    /**
     * Saves configuration object
     *
     * @param array $object Configuration object
     *
     * @return bool True on success, False on failre
     */
    public function set($object)
    {
        $object['uid'] = md5(uniqid(mt_rand(), true));
        $raw_msg = $this->build_message($object);

        if ($raw_msg && isset($this->dir[0])) {
            $result = $this->rc->imap->save_message($this->dir, $raw_msg, '', false);
        }

        // delete old message
        if ($result && !empty($object['msguid'])) {
            $this->rc->imap->delete_message($object['msguid'], $object['mailbox']);
        }

        return $result;
    }

    /**
     * Deletes configuration object
     *
     * @param array $object Configuration object
     *
     * @return bool True on success, False on failre
     */
    public function del($object)
    {
        if (!empty($object['msguid'])) {
            return $this->rc->imap->delete_message($object['msguid'], $object['mailbox']);
        }

        return true;
    }

    /**
     * Sets some rcube_imap settings before searching for config objects
     */
    private function set_imap_props()
    {
        // Save old settings
        $this->imap_data = array(
            'skip_deleted' => $skip_deleted,
            'page_size' => $page_size,
            'list_page' => $list_page,
            'threading' => $threading,
        );

        // Configure for configuration folder
        $this->rc->imap->skip_deleted = true;
        $this->rc->imap->threading    = false;
        $this->rc->imap->page_size    = 100;
        $this->rc->imap->list_page    = 1;
    }

    /**
     * Resets rcube_imap settings after searching for config objects
     */
    private function reset_imap_props()
    {
        // Reset to old settings
        foreach ($this->imap_data as $key => $val) {
            $this->rc->imap->$key = $val;
        }
        // reset saved search
        $this->rc->imap->search_set = null;
    }

    /**
     * Creates source of the configuration object message
     */
    private function build_message($object)
    {
        $MAIL_MIME = new Mail_mime("\r\n");

        $name = 'configuration';

        if (!empty($object['type'])) {
            $name .= '.' . $object['type'];
            if ($object['type'] == 'dictionary' && !empty($object['language'])) {
                $name .= '.' . $object['language'];
            }
        }

        $handler = Horde_Kolab_Format::factory('XML', 'configuration',
            array('subtype' => $object['type']));

        if (is_object($handler) && is_a($handler, 'PEAR_Error')) {
            return false;
        }

        $xml = $handler->save($object);

        if (is_object($xml) && is_a($xml, 'PEAR_Error')) {
            return false;
        }

        if ($ident = $this->rc->user->get_identity()) {
            $headers['From'] = $ident['email'];
            $headers['To'] = $ident['email'];
        }
        $headers['Date'] = date('r');
        $headers['X-Kolab-Type'] = 'application/x-vnd.kolab.'.$name;
        $headers['Subject'] = $object['uid'];
        $headers['Message-ID'] = rcmail_gen_message_id();
        $headers['User-Agent'] = $this->rc->config->get('useragent');

        $MAIL_MIME->headers($headers);
        $MAIL_MIME->setTXTBody('This is a Kolab Groupware object. '
            .'To view this object you will need an email client that understands the Kolab Groupware format. '
            .'For a list of such email clients please visit http://www.kolab.org/kolab2-clients.html');

        $MAIL_MIME->addAttachment($xml,
            'application/xml',
            $name . '.xml',
            false, '8bit', 'attachment', RCMAIL_CHARSET, '', '',
            $this->rc->config->get('mime_param_folding') ? 'quoted-printable' : null,
            $this->rc->config->get('mime_param_folding') == 2 ? 'quoted-printable' : null,
            '', RCMAIL_CHARSET
        );

        return $MAIL_MIME->getMessage();
    }

}
