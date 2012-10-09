<?php

/**
 * The kolab_storage_folder class represents an IMAP folder on the Kolab server.
 *
 * @version @package_version@
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
class kolab_storage_folder
{
    /**
     * The folder name.
     * @var string
     */
    public $name;

    /**
     * The type of this folder.
     * @var string
     */
    public $type;

    /**
     * Is this folder set to be the default for its type
     * @var boolean
     */
    public $default = false;

    /**
     * Is this folder set to be default
     * @var boolean
     */
    public $cache;

    private $type_annotation;
    private $imap;
    private $info;
    private $owner;
    private $resource_uri;
    private $uid2msg = array();


    /**
     * Default constructor
     */
    function __construct($name, $type = null)
    {
        $this->imap = rcube::get_instance()->get_storage();
        $this->imap->set_options(array('skip_deleted' => true));
        $this->cache = new kolab_storage_cache($this);
        $this->set_folder($name, $type);
    }


    /**
     * Set the IMAP folder this instance connects to
     *
     * @param string The folder name/path
     * @param string Optional folder type if known
     */
    public function set_folder($name, $ftype = null)
    {
        $this->type_annotation = $ftype ? $ftype : kolab_storage::folder_type($name);

        list($this->type, $suffix) = explode('.', $this->type_annotation);
        $this->default      = $suffix == 'default';
        $this->name         = $name;
        $this->resource_uri = null;

        $this->imap->set_folder($this->name);
        $this->cache->set_folder($this);
    }


    /**
     *
     */
    private function get_folder_info()
    {
        if (!isset($this->info))
            $this->info = $this->imap->folder_info($this->name);

        return $this->info;
    }


    /**
     * Returns IMAP metadata/annotations (GETMETADATA/GETANNOTATION)
     *
     * @param array List of metadata keys to read
     * @return array Metadata entry-value hash array on success, NULL on error
     */
    public function get_metadata($keys)
    {
        $metadata = $this->imap->get_metadata($this->name, (array)$keys);
        return $metadata[$this->name];
    }


    /**
     * Sets IMAP metadata/annotations (SETMETADATA/SETANNOTATION)
     *
     * @param array  $entries Entry-value array (use NULL value as NIL)
     * @return boolean True on success, False on failure
     */
    public function set_metadata($entries)
    {
        return $this->imap->set_metadata($this->name, $entries);
    }


    /**
     * Returns the owner of the folder.
     *
     * @return string  The owner of this folder.
     */
    public function get_owner()
    {
        // return cached value
        if (isset($this->owner))
            return $this->owner;

        $info = $this->get_folder_info();
        $rcmail = rcube::get_instance();

        switch ($info['namespace']) {
        case 'personal':
            $this->owner = $rcmail->get_user_name();
            break;

        case 'shared':
            $this->owner = 'anonymous';
            break;

        default:
            $owner = '';
            list($prefix, $user) = explode($this->imap->get_hierarchy_delimiter(), $info['name']);
            if (strpos($user, '@') === false) {
                $domain = strstr($rcmail->get_user_name(), '@');
                if (!empty($domain))
                    $user .= $domain;
            }
            $this->owner = $user;
            break;
        }

        return $this->owner;
    }


    /**
     * Getter for the name of the namespace to which the IMAP folder belongs
     *
     * @return string Name of the namespace (personal, other, shared)
     */
    public function get_namespace()
    {
        return $this->imap->folder_namespace($this->name);
    }


    /**
     * Get IMAP ACL information for this folder
     *
     * @return string  Permissions as string
     */
    public function get_myrights()
    {
        $rights = $this->info['rights'];

        if (!is_array($rights))
            $rights = $this->imap->my_rights($this->name);

        return join('', (array)$rights);
    }


    /**
     * Compose a unique resource URI for this IMAP folder
     */
    public function get_resource_uri()
    {
        if (!empty($this->resource_uri))
            return $this->resource_uri;

        // strip namespace prefix from folder name
        $ns = $this->get_namespace();
        $nsdata = $this->imap->get_namespace($ns);
        if (is_array($nsdata[0]) && strlen($nsdata[0][0]) && strpos($this->name, $nsdata[0][0]) === 0) {
            $subpath = substr($this->name, strlen($nsdata[0][0]));
            if ($ns == 'other') {
                list($user, $suffix) = explode($nsdata[0][1], $subpath);
                $subpath = $suffix;
            }
        }
        else {
            $subpath = $this->name;
        }

        // compose fully qualified ressource uri for this instance
        $this->resource_uri = 'imap://' . urlencode($this->get_owner()) . '@' . $this->imap->options['host'] . '/' . $subpath;
        return $this->resource_uri;
    }

    /**
     * Check subscription status of this folder
     *
     * @param string Subscription type (kolab_storage::SERVERSIDE_SUBSCRIPTION or kolab_storage::CLIENTSIDE_SUBSCRIPTION)
     * @return boolean True if subscribed, false if not
     */
    public function is_subscribed($type = 0)
    {
        static $subscribed;  // local cache

        if ($type == kolab_storage::SERVERSIDE_SUBSCRIPTION) {
            if (!$subscribed)
                $subscribed = $this->imap->list_folders_subscribed();

            return in_array($this->name, $subscribed);
        }
        else if (kolab_storage::CLIENTSIDE_SUBSCRIPTION) {
            // TODO: implement this
            return true;
        }

        return false;
    }

    /**
     * Change subscription status of this folder
     *
     * @param boolean The desired subscription status: true = subscribed, false = not subscribed
     * @param string  Subscription type (kolab_storage::SERVERSIDE_SUBSCRIPTION or kolab_storage::CLIENTSIDE_SUBSCRIPTION)
     * @return True on success, false on error
     */
    public function subscribe($subscribed, $type = 0)
    {
        if ($type == kolab_storage::SERVERSIDE_SUBSCRIPTION) {
            return $subscribed ? $this->imap->subscribe($this->name) : $this->imap->unsubscribe($this->name);
        }
        else {
          // TODO: implement this
        }

        return false;
    }


    /**
     * Get number of objects stored in this folder
     *
     * @param mixed  Pseudo-SQL query as list of filter parameter triplets
     *    or string with object type (e.g. contact, event, todo, journal, note, configuration)
     * @return integer The number of objects of the given type
     * @see self::select()
     */
    public function count($type_or_query = null)
    {
        if (!$type_or_query)
            $query = array(array('type','=',$this->type));
        else if (is_string($type_or_query))
            $query = array(array('type','=',$type_or_query));
        else
            $query = $this->_prepare_query((array)$type_or_query);

        // synchronize cache first
        $this->cache->synchronize();

        return $this->cache->count($query);
    }


    /**
     * List all Kolab objects of the given type
     *
     * @param string  $type Object type (e.g. contact, event, todo, journal, note, configuration)
     * @return array  List of Kolab data objects (each represented as hash array)
     */
    public function get_objects($type = null)
    {
        if (!$type) $type = $this->type;

        // synchronize caches
        $this->cache->synchronize();

        // fetch objects from cache
        return $this->cache->select(array(array('type','=',$type)));
    }


    /**
     * Select *some* Kolab objects matching the given query
     *
     * @param array Pseudo-SQL query as list of filter parameter triplets
     *   triplet: array('<colname>', '<comparator>', '<value>')
     * @return array List of Kolab data objects (each represented as hash array)
     */
    public function select($query = array())
    {
        // check query argument
        if (empty($query))
            return $this->get_objects();

        // synchronize caches
        $this->cache->synchronize();

        // fetch objects from cache
        return $this->cache->select($this->_prepare_query($query));
    }


    /**
     * Getter for object UIDs only
     *
     * @param array Pseudo-SQL query as list of filter parameter triplets
     * @return array List of Kolab object UIDs
     */
    public function get_uids($query = array())
    {
        // synchronize caches
        $this->cache->synchronize();

        // fetch UIDs from cache
        return $this->cache->select($this->_prepare_query($query), true);
    }


    /**
     * Helper method to sanitize query arguments
     */
    private function _prepare_query($query)
    {
        $type = null;
        foreach ($query as $i => $param) {
            if ($param[0] == 'type') {
                $type = $param[2];
            }
            else if (($param[0] == 'dtstart' || $param[0] == 'dtend' || $param[0] == 'changed')) {
                if (is_object($param[2]) && is_a($param[2], 'DateTime'))
                    $param[2] = $param[2]->format('U');
                if (is_numeric($param[2]))
                    $query[$i][2] = date('Y-m-d H:i:s', $param[2]);
            }
        }

        // add type selector if not in $query
        if (!$type)
            $query[] = array('type','=',$this->type);

        return $query;
    }


    /**
     * Getter for a single Kolab object, identified by its UID
     *
     * @param string Object UID
     * @return array The Kolab object represented as hash array
     */
    public function get_object($uid)
    {
        // synchronize caches
        $this->cache->synchronize();

        $msguid = $this->cache->uid2msguid($uid);
        if ($msguid && ($object = $this->cache->get($msguid)))
            return $object;

        return false;
    }


    /**
     * Fetch a Kolab object attachment which is stored in a separate part
     * of the mail MIME message that represents the Kolab record.
     *
     * @param string  Object's UID
     * @param string  The attachment's mime number
     * @param string  IMAP folder where message is stored;
     *                If set, that also implies that the given UID is an IMAP UID
     * @return mixed  The attachment content as binary string
     */
    public function get_attachment($uid, $part, $mailbox = null)
    {
        if ($msguid = ($mailbox ? $uid : $this->cache->uid2msguid($uid))) {
            $this->imap->set_folder($mailbox ? $mailbox : $this->name);
            return $this->imap->get_message_part($msguid, $part);
        }

        return null;
    }


    /**
     * Fetch the mime message from the storage server and extract
     * the Kolab groupware object from it
     *
     * @param string The IMAP message UID to fetch
     * @param string The object type expected (use wildcard '*' to accept all types)
     * @param string The folder name where the message is stored
     * @return mixed Hash array representing the Kolab object, a kolab_format instance or false if not found
     */
    public function read_object($msguid, $type = null, $folder = null)
    {
        if (!$type) $type = $this->type;
        if (!$folder) $folder = $this->name;

        $this->imap->set_folder($folder);

        $headers = $this->imap->get_message_headers($msguid);

        // Message doesn't exist?
        if (empty($headers)) {
            return false;
        }

        $object_type = kolab_format::mime2object_type($headers->others['x-kolab-type']);
        $content_type  = kolab_format::KTYPE_PREFIX . $object_type;

        // check object type header and abort on mismatch
        if ($type != '*' && $object_type != $type)
            return false;

        $message = new rcube_message($msguid);
        $attachments = array();

        // get XML part
        foreach ((array)$message->attachments as $part) {
            if (!$xml && ($part->mimetype == $content_type || preg_match('!application/([a-z]+\+)?xml!', $part->mimetype))) {
                $xml = $part->body ? $part->body : $message->get_part_content($part->mime_id);
            }
            else if ($part->filename || $part->content_id) {
                $key = $part->content_id ? trim($part->content_id, '<>') : $part->filename;
                $attachments[$key] = array(
                    'id' => $part->mime_id,
                    'name' => $part->filename,
                    'mimetype' => $part->mimetype,
                    'size' => $part->size,
                );
            }
        }

        if (!$xml) {
            rcube::raise_error(array(
                'code' => 600,
                'type' => 'php',
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => "Could not find Kolab data part in message $msguid ($this->name).",
            ), true);
            return false;
        }

        $format = kolab_format::factory($object_type);

        if (is_a($format, 'PEAR_Error'))
            return false;

        // check kolab format version
        $mime_version = $headers->others['x-kolab-mime-version'];
        if (empty($mime_version)) {
            list($xmltype, $subtype) = explode('.', $object_type);
            $xmlhead = substr($xml, 0, 512);

            // detect old Kolab 2.0 format
            if (strpos($xmlhead, '<' . $xmltype) !== false && strpos($xmlhead, 'xmlns=') === false)
                $mime_version = 2.0;
            else
                $mime_version = 3.0; // assume 3.0
        }

        if ($mime_version <= 2.0) {
            // read Kolab 2.0 format
            $handler = class_exists('Horde_Kolab_Format') ? Horde_Kolab_Format::factory('XML', $xmltype, array('subtype' => $subtype)) : null;
            if (!is_object($handler) || is_a($handler, 'PEAR_Error')) {
                return false;
            }

            // XML-to-array
            $object = $handler->load($xml);
            $format->fromkolab2($object);
        }
        else {
            // load Kolab 3 format using libkolabxml
            $format->load($xml);
        }

        if ($format->is_valid()) {
            $object = $format->to_array();
            $object['_type'] = $object_type;
            $object['_msguid'] = $msguid;
            $object['_mailbox'] = $this->name;
            $object['_attachments'] = array_merge((array)$object['_attachments'], $attachments);
            $object['_formatobj'] = $format;

            return $object;
        }
        else {
            // try to extract object UID from XML block
            if (preg_match('!<uid>(.+)</uid>!Uims', $xml, $m))
                $msgadd = " UID = " . trim(strip_tags($m[1]));

            rcube::raise_error(array(
                'code' => 600,
                'type' => 'php',
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => "Could not parse Kolab object data in message $msguid ($this->name)." . $msgadd,
            ), true);
        }

        return false;
    }


    /**
     * Save an object in this folder.
     *
     * @param array  $object    The array that holds the data of the object.
     * @param string $type      The type of the kolab object.
     * @param string $uid       The UID of the old object if it existed before
     * @return boolean          True on success, false on error
     */
    public function save(&$object, $type = null, $uid = null)
    {
        if (!$type)
            $type = $this->type;

        // copy attachments from old message
        if (!empty($object['_msguid']) && ($old = $this->cache->get($object['_msguid'], $type, $object['_mailbox']))) {
            foreach ((array)$old['_attachments'] as $key => $att) {
                if (!isset($object['_attachments'][$key])) {
                    $object['_attachments'][$key] = $old['_attachments'][$key];
                }
                // unset deleted attachment entries
                if ($object['_attachments'][$key] == false) {
                    unset($object['_attachments'][$key]);
                }
                // load photo.attachment from old Kolab2 format to be directly embedded in xcard block
                else if ($key == 'photo.attachment' && !isset($object['photo']) && !$object['_attachments'][$key]['content'] && $att['id']) {
                    $object['photo'] = $this->get_attachment($object['_msguid'], $att['id'], $object['_mailbox']);
                    unset($object['_attachments'][$key]);
                }
            }
        }

        // generate unique keys (used as content-id) for attachments
        if (is_array($object['_attachments'])) {
            $numatt = count($object['_attachments']);
            foreach ($object['_attachments'] as $key => $attachment) {
                if (is_numeric($key) && $key < $numatt) {
                    // derrive content-id from attachment file name
                    $ext = preg_match('/(\.[a-z0-9]{1,6})$/i', $attachment['name'], $m) ? $m[1] : null;
                    $basename = preg_replace('/[^a-z0-9_.-]/i', '', basename($attachment['name'], $ext));  // to 7bit ascii
                    if (!$basename) $basename = 'noname';
                    $cid = $basename . '.' . microtime(true) . $ext;

                    $object['_attachments'][$cid] = $attachment;
                    unset($object['_attachments'][$key]);
                }
            }
        }

        if ($raw_msg = $this->build_message($object, $type)) {
            $result = $this->imap->save_message($this->name, $raw_msg, '', false);

            // delete old message
            if ($result && !empty($object['_msguid']) && !empty($object['_mailbox'])) {
                $this->imap->delete_message($object['_msguid'], $object['_mailbox']);
                $this->cache->set($object['_msguid'], false, $object['_mailbox']);
            }
            else if ($result && $uid && ($msguid = $this->cache->uid2msguid($uid))) {
                $this->imap->delete_message($msguid, $this->name);
                $this->cache->set($object['_msguid'], false);
            }

            // update cache with new UID
            if ($result) {
                $object['_msguid'] = $result;
                $this->cache->insert($result, $object);
            }
        }
        
        return $result;
    }


    /**
     * Delete the specified object from this folder.
     *
     * @param  mixed   $object  The Kolab object to delete or object UID
     * @param  boolean $expunge Should the folder be expunged?
     *
     * @return boolean True if successful, false on error
     */
    public function delete($object, $expunge = true)
    {
        $msguid = is_array($object) ? $object['_msguid'] : $this->cache->uid2msguid($object);
        $success = false;

        if ($msguid && $expunge) {
            $success = $this->imap->delete_message($msguid, $this->name);
        }
        else if ($msguid) {
            $success = $this->imap->set_flag($msguid, 'DELETED', $this->name);
        }

        if ($success) {
            $this->cache->set($msguid, false);
        }

        return $success;
    }


    /**
     *
     */
    public function delete_all()
    {
        $this->cache->purge();
        return $this->imap->clear_folder($this->name);
    }


    /**
     * Restore a previously deleted object
     *
     * @param string Object UID
     * @return mixed Message UID on success, false on error
     */
    public function undelete($uid)
    {
        if ($msguid = $this->cache->uid2msguid($uid, true)) {
            if ($this->imap->set_flag($msguid, 'UNDELETED', $this->name)) {
                return $msguid;
            }
        }

        return false;
    }


    /**
     * Move a Kolab object message to another IMAP folder
     *
     * @param string Object UID
     * @param string IMAP folder to move object to
     * @return boolean True on success, false on failure
     */
    public function move($uid, $target_folder)
    {
        if ($msguid = $this->cache->uid2msguid($uid)) {
            if ($success = $this->imap->move_message($msguid, $target_folder, $this->name)) {
                $this->cache->move($msguid, $uid, $target_folder);
                return true;
            }
            else {
                rcube::raise_error(array(
                    'code' => 600, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Failed to move message $msguid to $target_folder: " . $this->imap->get_error_str(),
                ), true);
            }
        }

        return false;
    }


    /**
     * Creates source of the configuration object message
     */
    private function build_message(&$object, $type)
    {
        // load old object to preserve data we don't understand/process
        if (is_object($object['_formatobj']))
            $format = $object['_formatobj'];
        else if ($object['_msguid'] && ($old = $this->cache->get($object['_msguid'], $type, $object['_mailbox'])))
            $format = $old['_formatobj'];

        // create new kolab_format instance
        if (!$format)
            $format = kolab_format::factory($type);

        if (PEAR::isError($format))
            return false;

        $format->set($object);
        $xml = $format->write();
        $object['uid'] = $format->uid;  // read UID from format
        $object['_formatobj'] = $format;

        if (!$format->is_valid() || empty($object['uid'])) {
            return false;
        }

        $mime = new Mail_mime("\r\n");
        $rcmail = rcube::get_instance();
        $headers = array();
        $part_id = 1;

        if ($ident = $rcmail->user->get_identity()) {
            $headers['From'] = $ident['email'];
            $headers['To'] = $ident['email'];
        }
        $headers['Date'] = date('r');
        $headers['X-Kolab-Type'] = kolab_format::KTYPE_PREFIX . $type;
        $headers['X-Kolab-Mime-Version'] = kolab_format::VERSION;
        $headers['Subject'] = $object['uid'];
//        $headers['Message-ID'] = $rcmail->gen_message_id();
        $headers['User-Agent'] = $rcmail->config->get('useragent');

        $mime->headers($headers);
        $mime->setTXTBody('This is a Kolab Groupware object. '
            . 'To view this object you will need an email client that understands the Kolab Groupware format. '
            . "For a list of such email clients please visit http://www.kolab.org/\n\n");

        $mime->addAttachment($xml,  // file
            $format->CTYPE,         // content-type
            'kolab.xml',            // filename
            false,                  // is_file
            '8bit',                 // encoding
            'attachment',           // disposition
            RCMAIL_CHARSET          // charset
        );
        $part_id++;

        // save object attachments as separate parts
        // TODO: optimize memory consumption by using tempfiles for transfer
        foreach ((array)$object['_attachments'] as $key => $att) {
            if (empty($att['content']) && !empty($att['id'])) {
                $msguid = !empty($object['_msguid']) ? $object['_msguid'] : $object['uid'];
                $att['content'] = $this->get_attachment($msguid, $att['id'], $object['_mailbox']);
            }

            $headers = array('Content-ID' => Mail_mimePart::encodeHeader('Content-ID', '<' . $key . '>', RCMAIL_CHARSET, 'quoted-printable'));
            $name = !empty($att['name']) ? $att['name'] : $key;

            if (!empty($att['content'])) {
                $mime->addAttachment($att['content'], $att['mimetype'], $name, false, 'base64', 'attachment', '', '', '', null, null, '', RCMAIL_CHARSET, $headers);
                $part_id++;
            }
            else if (!empty($att['path'])) {
                $mime->addAttachment($att['path'], $att['mimetype'], $name, true, 'base64', 'attachment', '', '', '', null, null, '', RCMAIL_CHARSET, $headers);
                $part_id++;
            }

            $object['_attachments'][$key]['id'] = $part_id;
        }

        return $mime->getMessage();
    }


    /**
     * Triggers any required updates after changes within the
     * folder. This is currently only required for handling free/busy
     * information with Kolab.
     *
     * @return boolean|PEAR_Error True if successfull.
     */
    public function trigger()
    {
        $owner = $this->get_owner();
        $result = false;

        switch($this->type) {
        case 'event':
            if ($this->get_namespace() == 'personal') {
                $result = $this->trigger_url(
                    sprintf('%s/trigger/%s/%s.pfb', kolab_storage::get_freebusy_server(), $owner, $this->imap->mod_folder($this->name)),
                    $this->imap->options['user'],
                    $this->imap->options['password']
                );
            }
            break;

        default:
            return true;
        }

        if ($result && is_object($result) && is_a($result, 'PEAR_Error')) {
            return PEAR::raiseError(sprintf("Failed triggering folder %s. Error was: %s",
                                            $this->name, $result->getMessage()));
        }

        return $result;
    }

    /**
     * Triggers a URL.
     *
     * @param string $url          The URL to be triggered.
     * @param string $auth_user    Username to authenticate with
     * @param string $auth_passwd  Password for basic auth
     * @return boolean|PEAR_Error  True if successfull.
     */
    private function trigger_url($url, $auth_user = null, $auth_passwd = null)
    {
        require_once('HTTP/Request2.php');

        try {
            $rcmail = rcube::get_instance();
            $request = new HTTP_Request2($url);
            $request->setConfig(array('ssl_verify_peer' => $rcmail->config->get('kolab_ssl_verify_peer', true)));

            // set authentication credentials
            if ($auth_user && $auth_passwd)
                $request->setAuth($auth_user, $auth_passwd);

            $result = $request->send();
            // rcube::write_log('trigger', $result->getBody());
        }
        catch (Exception $e) {
            return PEAR::raiseError($e->getMessage());
        }

        return true;
    }

}

