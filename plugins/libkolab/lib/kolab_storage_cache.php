<?php

/**
 * Kolab storage cache class providing a local caching layer for Kolab groupware objects.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012-2013, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_storage_cache
{
    protected $db;
    protected $imap;
    protected $folder;
    protected $uid2msg;
    protected $objects;
    protected $index = null;
    protected $metadata = array();
    protected $folder_id;
    protected $resource_uri;
    protected $enabled = true;
    protected $synched = false;
    protected $synclock = false;
    protected $ready = false;
    protected $cache_table;
    protected $folders_table;
    protected $max_sql_packet;
    protected $max_sync_lock_time = 600;
    protected $binary_items = array();
    protected $extra_cols = array();


    /**
     * Factory constructor
     */
    public static function factory(kolab_storage_folder $storage_folder)
    {
        $subclass = 'kolab_storage_cache_' . $storage_folder->type;
        if (class_exists($subclass)) {
            return new $subclass($storage_folder);
        }
        else {
            rcube::raise_error(array(
                'code' => 900,
                'type' => 'php',
                'message' => "No kolab_storage_cache class found for folder '$storage_folder->name' of type '$storage_folder->type'"
            ), true);

            return new kolab_storage_cache($storage_folder);
        }
    }


    /**
     * Default constructor
     */
    public function __construct(kolab_storage_folder $storage_folder = null)
    {
        $rcmail = rcube::get_instance();
        $this->db = $rcmail->get_dbh();
        $this->imap = $rcmail->get_storage();
        $this->enabled = $rcmail->config->get('kolab_cache', false);

        if ($this->enabled) {
            // always read folder cache and lock state from DB master
            $this->db->set_table_dsn('kolab_folders', 'w');
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
        $this->folders_table = $this->db->table_name('kolab_folders');
        $this->cache_table = $this->db->table_name('kolab_cache_' . $this->folder->type);
        $this->ready = $this->enabled && !empty($this->folder->type);
        $this->folder_id = null;
    }

    /**
     * Returns true if this cache supports query by type
     */
    public function has_type_col()
    {
        return in_array('type', $this->extra_cols);
    }

    /**
     * Getter for the numeric ID used in cache tables
     */
    public function get_folder_id()
    {
        $this->_read_folder_data();
        return $this->folder_id;
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
        @set_time_limit($this->max_sync_lock_time);

        // read cached folder metadata
        $this->_read_folder_data();

        // check cache status hash first ($this->metadata is set in _read_folder_data())
        if ($this->metadata['ctag'] != $this->folder->get_ctag()) {
            // lock synchronization for this folder or wait if locked
            $this->_sync_lock();

            // disable messages cache if configured to do so
            $this->bypass(true);

            // synchronize IMAP mailbox cache
            $this->imap->folder_sync($this->folder->name);

            // compare IMAP index with object cache index
            $imap_index = $this->imap->index($this->folder->name, null, null, true, true);
            $this->index = $imap_index->get();

            // determine objects to fetch or to invalidate
            if ($this->ready) {
                // read cache index
                $sql_result = $this->db->query(
                    "SELECT msguid, uid FROM $this->cache_table WHERE folder_id=?",
                    $this->folder_id
                );

                $old_index = array();
                while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                    $old_index[] = $sql_arr['msguid'];
                    $this->uid2msg[$sql_arr['uid']] = $sql_arr['msguid'];
                }

                // fetch new objects from imap
                foreach (array_diff($this->index, $old_index) as $msguid) {
                    if ($object = $this->folder->read_object($msguid, '*')) {
                        $this->_extended_insert($msguid, $object);
                    }
                }
                $this->_extended_insert(0, null);

                // delete invalid entries from local DB
                $del_index = array_diff($old_index, $this->index);
                if (!empty($del_index)) {
                    $quoted_ids = join(',', array_map(array($this->db, 'quote'), $del_index));
                    $this->db->query(
                        "DELETE FROM $this->cache_table WHERE folder_id=? AND msguid IN ($quoted_ids)",
                        $this->folder_id
                    );
                }

                // update ctag value (will be written to database in _sync_unlock())
                $this->metadata['ctag'] = $this->folder->get_ctag();
            }

            $this->bypass(false);

            // remove lock
            $this->_sync_unlock();
        }

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
            return kolab_storage::get_folder($foldername)->cache->get($msguid, $type);
        }

        // load object if not in memory
        if (!isset($this->objects[$msguid])) {
            if ($this->ready) {
                $this->_read_folder_data();

                $sql_result = $this->db->query(
                    "SELECT * FROM $this->cache_table ".
                    "WHERE folder_id=? AND msguid=?",
                    $this->folder_id,
                    $msguid
                );

                if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                    $this->objects = array($msguid => $this->_unserialize($sql_arr));  // store only this object in memory (#2827)
                }
            }

            // fetch from IMAP if not present in cache
            if (empty($this->objects[$msguid])) {
                $result = $this->_fetch(array($msguid), $type, $foldername);
                $this->objects = array($msguid => $result[0]);  // store only this object in memory (#2827)
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
        if (!$msguid) {
            return;
        }

        // delegate to another cache instance
        if ($foldername && $foldername != $this->folder->name) {
            kolab_storage::get_folder($foldername)->cache->set($msguid, $object);
            return;
        }

        // remove old entry
        if ($this->ready) {
            $this->_read_folder_data();
            $this->db->query("DELETE FROM $this->cache_table WHERE folder_id=? AND msguid=?",
                $this->folder_id, $msguid);
        }

        if ($object) {
            // insert new object data...
            $this->insert($msguid, $object);
        }
        else {
            // ...or set in-memory cache to false
            $this->objects[$msguid] = $object;
        }
    }


    /**
     * Insert a cache entry
     *
     * @param string Related IMAP message UID
     * @param mixed  Hash array with object properties to save or false to delete the cache entry
     */
    public function insert($msguid, $object)
    {
        // write to cache
        if ($this->ready) {
            $this->_read_folder_data();

            $sql_data = $this->_serialize($object);

            $extra_cols   = $this->extra_cols ? ', ' . join(', ', $this->extra_cols) : '';
            $extra_fields = $this->extra_cols ? str_repeat(', ?', count($this->extra_cols)) : '';

            $args = array(
                "INSERT INTO $this->cache_table ".
                " (folder_id, msguid, uid, created, changed, data, xml, tags, words $extra_cols)".
                " VALUES (?, ?, ?, " . $this->db->now() . ", ?, ?, ?, ?, ? $extra_fields)",
                $this->folder_id,
                $msguid,
                $object['uid'],
                $sql_data['changed'],
                $sql_data['data'],
                $sql_data['xml'],
                $sql_data['tags'],
                $sql_data['words'],
            );

            foreach ($this->extra_cols as $col) {
                $args[] = $sql_data[$col];
            }

            $result = call_user_func_array(array($this->db, 'query'), $args);

            if (!$this->db->affected_rows($result)) {
                rcube::raise_error(array(
                    'code' => 900, 'type' => 'php',
                    'message' => "Failed to write to kolab cache"
                ), true);
            }
        }

        // keep a copy in memory for fast access
        $this->objects = array($msguid => $object);
        $this->uid2msg = array($object['uid'] => $msguid);
    }


    /**
     * Move an existing cache entry to a new resource
     *
     * @param string Entry's IMAP message UID
     * @param string Entry's Object UID
     * @param string Target IMAP folder to move it to
     */
    public function move($msguid, $uid, $target_folder)
    {
        $target = kolab_storage::get_folder($target_folder);

        // resolve new message UID in target folder
        if ($new_msguid = $target->cache->uid2msguid($uid)) {
            $this->_read_folder_data();

            $this->db->query(
                "UPDATE $this->cache_table SET folder_id=?, msguid=? ".
                "WHERE folder_id=? AND msguid=?",
                $target->cache->get_folder_id(),
                $new_msguid,
                $this->folder_id,
                $msguid
            );
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
        $this->_read_folder_data();

        $result = $this->db->query(
            "DELETE FROM $this->cache_table WHERE folder_id=?",
            $this->folder_id
        );
        return $this->db->affected_rows($result);
    }

    /**
     * Update resource URI for existing cache entries
     *
     * @param string Target IMAP folder to move it to
     */
    public function rename($new_folder)
    {
        $target = kolab_storage::get_folder($new_folder);

        // resolve new message UID in target folder
        $this->db->query(
            "UPDATE $this->folders_table SET resource=? ".
            "WHERE resource=?",
            $target->get_resource_uri(),
            $this->resource_uri
        );
    }

    /**
     * Select Kolab objects filtered by the given query
     *
     * @param array Pseudo-SQL query as list of filter parameter triplets
     *   triplet: array('<colname>', '<comparator>', '<value>')
     * @param boolean Set true to only return UIDs instead of complete objects
     * @return array List of Kolab data objects (each represented as hash array) or UIDs
     */
    public function select($query = array(), $uids = false)
    {
        $result = $uids ? array() : new kolab_storage_dataset($this);

        // read from local cache DB (assume it to be synchronized)
        if ($this->ready) {
            $this->_read_folder_data();

            // fetch full object data on one query if a small result set is expected
            $fetchall = !$uids && $this->count($query) < 500;
            $sql_result = $this->db->query(
                "SELECT " . ($fetchall ? '*' : 'msguid AS _msguid, uid') . " FROM $this->cache_table ".
                "WHERE folder_id=? " . $this->_sql_where($query),
                $this->folder_id
            );

            if ($this->db->is_error($sql_result)) {
                if (!$uids) $result->set_error(true);
                return $result;
            }

            while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                if ($uids) {
                    $this->uid2msg[$sql_arr['uid']] = $sql_arr['_msguid'];
                    $result[] = $sql_arr['uid'];
                }
                else if ($fetchall && ($object = $this->_unserialize($sql_arr))) {
                    $result[] = $object;
                }
                else {
                    // only add msguid to dataset index
                    $result[] = $sql_arr;
                }
            }
        }
        else {
            // extract object type from query parameter
            $filter = $this->_query2assoc($query);

            // use 'list' for folder's default objects
            if (is_array($this->index) && $filter['type'] == $this->type) {
                $index = $this->index;
            }
            else {  // search by object type
                $search = 'UNDELETED HEADER X-Kolab-Type ' . kolab_format::KTYPE_PREFIX . $filter['type'];
                $index  = $this->imap->search_once($this->folder->name, $search)->get();
            }

            // fetch all messages in $index from IMAP
            $result = $uids ? $this->_fetch_uids($index, $filter['type']) : $this->_fetch($index, $filter['type']);

            // TODO: post-filter result according to query
        }

        // We don't want to cache big results in-memory, however
        // if we select only one object here, there's a big chance we will need it later
        if (!$uids && count($result) == 1) {
            if ($msguid = $result[0]['_msguid']) {
                $this->uid2msg[$result[0]['uid']] = $msguid;
                $this->objects = array($msguid => $result[0]);
            }
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
        // cache is in sync, we can count records in local DB
        if ($this->synched) {
            $this->_read_folder_data();

            $sql_result = $this->db->query(
                "SELECT COUNT(*) AS numrows FROM $this->cache_table ".
                "WHERE folder_id=? " . $this->_sql_where($query),
                $this->folder_id
            );

            if ($this->db->is_error($sql_result)) {
                return null;
            }

            $sql_arr = $this->db->fetch_assoc($sql_result);
            $count   = intval($sql_arr['numrows']);
        }
        else {
            // search IMAP by object type
            $filter = $this->_query2assoc($query);
            $ctype  = kolab_format::KTYPE_PREFIX . $filter['type'];
            $index  = $this->imap->search_once($this->folder->name, 'UNDELETED HEADER X-Kolab-Type ' . $ctype);

            if ($index->is_error()) {
                return null;
            }

            $count = $index->count();
        }

        return $count;
    }


    /**
     * Helper method to compose a valid SQL query from pseudo filter triplets
     */
    protected function _sql_where($query)
    {
        $sql_where = '';
        foreach ((array) $query as $param) {
            if (is_array($param[0])) {
                $subq = array();
                foreach ($param[0] as $q) {
                    $subq[] = preg_replace('/^\s*AND\s+/i', '', $this->_sql_where(array($q)));
                }
                if (!empty($subq)) {
                    $sql_where .= ' AND (' . implode($param[1] == 'OR' ? ' OR ' : ' AND ', $subq) . ')';
                }
                continue;
            }
            else if ($param[1] == '=' && is_array($param[2])) {
                $qvalue = '(' . join(',', array_map(array($this->db, 'quote'), $param[2])) . ')';
                $param[1] = 'IN';
            }
            else if ($param[1] == '~' || $param[1] == 'LIKE' || $param[1] == '!~' || $param[1] == '!LIKE') {
                $not = ($param[1] == '!~' || $param[1] == '!LIKE') ? 'NOT ' : '';
                $param[1] = $not . 'LIKE';
                $qvalue = $this->db->quote('%'.preg_replace('/(^\^|\$$)/', ' ', $param[2]).'%');
            }
            else if ($param[0] == 'tags') {
                $param[1] = 'LIKE';
                $qvalue = $this->db->quote('% '.$param[2].' %');
            }
            else {
                $qvalue = $this->db->quote($param[2]);
            }

            $sql_where .= sprintf(' AND %s %s %s',
                $this->db->quote_identifier($param[0]),
                $param[1],
                $qvalue
            );
        }

        return $sql_where;
    }

    /**
     * Helper method to convert the given pseudo-query triplets into
     * an associative filter array with 'equals' values only
     */
    protected function _query2assoc($query)
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
     * @param array  List of message UIDs to fetch
     * @param string Requested object type or * for all
     * @param string IMAP folder to read from
     * @return array List of parsed Kolab objects
     */
    protected function _fetch($index, $type = null, $folder = null)
    {
        $results = new kolab_storage_dataset($this);
        foreach ((array)$index as $msguid) {
            if ($object = $this->folder->read_object($msguid, $type, $folder)) {
                $results[] = $object;
                $this->set($msguid, $object);
            }
        }

        return $results;
    }


    /**
     * Fetch object UIDs (aka message subjects) from IMAP
     *
     * @param array List of message UIDs to fetch
     * @param string Requested object type or * for all
     * @param string IMAP folder to read from
     * @return array List of parsed Kolab objects
     */
    protected function _fetch_uids($index, $type = null)
    {
        if (!$type)
            $type = $this->folder->type;

        $this->bypass(true);

        $results = array();
        $headers = $this->imap->fetch_headers($this->folder->name, $index, false);

        $this->bypass(false);

        foreach ((array)$headers as $msguid => $headers) {
            $object_type = kolab_format::mime2object_type($headers->others['x-kolab-type']);

            // check object type header and abort on mismatch
            if ($type != '*' && $object_type != $type)
                return false;

            $uid = $headers->subject;
            $this->uid2msg[$uid] = $msguid;
            $results[] = $uid;
        }

        return $results;
    }


    /**
     * Helper method to convert the given Kolab object into a dataset to be written to cache
     */
    protected function _serialize($object)
    {
        $sql_data = array('changed' => null, 'xml' => '', 'tags' => '', 'words' => '');

        if ($object['changed']) {
            $sql_data['changed'] = date('Y-m-d H:i:s', is_object($object['changed']) ? $object['changed']->format('U') : $object['changed']);
        }

        if ($object['_formatobj']) {
            $sql_data['xml']   = preg_replace('!(</?[a-z0-9:-]+>)[\n\r\t\s]+!ms', '$1', (string)$object['_formatobj']->write(3.0));
            $sql_data['tags']  = ' ' . join(' ', $object['_formatobj']->get_tags()) . ' ';  // pad with spaces for strict/prefix search
            $sql_data['words'] = ' ' . join(' ', $object['_formatobj']->get_words()) . ' ';
        }

        // extract object data
        $data = array();
        foreach ($object as $key => $val) {
            // skip empty properties
            if ($val === "" || $val === null) {
                continue;
            }
            // mark binary data to be extracted from xml on unserialize()
            if (isset($this->binary_items[$key])) {
                $data[$key] = true;
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

        // use base64 encoding (Bug #1912, #2662)
        $sql_data['data'] = base64_encode(serialize($data));

        return $sql_data;
    }

    /**
     * Helper method to turn stored cache data into a valid storage object
     */
    protected function _unserialize($sql_arr)
    {
        // check if data is a base64-encoded string, for backward compat.
        if (strpos(substr($sql_arr['data'], 0, 64), ':') === false) {
            $sql_arr['data'] = base64_decode($sql_arr['data']);
        }

        $object = unserialize($sql_arr['data']);

        // de-serialization failed
        if ($object === false) {
            rcube::raise_error(array(
                'code' => 900, 'type' => 'php',
                'message' => "Malformed data for {$this->resource_uri}/{$sql_arr['msguid']} object."
            ), true);

            return null;
        }

        // decode binary properties
        foreach ($this->binary_items as $key => $regexp) {
            if (!empty($object[$key]) && preg_match($regexp, $sql_arr['xml'], $m)) {
                $object[$key] = base64_decode($m[1]);
            }
        }

        // add meta data
        $object['_type']      = $sql_arr['type'] ?: $this->folder->type;
        $object['_msguid']    = $sql_arr['msguid'];
        $object['_mailbox']   = $this->folder->name;
        $object['_size']      = strlen($sql_arr['xml']);
        $object['_formatobj'] = kolab_format::factory($object['_type'], 3.0, $sql_arr['xml']);

        return $object;
    }

    /**
     * Write records into cache using extended inserts to reduce the number of queries to be executed
     *
     * @param int  Message UID. Set 0 to commit buffered inserts
     * @param array Kolab object to cache
     */
    protected function _extended_insert($msguid, $object)
    {
        static $buffer = '';

        $line = '';
        if ($object) {
            $sql_data = $this->_serialize($object);
            $values = array(
                $this->db->quote($this->folder_id),
                $this->db->quote($msguid),
                $this->db->quote($object['uid']),
                $this->db->now(),
                $this->db->quote($sql_data['changed']),
                $this->db->quote($sql_data['data']),
                $this->db->quote($sql_data['xml']),
                $this->db->quote($sql_data['tags']),
                $this->db->quote($sql_data['words']),
            );
            foreach ($this->extra_cols as $col) {
                $values[] = $this->db->quote($sql_data[$col]);
            }
            $line = '(' . join(',', $values) . ')';
        }

        if ($buffer && (!$msguid || (strlen($buffer) + strlen($line) > $this->max_sql_packet()))) {
            $extra_cols = $this->extra_cols ? ', ' . join(', ', $this->extra_cols) : '';
            $result = $this->db->query(
                "INSERT INTO $this->cache_table ".
                " (folder_id, msguid, uid, created, changed, data, xml, tags, words $extra_cols)".
                " VALUES $buffer"
            );
            if (!$this->db->affected_rows($result)) {
                rcube::raise_error(array(
                    'code' => 900, 'type' => 'php',
                    'message' => "Failed to write to kolab cache"
                ), true);
            }

            $buffer = '';
        }

        $buffer .= ($buffer ? ',' : '') . $line;
    }

    /**
     * Returns max_allowed_packet from mysql config
     */
    protected function max_sql_packet()
    {
        if (!$this->max_sql_packet) {
            // mysql limit or max 4 MB
            $value = $this->db->get_variable('max_allowed_packet', 1048500);
            $this->max_sql_packet = min($value, 4*1024*1024) - 2000;
        }

        return $this->max_sql_packet;
    }

    /**
     * Read this folder's ID and cache metadata
     */
    protected function _read_folder_data()
    {
        // already done
        if (!empty($this->folder_id))
            return;

        $sql_arr = $this->db->fetch_assoc($this->db->query("SELECT folder_id, synclock, ctag FROM $this->folders_table WHERE resource=?", $this->resource_uri));
        if ($sql_arr) {
            $this->metadata = $sql_arr;
            $this->folder_id = $sql_arr['folder_id'];
        }
        else {
            $this->db->query("INSERT INTO $this->folders_table (resource, type) VALUES (?, ?)", $this->resource_uri, $this->folder->type);
            $this->folder_id = $this->db->insert_id('kolab_folders');
            $this->metadata = array();
        }
    }

    /**
     * Check lock record for this folder and wait if locked or set lock
     */
    protected function _sync_lock()
    {
        if (!$this->ready)
            return;

        $this->_read_folder_data();
        $sql_query = "SELECT synclock, ctag FROM $this->folders_table WHERE folder_id=?";

        // abort if database is not set-up
        if ($this->db->is_error()) {
            $this->ready = false;
            return;
        }

        $this->synclock = true;

        // wait if locked (expire locks after 10 minutes)
        while ($this->metadata && intval($this->metadata['synclock']) > 0 && $this->metadata['synclock'] + $this->max_sync_lock_time > time()) {
            usleep(500000);
            $this->metadata = $this->db->fetch_assoc($this->db->query($sql_query, $this->folder_id));
        }

        // set lock
        $this->db->query("UPDATE $this->folders_table SET synclock = ? WHERE folder_id = ?", time(), $this->folder_id);
    }

    /**
     * Remove lock for this folder
     */
    public function _sync_unlock()
    {
        if (!$this->ready || !$this->synclock)
            return;

        $this->db->query(
            "UPDATE $this->folders_table SET synclock = 0, ctag = ? WHERE folder_id = ?",
            $this->metadata['ctag'],
            $this->folder_id
        );

        $this->synclock = false;
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
            $index = $this->imap->search_once($this->folder->name, ($deleted ? '' : 'UNDELETED ') .
                'HEADER SUBJECT ' . rcube_imap_generic::escape($uid));
            $results = $index->get();
            $this->uid2msg[$uid] = $results[0];
        }

        return $this->uid2msg[$uid];
    }

    /**
     * Getter for protected member variables
     */
    public function __get($name)
    {
        if ($name == 'folder_id') {
            $this->_read_folder_data();
        }

        return $this->$name;
    }

    /**
     * Bypass Roundcube messages cache.
     * Roundcube cache duplicates information already stored in kolab_cache.
     *
     * @param bool $disable True disables, False enables messages cache
     */
    public function bypass($disable = false)
    {
        // if kolab cache is disabled do nothing
        if (!$this->enabled) {
            return;
        }

        static $messages_cache, $cache_bypass;

        if ($messages_cache === null) {
            $rcmail = rcube::get_instance();
            $messages_cache = (bool) $rcmail->config->get('messages_cache');
            $cache_bypass   = (int) $rcmail->config->get('kolab_messages_cache_bypass');
        }

        if ($messages_cache) {
            // handle recurrent (multilevel) bypass() calls
            if ($disable) {
                $this->cache_bypassed += 1;
                if ($this->cache_bypassed > 1) {
                    return;
                }
            }
            else {
                $this->cache_bypassed -= 1;
                if ($this->cache_bypassed > 0) {
                    return;
                }
            }

            switch ($cache_bypass) {
                case 2:
                    // Disable messages cache completely
                    $this->imap->set_messages_caching(!$disable);
                    break;

                case 1:
                    // We'll disable messages cache, but keep index cache.
                    // Default mode is both (MODE_INDEX | MODE_MESSAGE)
                    $mode = rcube_imap_cache::MODE_INDEX;

                    if (!$disable) {
                        $mode |= rcube_imap_cache::MODE_MESSAGE;
                    }

                    $this->imap->set_messages_caching(true, $mode);
            }
        }
    }

}
