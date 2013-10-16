<?php

/**
 * Kolab storage cache class providing a local caching layer for Kolab groupware objects.
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

class kolab_storage_cache_mongodb
{
    private $db;
    private $imap;
    private $folder;
    private $uid2msg;
    private $objects;
    private $index = array();
    private $resource_uri;
    private $enabled = true;
    private $synched = false;
    private $synclock = false;
    private $ready = false;
    private $max_sql_packet = 1046576;  // 1 MB - 2000 bytes
    private $binary_cols = array('photo','pgppublickey','pkcs7publickey');


    /**
     * Default constructor
     */
    public function __construct(kolab_storage_folder $storage_folder = null)
    {
        $rcmail = rcube::get_instance();
        $mongo = new Mongo();
        $this->db = $mongo->kolab_cache;
        $this->imap = $rcmail->get_storage();
        $this->enabled = $rcmail->config->get('kolab_cache', false);

        if ($this->enabled) {
            // remove sync-lock on script termination
            $rcmail->add_shutdown_function(array($this, '_sync_unlock'));
        }

        if ($storage_folder)
            $this->set_folder($storage_folder);
    }


    /**
     * Connect cache with a storage folder
     *
     * @param kolab_storage_folder The storage folder instance to connect with
     */
    public function set_folder(kolab_storage_folder $storage_folder)
    {
        $this->folder = $storage_folder;

        if (empty($this->folder->name)) {
            $this->ready = false;
            return;
        }

        // compose fully qualified ressource uri for this instance
        $this->resource_uri = $this->folder->get_resource_uri();
        $this->ready = $this->enabled;
    }


    /**
     * Synchronize local cache data with remote
     */
    public function synchronize()
    {
        // only sync once per request cycle
        if ($this->synched)
            return;

        // increase time limit
        @set_time_limit(500);

        // lock synchronization for this folder or wait if locked
        $this->_sync_lock();

        // synchronize IMAP mailbox cache
        $this->imap->folder_sync($this->folder->name);

        // compare IMAP index with object cache index
        $imap_index = $this->imap->index($this->folder->name);
        $this->index = $imap_index->get();

        // determine objects to fetch or to invalidate
        if ($this->ready) {
            // read cache index
            $old_index = array();
            $cursor = $this->db->cache->find(array('resource' => $this->resource_uri), array('msguid' => 1, 'uid' => 1));
            foreach ($cursor as $doc) {
                $old_index[] = $doc['msguid'];
                $this->uid2msg[$doc['uid']] = $doc['msguid'];
            }

            // fetch new objects from imap
            foreach (array_diff($this->index, $old_index) as $msguid) {
                if ($object = $this->folder->read_object($msguid, '*')) {
                    try {
                        $this->db->cache->insert($this->_serialize($object, $msguid));
                    }
                    catch (Exception $e) {
                        rcmail::raise_error(array(
                            'code' => 900, 'type' => 'php',
                            'message' => "Failed to write to mongodb cache: " . $e->getMessage(),
                        ), true);
                    }
                }
            }

            // delete invalid entries from local DB
            $del_index = array_diff($old_index, $this->index);
            if (!empty($del_index)) {
                $this->db->cache->remove(array('resource' => $this->resource_uri, 'msguid' => array('$in' => $del_index)));
            }
        }

        // remove lock
        $this->_sync_unlock();

        $this->synched = time();
    }


    /**
     * Read a single entry from cache or from IMAP directly
     *
     * @param string Related IMAP message UID
     * @param string Object type to read
     * @param string IMAP folder name the entry relates to
     * @param array  Hash array with object properties or null if not found
     */
    public function get($msguid, $type = null, $foldername = null)
    {
        // delegate to another cache instance
        if ($foldername && $foldername != $this->folder->name) {
            return kolab_storage::get_folder($foldername)->cache->get($msguid, $object);
        }

        // load object if not in memory
        if (!isset($this->objects[$msguid])) {
            if ($this->ready && ($doc = $this->db->cache->findOne(array('resource' => $this->resource_uri, 'msguid' => $msguid))))
                $this->objects[$msguid] = $this->_unserialize($doc);

            // fetch from IMAP if not present in cache
            if (empty($this->objects[$msguid])) {
                $result = $this->_fetch(array($msguid), $type, $foldername);
                $this->objects[$msguid] = $result[0];
            }
        }

        return $this->objects[$msguid];
    }


    /**
     * Insert/Update a cache entry
     *
     * @param string Related IMAP message UID
     * @param mixed  Hash array with object properties to save or false to delete the cache entry
     * @param string IMAP folder name the entry relates to
     */
    public function set($msguid, $object, $foldername = null)
    {
        // delegate to another cache instance
        if ($foldername && $foldername != $this->folder->name) {
            kolab_storage::get_folder($foldername)->cache->set($msguid, $object);
            return;
        }

        // write to cache
        if ($this->ready) {
            // remove old entry
            $this->db->cache->remove(array('resource' => $this->resource_uri, 'msguid' => $msguid));

            // write new object data if not false (wich means deleted)
            if ($object) {
                try {
                    $this->db->cache->insert($this->_serialize($object, $msguid));
                }
                catch (Exception $e) {
                    rcmail::raise_error(array(
                        'code' => 900, 'type' => 'php',
                        'message' => "Failed to write to mongodb cache: " . $e->getMessage(),
                    ), true);
                }
            }
        }

        // keep a copy in memory for fast access
        $this->objects[$msguid] = $object;

        if ($object)
            $this->uid2msg[$object['uid']] = $msguid;
    }

    /**
     * Move an existing cache entry to a new resource
     *
     * @param string Entry's IMAP message UID
     * @param string Entry's Object UID
     * @param string Target IMAP folder to move it to
     */
    public function move($msguid, $objuid, $target_folder)
    {
        $target = kolab_storage::get_folder($target_folder);

        // resolve new message UID in target folder
        if ($new_msguid = $target->cache->uid2msguid($objuid)) {
/*
            $this->db->query(
                "UPDATE kolab_cache SET resource=?, msguid=? ".
                "WHERE resource=? AND msguid=? AND type<>?",
                $target->get_resource_uri(),
                $new_msguid,
                $this->resource_uri,
                $msguid,
                'lock'
            );
*/
        }
        else {
            // just clear cache entry
            $this->set($msguid, false);
        }

        unset($this->uid2msg[$uid]);
    }


    /**
     * Remove all objects from local cache
     */
    public function purge($type = null)
    {
        return $this->db->cache->remove(array(), array('safe' => true));
    }


    /**
     * Select Kolab objects filtered by the given query
     *
     * @param array Pseudo-SQL query as list of filter parameter triplets
     *   triplet: array('<colname>', '<comparator>', '<value>')
     * @return array List of Kolab data objects (each represented as hash array)
     */
    public function select($query = array())
    {
        $result = array();

        // read from local cache DB (assume it to be synchronized)
        if ($this->ready) {
            $cursor = $this->db->cache->find(array('resource' => $this->resource_uri) + $this->_mongo_filter($query));
            foreach ($cursor as $doc) {
                if ($object = $this->_unserialize($doc))
                    $result[] = $object;
            }
        }
        else {
            // extract object type from query parameter
            $filter = $this->_query2assoc($query);

            // use 'list' for folder's default objects
            if ($filter['type'] == $this->type) {
                $index = $this->index;
            }
            else {  // search by object type
                $search = 'UNDELETED HEADER X-Kolab-Type ' . kolab_storage_folder::KTYPE_PREFIX . $filter['type'];
                $index = $this->imap->search_once($this->folder->name, $search)->get();
            }

            // fetch all messages in $index from IMAP
            $result = $this->_fetch($index, $filter['type']);

            // TODO: post-filter result according to query
        }

        return $result;
    }


    /**
     * Get number of objects mathing the given query
     *
     * @param array  $query Pseudo-SQL query as list of filter parameter triplets
     * @return integer The number of objects of the given type
     */
    public function count($query = array())
    {
        $count = 0;

        // cache is in sync, we can count records in local DB
        if ($this->synched) {
            $cursor = $this->db->cache->find(array('resource' => $this->resource_uri) + $this->_mongo_filter($query));
            $count = $cursor->valid() ? $cursor->count() : 0;
        }
        else {
            // search IMAP by object type
            $filter = $this->_query2assoc($query);
            $ctype  = kolab_storage_folder::KTYPE_PREFIX . $filter['type'];
            $index = $this->imap->search_once($this->folder->name, 'UNDELETED HEADER X-Kolab-Type ' . $ctype);
            $count = $index->count();
        }

        return $count;
    }

    /**
     * Helper method to convert the pseudo-SQL query into a valid mongodb filter
     */
    private function _mongo_filter($query)
    {
        $filters = array();
        foreach ($query as $param) {
            $filter = array();
            if ($param[1] == '=' && is_array($param[2])) {
                $filter[$param[0]] = array('$in' => $param[2]);
                $filters[] = $filter;
            }
            else if ($param[1] == '=') {
                $filters[] = array($param[0] => $param[2]);
            }
            else if ($param[1] == 'LIKE' || $param[1] == '~') {
                $filter[$param[0]] = array('$regex' => preg_quote($param[2]), '$options' => 'i');
                $filters[] = $filter;
            }
            else if ($param[1] == '!~' || $param[1] == '!LIKE') {
                $filter[$param[0]] = array('$not' => '/' . preg_quote($param[2]) . '/i');
                $filters[] = $filter;
            }
            else {
                $op = '';
                switch ($param[1]) {
                    case '>':  $op = '$gt';  break;
                    case '>=': $op = '$gte'; break;
                    case '<':  $op = '$lt';  break;
                    case '<=': $op = '$lte'; break;
                    case '!=':
                    case '<>': $op = '$gte'; break;
                }
                if ($op) {
                    $filter[$param[0]] = array($op => $param[2]);
                    $filters[] = $filter;
                }
            }
        }

        return array('$and' => $filters);
    }

    /**
     * Helper method to convert the given pseudo-query triplets into
     * an associative filter array with 'equals' values only
     */
    private function _query2assoc($query)
    {
        // extract object type from query parameter
        $filter = array();
        foreach ($query as $param) {
            if ($param[1] == '=')
                $filter[$param[0]] = $param[2];
        }
        return $filter;
    }

    /**
     * Fetch messages from IMAP
     *
     * @param array List of message UIDs to fetch
     * @return array List of parsed Kolab objects
     */
    private function _fetch($index, $type = null, $folder = null)
    {
        $results = array();
        foreach ((array)$index as $msguid) {
            if ($object = $this->folder->read_object($msguid, $type, $folder)) {
                $results[] = $object;
                $this->set($msguid, $object);
            }
        }

        return $results;
    }


    /**
     * Helper method to convert the given Kolab object into a dataset to be written to cache
     */
    private function _serialize($object, $msguid)
    {
        $bincols = array_flip($this->binary_cols);
        $doc = array(
            'resource' => $this->resource_uri,
            'type'     => $object['_type'] ? $object['_type'] : $this->folder->type,
            'msguid'   => $msguid,
            'uid'      => $object['uid'],
            'xml'      => '',
            'tags'     => array(),
            'words'    => array(),
            'objcols'  => array(),
        );

        // set type specific values
        if ($this->folder->type == 'event') {
            // database runs in server's timezone so using date() is what we want
            $doc['dtstart'] = date('Y-m-d H:i:s', is_object($object['start']) ? $object['start']->format('U') : $object['start']);
            $doc['dtend']   = date('Y-m-d H:i:s', is_object($object['end'])   ? $object['end']->format('U')   : $object['end']);

            // extend date range for recurring events
            if ($object['recurrence']) {
                $doc['dtend'] = date('Y-m-d H:i:s', $object['recurrence']['UNTIL'] ?: strtotime('now + 2 years'));
            }
        }

        if ($object['_formatobj']) {
            $doc['xml'] = preg_replace('!(</?[a-z0-9:-]+>)[\n\r\t\s]+!ms', '$1', (string)$object['_formatobj']->write());
            $doc['tags'] = $object['_formatobj']->get_tags();
            $doc['words'] = $object['_formatobj']->get_words();
        }

        // extract object data
        $data = array();
        foreach ($object as $key => $val) {
            if ($val === "" || $val === null) {
                // skip empty properties
                continue;
            }
            if (isset($bincols[$key])) {
                $data[$key] = base64_encode($val);
            }
            else if (is_object($val)) {
                if (is_a($val, 'DateTime')) {
                    $data[$key] = array('_class' => 'DateTime', 'date' => $val->format('Y-m-d H:i:s'), 'timezone' => $val->getTimezone()->getName());
                    $doc['objcols'][] = $key;
                }
            }
            else if ($key[0] != '_') {
                $data[$key] = $val;
            }
            else if ($key == '_attachments') {
                foreach ($val as $k => $att) {
                    unset($att['content'], $att['path']);
                    if ($att['id'])
                        $data[$key][$k] = $att;
                }
            }
        }

        $doc['data'] = $data;
        return $doc;
    }

    /**
     * Helper method to turn stored cache data into a valid storage object
     */
    private function _unserialize($doc)
    {
        $object = $doc['data'];

        // decode binary properties
        foreach ($this->binary_cols as $key) {
            if (!empty($object[$key]))
                $object[$key] = base64_decode($object[$key]);
        }

        // restore serialized objects
        foreach ((array)$doc['objcols'] as $key) {
            switch ($object[$key]['_class']) {
                case 'DateTime':
                    $val = new DateTime($object[$key]['date'], new DateTimeZone($object[$key]['timezone']));
                    $object[$key] = $val;
                    break;
            }
        }

        // add meta data
        $object['_type'] = $doc['type'];
        $object['_msguid'] = $doc['msguid'];
        $object['_mailbox'] = $this->folder->name;
        $object['_formatobj'] = kolab_format::factory($doc['type'], $doc['xml']);

        return $object;
    }

    /**
     * Check lock record for this folder and wait if locked or set lock
     */
    private function _sync_lock()
    {
        if (!$this->ready)
            return;

        $this->synclock = true;
        $lock = $this->db->locks->findOne(array('resource' => $this->resource_uri));

        // create lock record if not exists
        if (!$lock) {
            $this->db->locks->insert(array('resource' => $this->resource_uri, 'created' => time()));
        }
        // wait if locked (expire locks after 10 minutes)
        else if ((time() - $lock['created']) < 600) {
            usleep(500000);
            return $this->_sync_lock();
        }
        // set lock
        else {
            $lock['created'] = time();
            $this->db->locks->update(array('_id' => $lock['_id']), $lock, array('safe' => true));
        }
    }

    /**
     * Remove lock for this folder
     */
    public function _sync_unlock()
    {
        if (!$this->ready || !$this->synclock)
            return;

        $this->db->locks->remove(array('resource' => $this->resource_uri));
    }

    /**
     * Resolve an object UID into an IMAP message UID
     *
     * @param string  Kolab object UID
     * @param boolean Include deleted objects
     * @return int The resolved IMAP message UID
     */
    public function uid2msguid($uid, $deleted = false)
    {
        if (!isset($this->uid2msg[$uid])) {
            // use IMAP SEARCH to get the right message
            $index = $this->imap->search_once($this->folder->name, ($deleted ? '' : 'UNDELETED ') . 'HEADER SUBJECT ' . $uid);
            $results = $index->get();
            $this->uid2msg[$uid] = $results[0];
        }

        return $this->uid2msg[$uid];
    }

}
