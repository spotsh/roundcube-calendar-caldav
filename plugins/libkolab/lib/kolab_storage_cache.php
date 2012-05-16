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

class kolab_storage_cache
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
    private $ready = false;

    private $binary_cols = array('photo','pgppublickey','pkcs7publickey');


    /**
     * Default constructor
     */
    public function __construct(kolab_storage_folder $storage_folder = null)
    {
        $rcmail = rcube::get_instance();
        $this->db = $rcmail->get_dbh();
        $this->imap = $rcmail->get_storage();
        $this->enabled = $rcmail->config->get('kolab_cache', false);

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
            $sql_result = $this->db->query(
                "SELECT msguid, uid FROM kolab_cache WHERE resource=? AND type<>?",
                $this->resource_uri,
                'lock'
            );

            $old_index = array();
            while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                $old_index[] = $sql_arr['msguid'];
                $this->uid2msg[$sql_arr['uid']] = $sql_arr['msguid'];
            }

            // fetch new objects from imap
            $fetch_index = array_diff($this->index, $old_index);
            foreach ($this->_fetch($fetch_index, '*') as $object) {
                $msguid = $object['_msguid'];
                $this->set($msguid, $object);
            }

            // delete invalid entries from local DB
            $del_index = array_diff($old_index, $this->index);
            if (!empty($del_index)) {
                $quoted_ids = join(',', array_map(array($this->db, 'quote'), $del_index));
                $this->db->query(
                    "DELETE FROM kolab_cache WHERE resource=? AND msguid IN ($quoted_ids)",
                    $this->resource_uri
                );
            }
        }

        // remove lock
        $this->_sync_unlock();

        $this->synched = time();
    }


    /**
     * Read a single entry from cache or
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
            if ($this->ready) {
                $sql_result = $this->db->query(
                    "SELECT * FROM kolab_cache ".
                    "WHERE resource=? AND msguid=?",
                    $this->resource_uri,
                    $msguid
                );

                if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                    $this->objects[$msguid] = $this->_unserialize($sql_arr);
                }
            }

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
            $this->db->query("DELETE FROM kolab_cache WHERE resource=? AND msguid=?",
                $this->resource_uri, $msguid);

            // write new object data if not false (wich means deleted)
            if ($object) {
                $sql_data = $this->_serialize($object);
                $objtype = $object['_type'] ? $object['_type'] : $this->folder->type;

                $result = $this->db->query(
                    "INSERT INTO kolab_cache ".
                    " (resource, type, msguid, uid, data, xml, dtstart, dtend, tags, words)".
                    " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    $this->resource_uri,
                    $objtype,
                    $msguid,
                    $object['uid'],
                    $sql_data['data'],
                    $sql_data['xml'],
                    $sql_data['dtstart'],
                    $sql_data['dtend'],
                    $sql_data['tags'],
                    $sql_data['words']
                );

                if (!$this->db->affected_rows($result)) {
                    rcmail::raise_error(array(
                        'code' => 900, 'type' => 'php',
                        'message' => "Failed to write to kolab cache"
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
            $this->db->query(
                "UPDATE kolab_cache SET resource=?, msguid=? ".
                "WHERE resource=? AND msguid=?",
                $target->get_resource_uri(),
                $new_msguid,
                $this->resource_uri,
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
        $result = $this->db->query(
            "DELETE FROM kolab_cache WHERE resource=?".
            ($type ? ' AND type=?' : ''),
            $this->resource_uri,
            $type
        );
        return $this->db->affected_rows($result);
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
            $sql_result = $this->db->query(
                "SELECT * FROM kolab_cache ".
                "WHERE resource=? " . $this->_sql_where($query),
                $this->resource_uri
            );

            while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                if ($object = $this->_unserialize($sql_arr))
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
            $sql_result = $this->db->query(
                "SELECT COUNT(*) AS NUMROWS FROM kolab_cache ".
                "WHERE resource=? " . $this->_sql_where($query),
                $this->resource_uri
            );

            $sql_arr = $this->db->fetch_assoc($sql_result);
            $count = intval($sql_arr['NUMROWS']);
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
     * Helper method to compose a valid SQL query from pseudo filter triplets
     */
    private function _sql_where($query)
    {
        $sql_where = '';
        foreach ($query as $param) {
            if ($param[1] == '=' && is_array($param[2])) {
                $qvalue = '(' . join(',', array_map(array($this->db, 'quote'), $param[2])) . ')';
                $param[1] = 'IN';
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
                $this->uid2msg[$object['uid']] = $msguid;
            }
        }

        return $results;
    }


    /**
     * Helper method to convert the given Kolab object into a dataset to be written to cache
     */
    private function _serialize($object)
    {
        $bincols = array_flip($this->binary_cols);
        $sql_data = array('dtstart' => null, 'dtend' => null, 'xml' => '', 'tags' => '', 'words' => '');

        // set type specific values
        if ($this->folder->type == 'event') {
            // database runs in server's timezone so using date() is what we want
            $sql_data['dtstart'] = date('Y-m-d H:i:s', is_object($object['start']) ? $object['start']->format('U') : $object['start']);
            $sql_data['dtend']   = date('Y-m-d H:i:s', is_object($object['end'])   ? $object['end']->format('U')   : $object['end']);

            // extend date range for recurring events
            if ($object['recurrence']) {
                $sql_data['dtend'] = date('Y-m-d H:i:s', $object['recurrence']['UNTIL'] ?: strtotime('now + 2 years'));
            }
        }

        if ($object['_formatobj']) {
            $sql_data['xml'] = (string)$object['_formatobj']->write();
            $sql_data['tags'] = ' ' . join(' ', $object['_formatobj']->get_tags()) . ' ';  // pad with spaces for strict/prefix search
            $sql_data['words'] = ' ' . join(' ', $object['_formatobj']->get_words()) . ' ';
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

        $sql_data['data'] = serialize($data);
        return $sql_data;
    }

    /**
     * Helper method to turn stored cache data into a valid storage object
     */
    private function _unserialize($sql_arr)
    {
        $object = unserialize($sql_arr['data']);

        // decode binary properties
        foreach ($this->binary_cols as $key) {
            if (!empty($object[$key]))
                $object[$key] = base64_decode($object[$key]);
        }

        // add meta data
        $object['_type'] = $sql_arr['type'];
        $object['_msguid'] = $sql_arr['msguid'];
        $object['_mailbox'] = $this->folder->name;
        $object['_formatobj'] = kolab_format::factory($sql_arr['type'], $sql_arr['xml']);

        return $object;
    }

    /**
     * Check lock record for this folder and wait if locked or set lock
     */
    private function _sync_lock()
    {
        if (!$this->ready)
            return;

        $sql_arr = $this->db->fetch_assoc($this->db->query(
            "SELECT msguid AS locked, ".$this->db->unixtimestamp('created')." AS created FROM kolab_cache ".
            "WHERE resource=? AND type=?",
            $this->resource_uri,
            'lock'
        ));

        // create lock record if not exists
        if (!$sql_arr) {
            $this->db->query(
                "INSERT INTO kolab_cache (resource, type, msguid, created, uid, data, xml)".
                " VALUES (?, ?, 1, ?, '', '', '')",
                $this->resource_uri,
                'lock',
                date('Y-m-d H:i:s')
            );
        }
        // wait if locked (expire locks after 10 minutes)
        else if (intval($sql_arr['locked']) > 0 && (time() - $sql_arr['created']) < 600) {
            usleep(500000);
            return $this->_sync_lock();
        }
        // set lock
        else {
            $this->db->query(
                "UPDATE kolab_cache SET msguid=1, created=? ".
                "WHERE resource=? AND type=?",
                date('Y-m-d H:i:s'),
                $this->resource_uri,
                'lock'
            );
        }
    }

    /**
     * Remove lock for this folder
     */
    private function _sync_unlock()
    {
        $this->db->query(
            "UPDATE kolab_cache SET msguid=0, created='' ".
            "WHERE resource=? AND type=?",
            $this->resource_uri,
            'lock'
        );
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
