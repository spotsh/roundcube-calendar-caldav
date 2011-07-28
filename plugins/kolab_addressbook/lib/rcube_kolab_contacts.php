<?php


/**
 * Backend class for a custom address book
 *
 * This part of the Roundcube+Kolab integration and connects the
 * rcube_addressbook interface with the rcube_kolab wrapper for Kolab_Storage
 *
 * @author Thomas Bruederli
 * @see rcube_addressbook
 */
class rcube_kolab_contacts extends rcube_addressbook
{
    public $primary_key = 'ID';
    public $readonly = true;
    public $editable = false;
    public $undelete = true;
    public $groups = true;
    public $coltypes = array(
      'name'         => array('limit' => 1),
      'firstname'    => array('limit' => 1),
      'surname'      => array('limit' => 1),
      'middlename'   => array('limit' => 1),
      'prefix'       => array('limit' => 1),
      'suffix'       => array('limit' => 1),
      'nickname'     => array('limit' => 1),
      'jobtitle'     => array('limit' => 1),
      'organization' => array('limit' => 1),
      'department'   => array('limit' => 1),
      'email'        => array('subtypes' => null),
      'phone'        => array(),
      'address'      => array('limit' => 2, 'subtypes' => array('home','business')),
      'officelocation' => array('type' => 'text', 'size' => 40, 'limit' => 1,
                                'label' => 'kolab_addressbook.officelocation', 'category' => 'main'),
      'website'      => array('limit' => 1, 'subtypes' => null),
      'im'           => array('limit' => 1, 'subtypes' => null),
      'gender'       => array('limit' => 1),
      'initials'     => array('type' => 'text', 'size' => 6, 'limit' => 1,
                                'label' => 'kolab_addressbook.initials', 'category' => 'personal'),
      'birthday'     => array('limit' => 1),
      'anniversary'  => array('limit' => 1),
      'profession'   => array('type' => 'text', 'size' => 40, 'limit' => 1,
                                'label' => 'kolab_addressbook.profession', 'category' => 'personal'),
      'manager'      => array('limit' => 1),
      'assistant'    => array('limit' => 1),
      'spouse'       => array('limit' => 1),
      'children'     => array('type' => 'text', 'size' => 40, 'limit' => 1,
                                'label' => 'kolab_addressbook.children', 'category' => 'personal'),
      'pgppublickey' => array('type' => 'text', 'size' => 40, 'limit' => 1,
                                'label' => 'kolab_addressbook.pgppublickey'),
      'freebusyurl'  => array('type' => 'text', 'size' => 40, 'limit' => 1,
                                'label' => 'kolab_addressbook.freebusyurl'),
      'notes'        => array(),
      'photo'        => array(),
      // TODO: define more Kolab-specific fields such as: language, latitude, longitude
    );

    private $gid;
    private $storagefolder;
    private $contactstorage;
    private $liststorage;
    private $contacts;
    private $distlists;
    private $groupmembers;
    private $id2uid;
    private $filter;
    private $result;
    private $namespace;
    private $imap_folder = 'INBOX/Contacts';
    private $gender_map = array(0 => 'male', 1 => 'female');
    private $phonetypemap = array('home' => 'home1', 'work' => 'business1', 'work2' => 'business2', 'workfax' => 'businessfax');
    private $addresstypemap = array('work' => 'business');
    private $fieldmap = array(
      // kolab       => roundcube
      'full-name'    => 'name',
      'given-name'   => 'firstname',
      'middle-names' => 'middlename',
      'last-name'    => 'surname',
      'prefix'       => 'prefix',
      'suffix'       => 'suffix',
      'nick-name'    => 'nickname',
      'organization' => 'organization',
      'department'   => 'department',
      'job-title'    => 'jobtitle',
      'initials'     => 'initials',
      'birthday'     => 'birthday',
      'anniversary'  => 'anniversary',
      'im-address'   => 'im',
      'web-page'     => 'website',
      'office-location' => 'officelocation',
      'profession'   => 'profession',
      'manager-name' => 'manager',
      'assistant'    => 'assistant',
      'spouse-name'  => 'spouse',
      'children'     => 'children',
      'body'         => 'notes',
      'pgp-publickey' => 'pgppublickey',
      'free-busy-url' => 'freebusyurl',
    );


    public function __construct($imap_folder = null)
    {
        if ($imap_folder)
            $this->imap_folder = $imap_folder;

        // extend coltypes configuration 
        $format = rcube_kolab::get_format('contact');
        $this->coltypes['phone']['subtypes'] = $format->_phone_types;
        $this->coltypes['address']['subtypes'] = $format->_address_types;

        // set localized labels for proprietary cols
        foreach ($this->coltypes as $col => $prop) {
            if (is_string($prop['label']))
                $this->coltypes[$col]['label'] = rcube_label($prop['label']);
        }

        // fetch objects from the given IMAP folder
        $this->storagefolder = rcube_kolab::get_folder($this->imap_folder);
        $this->ready = !PEAR::isError($this->storagefolder);

        // Set readonly and editable flags according to folder permissions
        if ($this->ready) {
            if ($this->get_owner() == $_SESSION['username']) {
                $this->editable = true;
                $this->readonly = false;
            }
            else {
                $acl = $this->storagefolder->getACL();
                if (!PEAR::isError($acl) && is_array($acl)) {
                    $acl = $acl[$_SESSION['username']];
                    if (strpos($acl, 'i') !== false)
                        $this->readonly = false;
                    if (strpos($acl, 'a') !== false || strpos($acl, 'x') !== false)
                        $this->editable = true;
                }
            }
        }
    }


    /**
     * Getter for the address book name to be displayed
     *
     * @return string Name of this address book
     */
    public function get_name()
    {
        $folder = rcube_kolab::object_name($this->imap_folder, $this->namespace);
        return $folder;
    }


    /**
     * Getter for the IMAP folder name
     *
     * @return string Name of the IMAP folder
     */
    public function get_realname()
    {
        return $this->imap_folder;
    }


    /**
     * Getter for the IMAP folder owner
     *
     * @return string Name of the folder owner
     */
    public function get_owner()
    {
        return $this->storagefolder->getOwner();
    }


    /**
     * Getter for the name of the namespace to which the IMAP folder belongs
     *
     * @return string Name of the namespace (personal, other, shared)
     */
    public function get_namespace()
    {
        if ($this->namespace === null) {
            $this->namespace = rcube_kolab::folder_namespace($this->imap_folder);
        }

        return $this->namespace;
    }


    /**
     * Setter for the current group
     */
    public function set_group($gid)
    {
        $this->gid = $gid;
    }


    /**
     * Save a search string for future listings
     *
     * @param mixed Search params to use in listing method, obtained by get_search_set()
     */
    public function set_search_set($filter)
    {
        $this->filter = $filter;
    }


    /**
     * Getter for saved search properties
     *
     * @return mixed Search properties used by this class
     */
    public function get_search_set()
    {
        return $this->filter;
    }


    /**
     * Reset saved results and search parameters
     */
    public function reset()
    {
        $this->result = null;
        $this->filter = null;
    }


    /**
     * List all active contact groups of this source
     *
     * @param string  Optional search string to match group name
     * @return array  Indexed list of contact groups, each a hash array
     */
    function list_groups($search = null)
    {
        $this->_fetch_groups();
        $groups = array();
        foreach ((array)$this->distlists as $group) {
            if (!$search || strstr(strtolower($group['last-name']), strtolower($search)))
                $groups[$group['last-name']] = array('ID' => $group['ID'], 'name' => $group['last-name']);
        }

        // sort groups
        ksort($groups, SORT_LOCALE_STRING);

        return array_values($groups);
    }


    /**
     * List the current set of contact records
     *
     * @param  array  List of cols to show
     * @param  int    Only return this number of records, use negative values for tail
     * @return array  Indexed list of contact records, each a hash array
     */
    public function list_records($cols=null, $subset=0)
    {
        $this->result = $this->count();

        // list member of the selected group
        if ($this->gid) {
            $seen = array();
            $this->result->count = 0;
            foreach ((array)$this->distlists[$this->gid]['member'] as $member) {
                // skip member that don't match the search filter
                if (is_array($this->filter['ids']) && array_search($member['ID'], $this->filter['ids']) === false)
                    continue;
                if ($this->contacts[$member['ID']] && !$seen[$member['ID']]++)
                    $this->result->count++;
            }
            $ids = array_keys($seen);
        }
        else
            $ids = is_array($this->filter['ids']) ? $this->filter['ids'] : array_keys($this->contacts);

        // sort data arrays according to desired list sorting
        if ($count = count($ids)) {
$aa = rcube_timer();
            uasort($this->contacts, array($this, '_sort_contacts_comp'));
rcube_print_time($aa);
            // get sorted IDs
            if ($count != count($this->contacts))
                $ids = array_intersect(array_keys($this->contacts), $ids);
            else
                $ids = array_keys($this->contacts);
        }

        // fill contact data into the current result set
        $start_row = $subset < 0 ? $this->result->first + $this->page_size + $subset : $this->result->first;
        $last_row = min($subset != 0 ? $start_row + abs($subset) : $this->result->first + $this->page_size, $count);

        for ($i = $start_row; $i < $last_row; $i++) {
            if ($id = $ids[$i])
                $this->result->add($this->contacts[$id]);
        }

        return $this->result;
    }


    /**
     * Search records
     *
     * @param mixed   $fields   The field name of array of field names to search in
     * @param mixed   $value    Search value (or array of values when $fields is array)
     * @param boolean $strict   True for strict (=), False for partial (LIKE) matching
     * @param boolean $select   True if results are requested, False if count only
     * @param boolean $nocount  True to skip the count query (select only)
     * @param array   $required List of fields that cannot be empty
     *
     * @return object rcube_result_set List of contact records and 'count' value
     */
    public function search($fields, $value, $strict=false, $select=true, $nocount=false, $required=array())
    {
        $this->_fetch_contacts();

        // search by ID
        if ($fields == $this->primary_key) {
            $ids    = !is_array($value) ? explode(',', $value) : $value;
            $result = new rcube_result_set();

            foreach ($ids as $id) {
                if ($rec = $this->get_record($id, true)) {
                    $result->add($rec);
                    $result->count++;
                }
            }
            return $result;
        }
        else if ($fields == '*') {
          $fields = array_keys($this->coltypes);
        }

        if (!is_array($fields))
            $fields = array($fields);
        if (!is_array($required) && !empty($required))
            $required = array($required);

        // advanced search
        if (is_array($value)) {
            $advanced = true;
            $value = array_map('mb_strtolower', $value);
        }
        else
            $value = mb_strtolower($value);

        $scount = count($fields);
        // build key name regexp
        $regexp = '/^(' . implode($fields, '|') . ')(?:.*)$/';

        // save searching conditions
        $this->filter = array('fields' => $fields, 'value' => $value, 'strict' => $strict, 'ids' => array());

        // search be iterating over all records in memory
        foreach ($this->contacts as $id => $contact) {
            // check if current contact has required values, otherwise skip it
            if ($required) {
                foreach ($required as $f)
                    if (empty($contact[$f]))
                        continue 2;
            }

            $found = array();
            foreach (preg_grep($regexp, array_keys($contact)) as $col) {
                if ($advanced) {
                    $pos     = strpos($col, ':');
                    $colname = $pos ? substr($col, 0, $pos) : $col;
                    $search  = $value[array_search($colname, $fields)];
                }
                else {
                    $search = $value;
                }

                $s_len = strlen($search);

                foreach ((array)$contact[$col] as $val) {
                    // composite field, e.g. address
                    if (is_array($val)) {
                        $val = implode($val);
                    }
                    $val = mb_strtolower($val);

                    if (($strict && $val == $search)
                        || (!$strict && $s_len && strpos($val, $search) !== false)
                    ) {
                        if (!$advanced) {
                            $this->filter['ids'][] = $id;
                            break 2;
                        }
                        else {
                            $found[$colname] = true;
                        }
                    }
                }
            }

            if (count($found) >= $scount) // && $advanced
                $this->filter['ids'][] = $id;
        }

        // list records (now limited by $this->filter)
        return $this->list_records();
    }


    /**
     * Refresh saved search results after data has changed
     */
    public function refresh_search()
    {
        if ($this->filter)
            $this->search($this->filter['fields'], $this->filter['value'], $this->filter['strict']);

        return $this->get_search_set();
    }


    /**
     * Count number of available contacts in database
     *
     * @return rcube_result_set Result set with values for 'count' and 'first'
     */
    public function count()
    {
        $this->_fetch_contacts();
        $this->_fetch_groups();
        $count = $this->gid ? count($this->distlists[$this->gid]['member']) : (is_array($this->filter['ids']) ? count($this->filter['ids']) : count($this->contacts));
        return new rcube_result_set($count, ($this->list_page-1) * $this->page_size);
    }


    /**
     * Return the last result set
     *
     * @return rcube_result_set Current result set or NULL if nothing selected yet
     */
    public function get_result()
    {
        return $this->result;
    }


    /**
     * Get a specific contact record
     *
     * @param mixed record identifier(s)
     * @param boolean True to return record as associative array, otherwise a result set is returned
     * @return mixed Result object with all record fields or False if not found
     */
    public function get_record($id, $assoc=false)
    {
        $this->_fetch_contacts();
        if ($this->contacts[$id]) {
            $this->result = new rcube_result_set(1);
            $this->result->add($this->contacts[$id]);
            return $assoc ? $this->contacts[$id] : $this->result;
        }

        return false;
    }


    /**
     * Get group assignments of a specific contact record
     *
     * @param mixed Record identifier
     * @return array List of assigned groups as ID=>Name pairs
     */
    public function get_record_groups($id)
    {
        $out = array();
        $this->_fetch_groups();

        foreach ((array)$this->groupmembers[$id] as $gid) {
            if ($group = $this->distlists[$gid])
                $out[$gid] = $group['last-name'];
        }

        return $out;
    }


    /**
     * Create a new contact record
     *
     * @param array Assoziative array with save data
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     * @param boolean True to check for duplicates first
     * @return mixed The created record ID on success, False on error
     */
    public function insert($save_data, $check=false)
    {
        if (!is_array($save_data))
            return false;

        $insert_id = $existing = false;

        // check for existing records by e-mail comparison
        if ($check) {
            foreach ($this->get_col_values('email', $save_data, true) as $email) {
                if (($res = $this->search('email', $email, true, false)) && $res->count) {
                    $existing = true;
                    break;
                }
            }
        }

        if (!$existing) {
            $this->_connect();

            // generate new Kolab contact item
            $object = $this->_from_rcube_contact($save_data);
            $object['uid'] = $this->contactstorage->generateUID();

            $saved = $this->contactstorage->save($object);

            if (PEAR::isError($saved)) {
                raise_error(array(
                  'code' => 600, 'type' => 'php',
                  'file' => __FILE__, 'line' => __LINE__,
                  'message' => "Error saving contact object to Kolab server:" . $saved->getMessage()),
                true, false);
            }
            else {
                $contact = $this->_to_rcube_contact($object);
                $id = $contact['ID'];
                $this->contacts[$id] = $contact;
                $this->id2uid[$id] = $object['uid'];
                $insert_id = $id;
            }
        }

        return $insert_id;
    }


    /**
     * Update a specific contact record
     *
     * @param mixed Record identifier
     * @param array Assoziative array with save data
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     * @return boolean True on success, False on error
     */
    public function update($id, $save_data)
    {
        $updated = false;
        $this->_fetch_contacts();
        if ($this->contacts[$id] && ($uid = $this->id2uid[$id])) {
            $old = $this->contactstorage->getObject($uid);
            $object = array_merge($old, $this->_from_rcube_contact($save_data));

            $saved = $this->contactstorage->save($object, $uid);
            if (PEAR::isError($saved)) {
                raise_error(array(
                  'code' => 600, 'type' => 'php',
                  'file' => __FILE__, 'line' => __LINE__,
                  'message' => "Error saving contact object to Kolab server:" . $saved->getMessage()),
                true, false);
            }
            else {
                $this->contacts[$id] = $this->_to_rcube_contact($object);
                $updated = true;
            }
        }

        return $updated;
    }


    /**
     * Mark one or more contact records as deleted
     *
     * @param array   Record identifiers
     * @param boolean Remove record(s) irreversible (mark as deleted otherwise)
     *
     * @return int Number of records deleted
     */
    public function delete($ids, $force=true)
    {
        $this->_fetch_contacts();
        $this->_fetch_groups();

        if (!is_array($ids))
            $ids = explode(',', $ids);

        $count = 0;
        $imap_uids = array();

        foreach ($ids as $id) {
            if ($uid = $this->id2uid[$id]) {
                $imap_uid = $this->contactstorage->_getStorageId($uid);
                $deleted = $this->contactstorage->delete($uid, $force);

                if (PEAR::isError($deleted)) {
                    raise_error(array(
                      'code' => 600, 'type' => 'php',
                      'file' => __FILE__, 'line' => __LINE__,
                      'message' => "Error deleting a contact object from the Kolab server:" . $deleted->getMessage()),
                    true, false);
                }
                else {
                    // remove from distribution lists
                    foreach ((array)$this->groupmembers[$id] as $gid)
                        $this->remove_from_group($gid, $id);

                    $imap_uids[$id] = $imap_uid;
                    // clear internal cache
                    unset($this->contacts[$id], $this->id2uid[$id], $this->groupmembers[$id]);
                    $count++;
                }
            }
        }

        // store IMAP uids for undelete()
        if (!$force)
            $_SESSION['kolab_delete_uids'] = $imap_uids;

        return $count;
    }


    /**
     * Undelete one or more contact records.
     * Only possible just after delete (see 2nd argument of delete() method).
     *
     * @param array  Record identifiers
     *
     * @return int Number of records restored
     */
    public function undelete($ids)
    {
        if (!is_array($ids))
            $ids = explode(',', $ids);

        $count     = 0;
        $uids      = array();
        $imap_uids = $_SESSION['kolab_delete_uids'];

        // convert contact IDs into IMAP UIDs
        foreach ($ids as $id)
            if ($uid = $imap_uids[$id])
                $uids[] = $uid;

        if (!empty($uids)) {
            $session = &Horde_Kolab_Session::singleton();
            $imap = &$session->getImap();

            if (is_a($imap, 'PEAR_Error')) {
                $error = $imap;
            }
            else {
                $result = $imap->select($this->imap_folder);
                if (is_a($result, 'PEAR_Error')) {
                    $error = $result;
                }
                else {
                    $result = $imap->undeleteMessages(implode(',', $uids));
                    if (is_a($result, 'PEAR_Error')) {
                        $error = $result;
                    }
                    else {
                        $this->_connect();
                        $this->contactstorage->synchronize();
                    }
                }
            }

            if ($error) {
                raise_error(array(
                  'code' => 600, 'type' => 'php',
                  'file' => __FILE__, 'line' => __LINE__,
                  'message' => "Error undeleting a contact object(s) from the Kolab server:" . $error->getMessage()),
                true, false);
            }

            $rcmail = rcmail::get_instance();
            $rcmail->session->remove('kolab_delete_uids');
        }

        return count($uids);
    }


    /**
     * Remove all records from the database
     */
    public function delete_all()
    {
        $this->_connect();

        if (!PEAR::isError($this->contactstorage->deleteAll())) {
            $this->contacts = array();
            $this->id2uid = array();
            $this->result = null;
        }
    }


    /**
     * Close connection to source
     * Called on script shutdown
     */
    public function close()
    {
        rcube_kolab::shutdown();
    }


    /**
     * Create a contact group with the given name
     *
     * @param string The group name
     * @return mixed False on error, array with record props in success
     */
    function create_group($name)
    {
        $this->_fetch_groups();
        $result = false;

        $list = array(
            'uid' => $this->liststorage->generateUID(),
            'last-name' => $name,
            'member' => array(),
        );
        $saved = $this->liststorage->save($list);

        if (PEAR::isError($saved)) {
            raise_error(array(
              'code' => 600, 'type' => 'php',
              'file' => __FILE__, 'line' => __LINE__,
              'message' => "Error saving distribution-list object to Kolab server:" . $saved->getMessage()),
            true, false);
            return false;
        }
        else {
            $id = md5($list['uid']);
            $this->distlists[$record['ID']] = $list;
            $result = array('id' => $id, 'name' => $name);
        }

        return $result;
    }

    /**
     * Delete the given group and all linked group members
     *
     * @param string Group identifier
     * @return boolean True on success, false if no data was changed
     */
    function delete_group($gid)
    {
        $this->_fetch_groups();
        $result = false;

        if ($list = $this->distlists[$gid])
            $deleted = $this->liststorage->delete($list['uid']);

        if (PEAR::isError($deleted)) {
            raise_error(array(
              'code' => 600, 'type' => 'php',
              'file' => __FILE__, 'line' => __LINE__,
              'message' => "Error deleting distribution-list object from the Kolab server:" . $deleted->getMessage()),
            true, false);
        }
        else
            $result = true;

        return $result;
    }

    /**
     * Rename a specific contact group
     *
     * @param string Group identifier
     * @param string New name to set for this group
     * @return boolean New name on success, false if no data was changed
     */
    function rename_group($gid, $newname)
    {
        $this->_fetch_groups();
        $list = $this->distlists[$gid];

        if ($newname != $list['last-name']) {
            $list['last-name'] = $newname;
            $saved = $this->liststorage->save($list, $list['uid']);
        }

        if (PEAR::isError($saved)) {
            raise_error(array(
              'code' => 600, 'type' => 'php',
              'file' => __FILE__, 'line' => __LINE__,
              'message' => "Error saving distribution-list object to Kolab server:" . $saved->getMessage()),
            true, false);
            return false;
        }

        return $newname;
    }

    /**
     * Add the given contact records the a certain group
     *
     * @param string  Group identifier
     * @param array   List of contact identifiers to be added
     * @return int    Number of contacts added
     */
    function add_to_group($gid, $ids)
    {
        if (!is_array($ids))
            $ids = explode(',', $ids);

        $added = 0;
        $exists = array();

        $this->_fetch_groups();
        $this->_fetch_contacts();
        $list = $this->distlists[$gid];

        foreach ((array)$list['member'] as $i => $member)
            $exists[] = $member['ID'];

        // substract existing assignments from list
        $ids = array_diff($ids, $exists);

        foreach ($ids as $contact_id) {
            if ($uid = $this->id2uid[$contact_id]) {
                $contact = $this->contacts[$contact_id];
                foreach ($this->get_col_values('email', $contact, true) as $email) {
                    $list['member'][] = array(
                        'uid' => $uid,
                        'display-name' => $contact['name'],
                        'smtp-address' => $email,
                    );
                }
                $this->groupmembers[$contact_id][] = $gid;
                $added++;
            }
        }

        if ($added)
            $saved = $this->liststorage->save($list, $list['uid']);

        if (PEAR::isError($saved)) {
            raise_error(array(
              'code' => 600, 'type' => 'php',
              'file' => __FILE__, 'line' => __LINE__,
              'message' => "Error saving distribution-list to Kolab server:" . $saved->getMessage()),
            true, false);
            $added = false;
        }
        else {
            $this->distlists[$gid] = $list;
        }

        return $added;
    }

    /**
     * Remove the given contact records from a certain group
     *
     * @param string  Group identifier
     * @param array   List of contact identifiers to be removed
     * @return int    Number of deleted group members
     */
    function remove_from_group($gid, $ids)
    {
        if (!is_array($ids))
            $ids = explode(',', $ids);

        $this->_fetch_groups();
        if (!($list = $this->distlists[$gid]))
            return false;

        $new_member = array();
        foreach ((array)$list['member'] as $member) {
            if (!in_array($member['ID'], $ids))
                $new_member[] = $member;
        }

        // write distribution list back to server
        $list['member'] = $new_member;
        $saved = $this->liststorage->save($list, $list['uid']);

        if (PEAR::isError($saved)) {
            raise_error(array(
              'code' => 600, 'type' => 'php',
              'file' => __FILE__, 'line' => __LINE__,
              'message' => "Error saving distribution-list object to Kolab server:" . $saved->getMessage()),
            true, false);
        }
        else {
            // remove group assigments in local cache
            foreach ($ids as $id) {
                $j = array_search($gid, $this->groupmembers[$id]);
                unset($this->groupmembers[$id][$j]);
            }
            $this->distlists[$gid] = $list;
            return true;
        }

        return false;
    }

    /**
     * Check the given data before saving.
     * If input not valid, the message to display can be fetched using get_error()
     *
     * @param array Associative array with contact data to save
     *
     * @return boolean True if input is valid, False if not.
     */
    public function validate($save_data)
    {
        // validate e-mail addresses
        $valid = parent::validate($save_data);

        // require at least one e-mail address (syntax check is already done)
        if ($valid) {
            if (!strlen($save_data['name'])
                && !array_filter($this->get_col_values('email', $save_data, true))
            ) {
                $this->set_error('warning', 'kolab_addressbook.noemailnamewarning');
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Establishes a connection to the Kolab_Data object for accessing contact data
     */
    private function _connect()
    {
        if (!isset($this->contactstorage)) {
            $this->contactstorage = $this->storagefolder->getData(null);
        }
    }

    /**
     * Establishes a connection to the Kolab_Data object for accessing groups data
     */
    private function _connect_groups()
    {
        if (!isset($this->liststorage)) {
            $this->liststorage = $this->storagefolder->getData('distributionlist');
        }
    }

    /**
     * Simply fetch all records and store them in private member vars
     */
    private function _fetch_contacts()
    {
        if (!isset($this->contacts)) {
            $this->_connect();

            // read contacts
            $this->contacts = $this->id2uid = array();
            foreach ((array)$this->contactstorage->getObjects() as $record) {
                // Because of a bug, sometimes group records are returned
                if ($record['__type'] == 'Group')
                    continue;

                $contact = $this->_to_rcube_contact($record);
                $id = $contact['ID'];
                $this->contacts[$id] = $contact;
                $this->id2uid[$id] = $record['uid'];
            }
        }
    }

    /**
     * Callback function for sorting contacts
     */
    private function _sort_contacts_comp($a, $b)
    {
        $a_name = $a['name'];
        $b_name = $b['name'];

        if (!$a_name) {
            $a_name = join(' ', array_filter(array($a['prefix'], $a['firstname'],
                $a['middlename'], $a['surname'], $a['suffix'])));
            if (!$a_name) {
                $a_name = is_array($a['email']) ? $a['email'][0] : $a['email'];
            }
        }
        if (!$b_name) {
            $b_name = join(' ', array_filter(array($b['prefix'], $b['firstname'],
                $b['middlename'], $b['surname'], $b['suffix'])));
            if (!$b_name) {
                $b_name = is_array($b['email']) ? $b['email'][0] : $b['email'];
            }
        }

        // return strcasecmp($a_name, $b_name);
        // make sorting unicode-safe and locale-dependent
        if ($a_name == $b_name)
            return 0;

        $arr = array($a_name, $b_name);
        sort($arr, SORT_LOCALE_STRING);
        return $a_name == $arr[0] ? -1 : 1;
    }

    /**
     * Read distribution-lists AKA groups from server
     */
    private function _fetch_groups()
    {
        if (!isset($this->distlists)) {
            $this->_connect_groups();

            $this->distlists = $this->groupmembers = array();
            foreach ((array)$this->liststorage->getObjects() as $record) {
                // FIXME: folders without any distribution-list objects return contacts instead ?!
                if ($record['__type'] != 'Group')
                    continue;
                $record['ID'] = md5($record['uid']);
                foreach ((array)$record['member'] as $i => $member) {
                    $mid = md5($member['uid']);
                    $record['member'][$i]['ID'] = $mid;
                    $this->groupmembers[$mid][] = $record['ID'];
                }
                $this->distlists[$record['ID']] = $record;
            }
        }
    }

    /**
     * Map fields from internal Kolab_Format to Roundcube contact format
     */
    private function _to_rcube_contact($record)
    {
        $out = array(
          'ID' => md5($record['uid']),
          'email' => array(),
          'phone' => array(),
        );

        foreach ($this->fieldmap as $kolab => $rcube) {
          if (strlen($record[$kolab]))
            $out[$rcube] = $record[$kolab];
        }

        if (isset($record['gender']))
            $out['gender'] = $this->gender_map[$record['gender']];

        foreach ((array)$record['email'] as $i => $email)
            $out['email'][] = $email['smtp-address'];

        if (!$record['email'] && $record['emails'])
            $out['email'] = preg_split('/,\s*/', $record['emails']);

        foreach ((array)$record['phone'] as $i => $phone)
            $out['phone:'.$phone['type']][] = $phone['number'];

        if (is_array($record['address'])) {
            foreach ($record['address'] as $i => $adr) {
                $key = 'address:' . $adr['type'];
                $out[$key][] = array(
                    'street' => $adr['street'],
                    'locality' => $adr['locality'],
                    'zipcode' => $adr['postal-code'],
                    'region' => $adr['region'],
                    'country' => $adr['country'],
                );
            }
        }

        // photo is stored as separate attachment
        if ($record['picture'] && ($att = $record['_attachments'][$record['picture']])) {
            $out['photo'] = $att['content'] ? $att['content'] : $this->contactstorage->getAttachment($att['key']);
        }

        // remove empty fields
        return array_filter($out);
    }

    /**
     * Map fields from Roundcube format to internal Kolab_Format
     */
    private function _from_rcube_contact($contact)
    {
        $object = array();

        foreach (array_flip($this->fieldmap) as $rcube => $kolab) {
            if (isset($contact[$rcube]))
                $object[$kolab] = is_array($contact[$rcube]) ? $contact[$rcube][0] : $contact[$rcube];
            else if ($values = $this->get_col_values($rcube, $contact, true))
                $object[$kolab] = is_array($values) ? $values[0] : $values;
        }

        // format dates
        if ($object['birthday'] && ($date = @strtotime($object['birthday'])))
            $object['birthday'] = date('Y-m-d', $date);
        if ($object['anniversary'] && ($date = @strtotime($object['anniversary'])))
            $object['anniversary'] = date('Y-m-d', $date);

        $gendermap = array_flip($this->gender_map);
        if (isset($contact['gender']))
            $object['gender'] = $gendermap[$contact['gender']];

        $emails = $this->get_col_values('email', $contact, true);
        $object['emails'] = join(', ', array_filter($emails));
        // overwrite 'email' field
        $object['email'] = '';

        foreach ($this->get_col_values('phone', $contact) as $type => $values) {
            if ($this->phonetypemap[$type])
                $type = $this->phonetypemap[$type];
            foreach ((array)$values as $phone) {
                if (!empty($phone)) {
                    $object['phone-' . $type] = $phone;
                    $object['phone'][] = array('number' => $phone, 'type' => $type);
                }
            }
        }

        $object['address'] = array();

        foreach ($this->get_col_values('address', $contact) as $type => $values) {
            if ($this->addresstypemap[$type])
                $type = $this->addresstypemap[$type];

            $updated = false;
            $basekey = 'addr-' . $type . '-';
            foreach ((array)$values as $adr) {
                // switch type if slot is already taken
                if (isset($object[$basekey . 'type'])) {
                    $type = $type == 'home' ? 'business' : 'home';
                    $basekey = 'addr-' . $type . '-';
                }

                if (!isset($object[$basekey . 'type'])) {
                    $object[$basekey . 'type'] = $type;
                    $object[$basekey . 'street'] = $adr['street'];
                    $object[$basekey . 'locality'] = $adr['locality'];
                    $object[$basekey . 'postal-code'] = $adr['zipcode'];
                    $object[$basekey . 'region'] = $adr['region'];
                    $object[$basekey . 'country'] = $adr['country'];

                    // Update existing address entry of this type
                    foreach($object['address'] as $index => $address) {
                        if ($address['type'] == $type) {
                            $object['address'][$index] = $new_address;
                            $updated = true;
                        }
                    }
                }
                if (!$updated) {
                    $object['address'][] = array(
                        'type' => $type,
                        'street' => $adr['street'],
                        'locality' => $adr['locality'],
                        'postal-code' => $adr['zipcode'],
                        'region' => $adr['region'],
                        'country' => $adr['country'],
                    );
                }
            }
        }

        // save new photo as attachment
        if ($contact['photo']) {
          $attkey = 'photo.attachment';
          $object['_attachments'][$attkey] = array(
            'type' => rc_image_content_type($contact['photo']),
            'content' => preg_match('![^a-z0-9/=+-]!i', $contact['photo']) ? $contact['photo'] : base64_decode($contact['photo']),
          );
          $object['picture'] = $attkey;
        }

        return $object;
    }

}
