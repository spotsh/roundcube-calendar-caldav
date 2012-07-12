<?php

/**
 * Kolab Groupware driver for the Tasklist plugin
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

class tasklist_kolab_driver extends tasklist_driver
{
    // features supported by the backend
    public $alarms = false;
    public $attachments = false;
    public $undelete = false; // task undelete action

    private $rc;
    private $plugin;
    private $lists;
    private $folders = array();
    private $tasks = array();


    /**
     * Default constructor
     */
    public function __construct($plugin)
    {
        $this->rc = $plugin->rc;
        $this->plugin = $plugin;

        $this->_read_lists();
    }

    /**
     * Read available calendars for the current user and store them internally
     */
    private function _read_lists()
    {
        // already read sources
        if (isset($this->lists))
            return $this->lists;

        // get all folders that have type "task"
        $this->folders = kolab_storage::get_folders('task');
        $this->lists = array();

        // convert to UTF8 and sort
        $names = array();
        foreach ($this->folders as $i => $folder) {
            $names[$folder->name] = rcube_charset::convert($folder->name, 'UTF7-IMAP');
            $this->folders[$folder->name] = $folder;
        }

        asort($names, SORT_LOCALE_STRING);

        $delim = $this->rc->get_storage()->get_hierarchy_delimiter();
        $listnames = array();

        foreach ($names as $utf7name => $name) {
            $folder = $this->folders[$utf7name];

            $path_imap = explode($delim, $name);
            $editname = array_pop($path_imap);  // pop off raw name part
            $path_imap = join($delim, $path_imap);

            $name = kolab_storage::folder_displayname(kolab_storage::object_name($utf7name), $listnames);

            $tasklist = array(
                'id' => kolab_storage::folder_id($utf7name),
                'name' => $name,
                'editname' => $editname,
                'color' => 'CC0000',
                'showalarms' => false,
                'editable' => true,
                'active' => $folder->is_subscribed(kolab_storage::SERVERSIDE_SUBSCRIPTION),
                'parentfolder' => $path_imap,
            );
            $this->lists[$tasklist['id']] = $tasklist;
            $this->folders[$tasklist['id']] = $folder;
        }
    }

    /**
     * Get a list of available task lists from this source
     */
    public function get_lists()
    {
        // attempt to create a default list for this user
        if (empty($this->lists)) {
          if ($this->create_list(array('name' => 'Default', 'color' => '000000')))
            $this->_read_lists();
        }

        return $this->lists;
    }

    /**
     * Create a new list assigned to the current user
     *
     * @param array Hash array with list properties
     *        name: List name
     *       color: The color of the list
     *  showalarms: True if alarms are enabled
     * @return mixed ID of the new list on success, False on error
     */
    public function create_list($prop)
    {
        $prop['type'] = 'task';
        $prop['subscribed'] = kolab_storage::SERVERSIDE_SUBSCRIPTION; // subscribe to folder by default
        $folder = kolab_storage::folder_update($prop);

        if ($folder === false) {
            $this->last_error = kolab_storage::$last_error;
            return false;
        }

        // create ID
        return kolab_storage::folder_id($folder);
    }

    /**
     * Update properties of an existing tasklist
     *
     * @param array Hash array with list properties
     *          id: List Identifier
     *        name: List name
     *       color: The color of the list
     *  showalarms: True if alarms are enabled (if supported)
     * @return boolean True on success, Fales on failure
     */
    public function edit_list($prop)
    {
        if ($prop['id'] && ($folder = $this->folders[$prop['id']])) {
            $prop['oldname'] = $folder->name;
            $prop['type'] = 'task';
            $newfolder = kolab_storage::folder_update($prop);

            if ($newfolder === false) {
                $this->last_error = kolab_storage::$last_error;
                return false;
            }

            // create ID
            return kolab_storage::folder_id($newfolder);
        }

        return false;
    }

    /**
     * Set active/subscribed state of a list
     *
     * @param array Hash array with list properties
     *          id: List Identifier
     *      active: True if list is active, false if not
     * @return boolean True on success, Fales on failure
     */
    public function subscribe_list($prop)
    {
        if ($prop['id'] && ($folder = $this->folders[$prop['id']])) {
            return $folder->subscribe($prop['active'], kolab_storage::SERVERSIDE_SUBSCRIPTION);
        }
        return false;
    }

    /**
     * Delete the given list with all its contents
     *
     * @param array Hash array with list properties
     *      id: list Identifier
     * @return boolean True on success, Fales on failure
     */
    public function remove_list($prop)
    {
        if ($prop['id'] && ($folder = $this->folders[$prop['id']])) {
          if (kolab_storage::folder_delete($folder->name))
              return true;
          else
              $this->last_error = kolab_storage::$last_error;
        }

        return false;
    }

    /**
     * Get number of tasks matching the given filter
     *
     * @param array List of lists to count tasks of
     * @return array Hash array with counts grouped by status (all|flagged|completed|today|tomorrow|nodate)
     */
    public function count_tasks($lists = null)
    {
        if (empty($lists))
            $lists = array_keys($this->lists);
        else if (is_string($lists))
            $lists = explode(',', $lists);

        $today_date = new DateTime('now', $this->plugin->timezone);
        $today = $today_date->format('Y-m-d');
        $tomorrow_date = new DateTime('now + 1 day', $this->plugin->timezone);
        $tomorrow = $tomorrow_date->format('Y-m-d');

        $counts = array('all' => 0, 'flagged' => 0, 'today' => 0, 'tomorrow' => 0, 'overdue' => 0, 'nodate' => 0);
        foreach ($lists as $list_id) {
            $folder = $this->folders[$list_id];
            foreach ((array)$folder->select(array(array('tags','!~','complete'))) as $record) {
                $rec = $this->_to_rcube_task($record);

                if ($rec['complete'])  // don't count complete tasks
                    continue;

                $counts['all']++;
                if ($rec['flagged'])
                    $counts['flagged']++;
                if (empty($rec['date']))
                    $counts['nodate']++;
                else if ($rec['date'] == $today)
                    $counts['today']++;
                else if ($rec['date'] == $tomorrow)
                    $counts['tomorrow']++;
                else if ($rec['date'] < $today)
                    $counts['overdue']++;
            }
        }

        return $counts;
    }

    /**
     * Get all taks records matching the given filter
     *
     * @param array Hash array with filter criterias:
     *  - mask:  Bitmask representing the filter selection (check against tasklist::FILTER_MASK_* constants)
     *  - from:  Date range start as string (Y-m-d)
     *  - to:    Date range end as string (Y-m-d)
     *  - search: Search query string
     * @param array List of lists to get tasks from
     * @return array List of tasks records matchin the criteria
     */
    public function list_tasks($filter, $lists = null)
    {
        if (empty($lists))
            $lists = array_keys($this->lists);
        else if (is_string($lists))
            $lists = explode(',', $lists);

        $results = array();

        // query Kolab storage
        $query = array();
        if ($filter['mask'] & tasklist::FILTER_MASK_COMPLETE)
            $query[] = array('tags','~','complete');
        else
            $query[] = array('tags','!~','complete');

        // full text search (only works with cache enabled)
        if ($filter['search']) {
            $search = mb_strtolower($filter['search']);
            foreach (rcube_utils::normalize_string($search, true) as $word) {
                $query[] = array('words', '~', $word);
            }
        }

        foreach ($lists as $list_id) {
            $folder = $this->folders[$list_id];
            foreach ((array)$folder->select($query) as $record) {
                $task = $this->_to_rcube_task($record);
                $task['list'] = $list_id;

                // TODO: post-filter tasks returned from storage

                $results[] = $task;
            }
        }

        return $results;
    }

    /**
     * Return data of a specific task
     *
     * @param mixed  Hash array with task properties or task UID
     * @return array Hash array with task properties or false if not found
     */
    public function get_task($prop)
    {
        $id = is_array($prop) ? $prop['uid'] : $prop;
        $list_id = is_array($prop) ? $prop['list'] : null;
        $folders = $list_id ? array($list_id => $this->folders[$list_id]) : $this->folders;

        // find task in the available folders
        foreach ($folders as $folder) {
            if (!$this->tasks[$id] && ($object = $folder->get_object($id))) {
                $this->tasks[$id] = $this->_to_rcube_task($object);
                break;
            }
        }

        return $this->tasks[$id];
    }

    /**
     * Convert from Kolab_Format to internal representation
     */
    private function _to_rcube_task($record)
    {
        $task = array(
            'id' => $record['uid'],
            'uid' => $record['uid'],
            'title' => $record['title'],
#            'location' => $record['location'],
            'description' => $record['description'],
            'tags' => (array)$record['categories'],
            'flagged' => $record['priority'] == 1,
            'complete' => $record['status'] == 'COMPLETED' ? 1 : floatval($record['complete'] / 100),
            'parent_id' => $record['parent_id'],
        );

        // convert from DateTime to internal date format
        if (is_a($record['due'], 'DateTime')) {
            $task['date'] = $record['due']->format('Y-m-d');
            $task['time'] = $record['due']->format('h:i');
        }
        if (is_a($record['dtstamp'], 'DateTime')) {
            $task['changed'] = $record['dtstamp']->format('U');
        }

        return $task;
    }

    /**
    * Convert the given task record into a data structure that can be passed to kolab_storage backend for saving
    * (opposite of self::_to_rcube_event())
     */
    private function _from_rcube_task($task, $old = array())
    {
        $object = $task;
        $object['categories'] = (array)$task['tags'];

        if (!empty($task['date'])) {
            $object['due'] = new DateTime($task['date'].' '.$task['time'], $this->plugin->timezone);
            if (empty($task['time']))
                $object['due']->_dateonly = true;
            unset($object['date']);
        }

        $object['complete'] = $task['complete'] * 100;
        if ($task['complete'] == 1.0)
            $object['status'] = 'COMPLETED';

        if ($task['flagged'])
            $object['priority'] = 1;
        else
            $object['priority'] = $old['priority'] > 1 ? $old['priority'] : 0;

        // copy meta data (starting with _) from old object
        foreach ((array)$old as $key => $val) {
          if (!isset($object[$key]) && $key[0] == '_')
            $object[$key] = $val;
        }

        unset($object['tempid'], $object['raw']);
        return $object;
    }

    /**
     * Add a single task to the database
     *
     * @param array Hash array with task properties (see header of tasklist_driver.php)
     * @return mixed New task ID on success, False on error
     */
    public function create_task($task)
    {
        return $this->edit_task($task);
    }

    /**
     * Update an task entry with the given data
     *
     * @param array Hash array with task properties (see header of tasklist_driver.php)
     * @return boolean True on success, False on error
     */
    public function edit_task($task)
    {
        $list_id = $task['list'];
        if (!$list_id || !($folder = $this->folders[$list_id]))
            return false;

        // moved from another folder
        if ($task['_fromlist'] && ($fromfolder = $this->folders[$task['_fromlist']])) {
            if (!$fromfolder->move($task['uid'], $folder->name))
                return false;

            unset($task['_fromlist']);
        }

        // load previous version of this task to merge
        if ($task['id']) {
            $old = $folder->get_object($task['uid']);
            if (!$old || PEAR::isError($old))
                return false;
        }

        // generate new task object from RC input
        $object = $this->_from_rcube_task($task, $old);
        $saved = $folder->save($object, 'task', $task['id']);

        if (!$saved) {
            raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error saving task object to Kolab server"),
                true, false);
            $saved = false;
        }
        else {
            $task['id'] = $task['uid'];
            $this->tasks[$task['uid']] = $task;
        }

        return $saved;
    }

    /**
     * Remove a single task from the database
     *
     * @param array   Hash array with task properties:
     *      id: Task identifier
     * @param boolean Remove record irreversible (mark as deleted otherwise, if supported by the backend)
     * @return boolean True on success, False on error
     */
    public function delete_task($task, $force = true)
    {
        $list_id = $task['list'];
        if (!$list_id || !($folder = $this->folders[$list_id]))
            return false;

        return $folder->delete($task['uid']);
    }

    /**
     * Restores a single deleted task (if supported)
     *
     * @param array Hash array with task properties:
     *      id: Task identifier
     * @return boolean True on success, False on error
     */
    public function undelete_task($prop)
    {
        // TODO: implement this
        return false;
    }


    /**
     * 
     */
    public function tasklist_edit_form($formfields)
    {
        $select = kolab_storage::folder_selector('task', array('name' => 'parent', 'id' => 'edit-parentfolder'), null);
        $formfields['parent'] = array(
            'id' => 'edit-parentfolder',
            'label' => $this->plugin->gettext('parentfolder'),
            'value' => $select->show(''),
        );
        
        return parent::tasklist_edit_form($formfields);
    }

}
