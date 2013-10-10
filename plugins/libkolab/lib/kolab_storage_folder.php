<?php

/**
 * The kolab_storage_folder class represents an IMAP folder on the Kolab server.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
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
    private $namespace;
    private $imap;
    private $info;
    private $idata;
    private $owner;
    private $resource_uri;


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
    public function get_folder_info()
    {
        if (!isset($this->info))
            $this->info = $this->imap->folder_info($this->name);

        return $this->info;
    }

    /**
     * Make IMAP folder data available for this folder
     */
    public function get_imap_data()
    {
        if (!isset($this->idata))
            $this->idata = $this->imap->folder_data($this->name);

        return $this->idata;
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
        if (!isset($this->namespace))
            $this->namespace = $this->imap->folder_namespace($this->name);
        return $this->namespace;
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
     * Get the display name value of this folder
     *
     * @return string Folder name
     */
    public function get_name()
    {
        return kolab_storage::object_name($this->name, $this->namespace);
    }


    /**
     * Get the color value stored in metadata
     *
     * @param string Default color value to return if not set
     * @return mixed Color value from IMAP metadata or $default is not set
     */
    public function get_color($default = null)
    {
        // color is defined in folder METADATA
        $metadata = $this->get_metadata(array(kolab_storage::COLOR_KEY_PRIVATE, kolab_storage::COLOR_KEY_SHARED));
        if (($color = $metadata[kolab_storage::COLOR_KEY_PRIVATE]) || ($color = $metadata[kolab_storage::COLOR_KEY_SHARED])) {
            return $color;
        }

        return $default;
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
                list($user, $suffix) = explode($nsdata[0][1], $subpath, 2);
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
     * Check activation status of this folder
     *
     * @return boolean True if enabled, false if not
     */
    public function is_active()
    {
        return kolab_storage::folder_is_active($this->name);
    }

    /**
     * Change activation status of this folder
     *
     * @param boolean The desired subscription status: true = active, false = not active
     *
     * @return True on success, false on error
     */
    public function activate($active)
    {
        return $active ? kolab_storage::folder_activate($this->name) : kolab_storage::folder_deactivate($this->name);
    }

    /**
     * Check subscription status of this folder
     *
     * @return boolean True if subscribed, false if not
     */
    public function is_subscribed()
    {
        return kolab_storage::folder_is_subscribed($this->name);
    }

    /**
     * Change subscription status of this folder
     *
     * @param boolean The desired subscription status: true = subscribed, false = not subscribed
     *
     * @return True on success, false on error
     */
    public function subscribe($subscribed)
    {
        return $subscribed ? kolab_storage::folder_subscribe($this->name) : kolab_storage::folder_unsubscribe($this->name);
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
     * @param string $uid  Object UID
     * @param string $type Object type (e.g. contact, event, todo, journal, note, configuration)
     *                     Defaults to folder type
     *
     * @return array The Kolab object represented as hash array
     */
    public function get_object($uid, $type = null)
    {
        // synchronize caches
        $this->cache->synchronize();

        $msguid = $this->cache->uid2msguid($uid);

        if ($msguid && ($object = $this->cache->get($msguid, $type))) {
            return $object;
        }

        return false;
    }


    /**
     * Fetch a Kolab object attachment which is stored in a separate part
     * of the mail MIME message that represents the Kolab record.
     *
     * @param string   Object's UID
     * @param string   The attachment's mime number
     * @param string   IMAP folder where message is stored;
     *                 If set, that also implies that the given UID is an IMAP UID
     * @param bool     True to print the part content
     * @param resource File pointer to save the message part
     * @param boolean  Disables charset conversion
     *
     * @return mixed  The attachment content as binary string
     */
    public function get_attachment($uid, $part, $mailbox = null, $print = false, $fp = null, $skip_charset_conv = false)
    {
        if ($msguid = ($mailbox ? $uid : $this->cache->uid2msguid($uid))) {
            $this->imap->set_folder($mailbox ? $mailbox : $this->name);
            return $this->imap->get_message_part($msguid, $part, null, $print, $fp, $skip_charset_conv);
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
        $message = null;

        // Message doesn't exist?
        if (empty($headers)) {
            return false;
        }

        // extract the X-Kolab-Type header from the XML attachment part if missing
        if (empty($headers->others['x-kolab-type'])) {
            $message = new rcube_message($msguid);
            foreach ((array)$message->attachments as $part) {
                if (strpos($part->mimetype, kolab_format::KTYPE_PREFIX) === 0) {
                    $headers->others['x-kolab-type'] = $part->mimetype;
                    break;
                }
            }
        }
        // fix buggy messages stating the X-Kolab-Type header twice
        else if (is_array($headers->others['x-kolab-type'])) {
            $headers->others['x-kolab-type'] = reset($headers->others['x-kolab-type']);
        }

        // no object type header found: abort
        if (empty($headers->others['x-kolab-type'])) {
            rcube::raise_error(array(
                'code' => 600,
                'type' => 'php',
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => "No X-Kolab-Type information found in message $msguid ($this->name).",
            ), true);
            return false;
        }

        $object_type = kolab_format::mime2object_type($headers->others['x-kolab-type']);
        $content_type  = kolab_format::KTYPE_PREFIX . $object_type;

        // check object type header and abort on mismatch
        if ($type != '*' && $object_type != $type)
            return false;

        if (!$message) $message = new rcube_message($msguid);
        $attachments = array();

        // get XML part
        foreach ((array)$message->attachments as $part) {
            if (!$xml && ($part->mimetype == $content_type || preg_match('!application/([a-z]+\+)?xml!', $part->mimetype))) {
                $xml = $part->body ? $part->body : $message->get_part_content($part->mime_id);
            }
            else if ($part->filename || $part->content_id) {
                $key  = $part->content_id ? trim($part->content_id, '<>') : $part->filename;
                $size = null;

                // Use Content-Disposition 'size' as for the Kolab Format spec.
                if (isset($part->d_parameters['size'])) {
                    $size = $part->d_parameters['size'];
                }
                // we can trust part size only if it's not encoded
                else if ($part->encoding == 'binary' || $part->encoding == '7bit' || $part->encoding == '8bit') {
                    $size = $part->size;
                }

                $attachments[$key] = array(
                    'id'       => $part->mime_id,
                    'name'     => $part->filename,
                    'mimetype' => $part->mimetype,
                    'size'     => $size,
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

        // check kolab format version
        $format_version = $headers->others['x-kolab-mime-version'];
        if (empty($format_version)) {
            list($xmltype, $subtype) = explode('.', $object_type);
            $xmlhead = substr($xml, 0, 512);

            // detect old Kolab 2.0 format
            if (strpos($xmlhead, '<' . $xmltype) !== false && strpos($xmlhead, 'xmlns=') === false)
                $format_version = '2.0';
            else
                $format_version = '3.0'; // assume 3.0
        }

        // get Kolab format handler for the given type
        $format = kolab_format::factory($object_type, $format_version);

        if (is_a($format, 'PEAR_Error'))
            return false;

        // load Kolab object from XML part
        $format->load($xml);

        if ($format->is_valid()) {
            $object = $format->to_array(array('_attachments' => $attachments));
            $object['_type']      = $object_type;
            $object['_msguid']    = $msguid;
            $object['_mailbox']   = $this->name;
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
                else if ($type == 'contact' && ($key == 'photo.attachment' || $key == 'kolab-picture.png') && $att['id']) {
                    if (!isset($object['photo']))
                        $object['photo'] = $this->get_attachment($object['_msguid'], $att['id'], $object['_mailbox']);
                    unset($object['_attachments'][$key]);
                }
            }
        }

        // save contact photo to attachment for Kolab2 format
        if (kolab_storage::$version == '2.0' && $object['photo']) {
            $attkey = 'kolab-picture.png';  // this file name is hard-coded in libkolab/kolabformatV2/contact.cpp
            $object['_attachments'][$attkey] = array(
                'mimetype'=> rcube_mime::image_content_type($object['photo']),
                'content' => preg_match('![^a-z0-9/=+-]!i', $object['photo']) ? $object['photo'] : base64_decode($object['photo']),
            );
        }

        // process attachments
        if (is_array($object['_attachments'])) {
            $numatt = count($object['_attachments']);
            foreach ($object['_attachments'] as $key => $attachment) {
                // FIXME: kolab_storage and Roundcube attachment hooks use different fields!
                if (empty($attachment['content']) && !empty($attachment['data'])) {
                    $attachment['content'] = $attachment['data'];
                    unset($attachment['data'], $object['_attachments'][$key]['data']);
                }

                // make sure size is set, so object saved in cache contains this info
                if (!isset($attachment['size'])) {
                    if (!empty($attachment['content'])) {
                        if (is_resource($attachment['content'])) {
                            // this need to be a seekable resource, otherwise
                            // fstat() failes and we're unable to determine size
                            // here nor in rcube_imap_generic before IMAP APPEND
                            $stat = fstat($attachment['content']);
                            $attachment['size'] = $stat ? $stat['size'] : 0;
                        }
                        else {
                            $attachment['size'] = strlen($attachment['content']);
                        }
                    }
                    else if (!empty($attachment['path'])) {
                        $attachment['size'] = filesize($attachment['path']);
                    }
                    $object['_attachments'][$key] = $attachment;
                }

                // generate unique keys (used as content-id) for attachments
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

        // save recurrence exceptions as individual objects due to lack of support in Kolab v2 format
        if (kolab_storage::$version == '2.0' && $object['recurrence']['EXCEPTIONS']) {
            $this->save_recurrence_exceptions($object, $type);
        }

        // check IMAP BINARY extension support for 'file' objects
        // allow configuration to workaround bug in Cyrus < 2.4.17
        $rcmail = rcube::get_instance();
        $binary = $type == 'file' && !$rcmail->config->get('kolab_binary_disable') && $this->imap->get_capability('BINARY');

        // generate and save object message
        if ($raw_msg = $this->build_message($object, $type, $binary, $body_file)) {
            // resolve old msguid before saving
            if ($uid && empty($object['_msguid']) && ($msguid = $this->cache->uid2msguid($uid))) {
                $object['_msguid'] = $msguid;
                $object['_mailbox'] = $this->name;
            }

            $result = $this->imap->save_message($this->name, $raw_msg, null, false, null, null, $binary);

            // delete old message
            if ($result && !empty($object['_msguid']) && !empty($object['_mailbox'])) {
                $this->imap->delete_message($object['_msguid'], $object['_mailbox']);
                $this->cache->set($object['_msguid'], false, $object['_mailbox']);
            }

            // update cache with new UID
            if ($result) {
                $object['_msguid'] = $result;
                $this->cache->insert($result, $object);

                // remove temp file
                if ($body_file) {
                    @unlink($body_file);
                }
            }
        }

        return $result;
    }

    /**
     * Save recurrence exceptions as individual objects.
     * The Kolab v2 format doesn't allow us to save fully embedded exception objects.
     *
     * @param array Hash array with event properties
     * @param string Object type
     */
    private function save_recurrence_exceptions(&$object, $type = null)
    {
        if ($object['recurrence']['EXCEPTIONS']) {
            $exdates = array();
            foreach ((array)$object['recurrence']['EXDATE'] as $exdate) {
                $key = is_a($exdate, 'DateTime') ? $exdate->format('Y-m-d') : strval($exdate);
                $exdates[$key] = 1;
            }

            // save every exception as individual object
            foreach((array)$object['recurrence']['EXCEPTIONS'] as $exception) {
                $exception['uid'] = self::recurrence_exception_uid($object['uid'], $exception['start']->format('Ymd'));
                $exception['sequence'] = $object['sequence'] + 1;

                if ($exception['thisandfuture']) {
                    $exception['recurrence'] = $object['recurrence'];

                    // adjust the recurrence duration of the exception
                    if ($object['recurrence']['COUNT']) {
                        $recurrence = new kolab_date_recurrence($object['_formatobj']);
                        if ($end = $recurrence->end()) {
                            unset($exception['recurrence']['COUNT']);
                            $exception['recurrence']['UNTIL'] = new DateTime('@'.$end);
                        }
                    }

                    // set UNTIL date if we have a thisandfuture exception
                    $untildate = clone $exception['start'];
                    $untildate->sub(new DateInterval('P1D'));
                    $object['recurrence']['UNTIL'] = $untildate;
                    unset($object['recurrence']['COUNT']);
                }
                else {
                    if (!$exdates[$exception['start']->format('Y-m-d')])
                        $object['recurrence']['EXDATE'][] = clone $exception['start'];
                    unset($exception['recurrence']);
                }

                unset($exception['recurrence']['EXCEPTIONS'], $exception['_formatobj'], $exception['_msguid']);
                $this->save($exception, $type, $exception['uid']);
            }

            unset($object['recurrence']['EXCEPTIONS']);
        }
    }

    /**
     * Generate an object UID with the given recurrence-ID in a way that it is
     * unique (the original UID is not a substring) but still recoverable.
     */
    private static function recurrence_exception_uid($uid, $recurrence_id)
    {
        $offset = -2;
        return substr($uid, 0, $offset) . '-' . $recurrence_id . '-' . substr($uid, $offset);
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
            if ($this->imap->move_message($msguid, $target_folder, $this->name)) {
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
     *
     * @param array  $object    The array that holds the data of the object.
     * @param string $type      The type of the kolab object.
     * @param bool   $binary    Enables use of binary encoding of attachment(s)
     * @param string $body_file Reference to filename of message body
     *
     * @return mixed Message as string or array with two elements
     *               (one for message file path, second for message headers)
     */
    private function build_message(&$object, $type, $binary, &$body_file)
    {
        // load old object to preserve data we don't understand/process
        if (is_object($object['_formatobj']))
            $format = $object['_formatobj'];
        else if ($object['_msguid'] && ($old = $this->cache->get($object['_msguid'], $type, $object['_mailbox'])))
            $format = $old['_formatobj'];

        // create new kolab_format instance
        if (!$format)
            $format = kolab_format::factory($type, kolab_storage::$version);

        if (PEAR::isError($format))
            return false;

        $format->set($object);
        $xml = $format->write(kolab_storage::$version);
        $object['uid'] = $format->uid;  // read UID from format
        $object['_formatobj'] = $format;

        if (empty($xml) || !$format->is_valid() || empty($object['uid'])) {
            return false;
        }

        $mime     = new Mail_mime("\r\n");
        $rcmail   = rcube::get_instance();
        $headers  = array();
        $files    = array();
        $part_id  = 1;
        $encoding = $binary ? 'binary' : 'base64';

        if ($user_email = $rcmail->get_user_email()) {
            $headers['From'] = $user_email;
            $headers['To'] = $user_email;
        }
        $headers['Date'] = date('r');
        $headers['X-Kolab-Type'] = kolab_format::KTYPE_PREFIX . $type;
        $headers['X-Kolab-Mime-Version'] = kolab_storage::$version;
        $headers['Subject'] = $object['uid'];
//        $headers['Message-ID'] = $rcmail->gen_message_id();
        $headers['User-Agent'] = $rcmail->config->get('useragent');

        // Check if we have enough memory to handle the message in it
        // It's faster than using files, so we'll do this if we only can
        if (!empty($object['_attachments']) && ($mem_limit = parse_bytes(ini_get('memory_limit'))) > 0) {
            $memory = function_exists('memory_get_usage') ? memory_get_usage() : 16*1024*1024; // safe value: 16MB

            foreach ($object['_attachments'] as $id => $attachment) {
                $memory += $attachment['size'];
            }

            // 1.33 is for base64, we need at least 4x more memory than the message size
            if ($memory * ($binary ? 1 : 1.33) * 4 > $mem_limit) {
                $marker   = '%%%~~~' . md5(microtime(true) . $memory) . '~~~%%%';
                $is_file  = true;
                $temp_dir = unslashify($rcmail->config->get('temp_dir'));
                $mime->setParam('delay_file_io', true);
            }
        }

        $mime->headers($headers);
        $mime->setTXTBody("This is a Kolab Groupware object. "
            . "To view this object you will need an email client that understands the Kolab Groupware format. "
            . "For a list of such email clients please visit http://www.kolab.org/\n\n");

        $ctype = kolab_storage::$version == '2.0' ? $format->CTYPEv2 : $format->CTYPE;
        // Convert new lines to \r\n, to wrokaround "NO Message contains bare newlines"
        // when APPENDing from temp file
        $xml = preg_replace('/\r?\n/', "\r\n", $xml);

        $mime->addAttachment($xml,  // file
            $ctype,                 // content-type
            'kolab.xml',            // filename
            false,                  // is_file
            '8bit',                 // encoding
            'attachment',           // disposition
            RCUBE_CHARSET           // charset
        );
        $part_id++;

        // save object attachments as separate parts
        foreach ((array)$object['_attachments'] as $key => $att) {
            if (empty($att['content']) && !empty($att['id'])) {
                // @TODO: use IMAP CATENATE to skip attachment fetch+push operation
                $msguid = !empty($object['_msguid']) ? $object['_msguid'] : $object['uid'];
                if ($is_file) {
                    $att['path'] = tempnam($temp_dir, 'rcmAttmnt');
                    if (($fp = fopen($att['path'], 'w')) && $this->get_attachment($msguid, $att['id'], $object['_mailbox'], false, $fp, true)) {
                        fclose($fp);
                    }
                    else {
                        return false;
                    }
                }
                else {
                    $att['content'] = $this->get_attachment($msguid, $att['id'], $object['_mailbox'], false, null, true);
                }
            }

            $headers = array('Content-ID' => Mail_mimePart::encodeHeader('Content-ID', '<' . $key . '>', RCUBE_CHARSET, 'quoted-printable'));
            $name = !empty($att['name']) ? $att['name'] : $key;

            // To store binary files we can use faster method
            // without writting full message content to a temporary file but
            // directly to IMAP, see rcube_imap_generic::append().
            // I.e. use file handles where possible
            if (!empty($att['path'])) {
                if ($is_file && $binary) {
                    $files[] = fopen($att['path'], 'r');
                    $mime->addAttachment($marker, $att['mimetype'], $name, false, $encoding, 'attachment', '', '', '', null, null, '', RCUBE_CHARSET, $headers);
                }
                else {
                    $mime->addAttachment($att['path'], $att['mimetype'], $name, true, $encoding, 'attachment', '', '', '', null, null, '', RCUBE_CHARSET, $headers);
                }
            }
            else {
                if (is_resource($att['content']) && $is_file && $binary) {
                    $files[] = $att['content'];
                    $mime->addAttachment($marker, $att['mimetype'], $name, false, $encoding, 'attachment', '', '', '', null, null, '', RCUBE_CHARSET, $headers);
                }
                else {
                    if (is_resource($att['content'])) {
                        @rewind($att['content']);
                        $att['content'] = stream_get_contents($att['content']);
                    }
                    $mime->addAttachment($att['content'], $att['mimetype'], $name, false, $encoding, 'attachment', '', '', '', null, null, '', RCUBE_CHARSET, $headers);
                }
            }

            $object['_attachments'][$key]['id'] = ++$part_id;
        }

        if (!$is_file || !empty($files)) {
            $message = $mime->getMessage();
        }

        // parse message and build message array with
        // attachment file pointers in place of file markers
        if (!empty($files)) {
            $message = explode($marker, $message);
            $tmp     = array();

            foreach ($message as $msg_part) {
                $tmp[] = $msg_part;
                if ($file = array_shift($files)) {
                    $tmp[] = $file;
                }
            }
            $message = $tmp;
        }
        // write complete message body into temp file
        else if ($is_file) {
            // use common temp dir
            $body_file = tempnam($temp_dir, 'rcmMsg');

            if (PEAR::isError($mime_result = $mime->saveMessageBody($body_file))) {
                self::raise_error(array('code' => 650, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Could not create message: ".$mime_result->getMessage()),
                    true, false);
                return false;
            }

            $message = array(trim($mime->txtHeaders()) . "\r\n\r\n", fopen($body_file, 'r'));
        }

        return $message;
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
                    sprintf('%s/trigger/%s/%s.pfb',
                        kolab_storage::get_freebusy_server(),
                        urlencode($owner),
                        urlencode($this->imap->mod_folder($this->name))
                    ),
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

