<?php
/*
 +-------------------------------------------------------------------------+
 | Kolab driver for the Calendar Plugin                                    |
 | Version 0.3 beta                                                        |
 |                                                                         |
 | This program is free software; you can redistribute it and/or modify    |
 | it under the terms of the GNU General Public License version 2          |
 | as published by the Free Software Foundation.                           |
 |                                                                         |
 | PURPOSE:                                                                |
 |   Kolab bindings for the calendar backend                               |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                          |
 +-------------------------------------------------------------------------+
*/

require_once(dirname(__FILE__) . '/kolab_calendar.php');

class kolab_driver extends calendar_driver
{
  // features this backend supports
  public $alarms = true;
  public $attendees = false;
  public $attachments = false;

  private $rc;
  private $cal;
  private $calendars;
  private $folders;

  /**
   * Default constructor
   */
  public function __construct($cal)
  {
    $this->cal = $cal;
    $this->rc = $cal->rc;
    $this->_read_calendars();
  }


  /**
   * Read available calendars from server
   */
  private function _read_calendars()
  {
    // already read sources
    if (isset($this->calendars))
        return $this->calendars;

    // get all folders that have "event" type
    $folders = rcube_kolab::get_folders('event');
    $this->folders = $this->calendars = array();

    if (PEAR::isError($folders)) {
        raise_error(array(
          'code' => 600, 'type' => 'php',
          'file' => __FILE__, 'line' => __LINE__,
          'message' => "Failed to list calendar folders from Kolab server:" . $folders->getMessage()),
        true, false);
    }
    else {
        foreach ($folders as $c_folder) {
            $calendar = new kolab_calendar($c_folder->name);
            $this->folders[$calendar->id] = $calendar;
            if ($calendar->ready) {
              $this->calendars[$calendar->id] = array(
                'id' => $calendar->id,
                'name' => $calendar->get_name(),
                'color' => $calendar->get_color(),
                'readonly' => $c_folder->_owner != $_SESSION['username'],
              );
            }
        }
    }

    return $this->calendars;
  }


  private function _get_storage($cid, $readonly = false)
  {
    if ($readonly)
      return $this->folders[$cid];
    else if (!$this->calendars[$cid]['readonly'])
      return $this->folders[$cid];
    return false;
  }


  /**
   * Get a list of available calendars from this source
   */
  public function list_calendars()
  {
    // attempt to create a default calendar for this user
    if (empty($this->calendars)) {
      if ($this->create_calendar(array('name' => 'Default', 'color' => 'cc0000')))
        $this->_read_calendars();
    }
    
    return $this->calendars;
  }


  /**
   * Create a new calendar assigned to the current user
   *
   * @param array Hash array with calendar properties
   *    name: Calendar name
   *   color: The color of the calendar
   * @return mixed ID of the calendar on success, False on error
   */
  public function create_calendar($prop)
  {
    
    return false;
  }

  /**
   * Add a single event to the database
   *
   * @see Driver:new_event()
   */
  public function new_event($event)
  {
    $cid = $event['calendar'] ? $event['calendar'] : reset(array_keys($this->calendars));
    if ($storage = $this->_get_storage($cid))
      return $storage->insert_event($event);
    
    return false;
  }

  /**
   * Update an event entry with the given data
   *
   * @see Driver:new_event()
   * @return boolean True on success, False on error
   */
  public function edit_event($event)
  {
    if ($storage = $this->_get_storage($event['calendar']))
      return $storage->update_event($event);
    
    return false;
  }

  /**
   * Move a single event
   *
   * @see Driver:move_event()
   * @return boolean True on success, False on error
   */
  public function move_event($event)
  {
    if (($storage = $this->_get_storage($event['calendar'])) && ($ev = $storage->get_event($event['id'])))
      return $storage->update_event($event + $ev);
    
    return false;
  }

  /**
   * Resize a single event
   *
   * @see Driver:resize_event()
   * @return boolean True on success, False on error
   */
  public function resize_event($event)
  {
    if (($storage = $this->_get_storage($event['calendar'])) && ($ev = $storage->get_event($event['id'])))
      return $storage->update_event($event + $ev);
    
    return false;
  }

  /**
   * Remove a single event from the database
   *
   * @param array Hash array with event properties:
   *      id: Event identifier 
   * @return boolean True on success, False on error
   */
  public function remove_event($event)
  {
    return false;
  }

  /**
   * Get events from source.
   *
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  mixed   List of calendar IDs to load events from (either as array or comma-separated string)
   * @return array A list of event records
   */
  public function load_events($start, $end, $calendars = null)
  {
    if ($calendars && is_string($calendars))
      $calendars = explode(',', $calendars);
    
    $events = array();
    foreach ($this->calendars as $cid => $calendar) {
      if ($calendars && !in_array($cid, $calendars))
        continue;
        
      $events = array_merge($this->folders[$cid]->list_events($start, $end));
    }
    
    return $events;
  }

  /**
   * Search events using the given query
   *
   * @see Driver::search_events()
   * @return array A list of event records
   */
  public function search_events($start, $end, $query, $calendars = null)
  {
    return array();
  }

  /**
   * Save an attachment related to the given event
   */
  public function add_attachment($attachment, $event_id)
  {
    
  }

  /**
   * Remove a specific attachment from the given event
   */
  public function remove_attachment($attachment, $event_id)
  {
    
  }

  /**
   * Create a new category
   */
  public function add_category($name, $color)
  {
    
  }

  /**
   * Remove the given category
   */
  public function remove_category($name)
  {
    
  }

  /**
   * Update/replace a category
   */
  public function replace_category($oldname, $name, $color)
  {
    
  }

  /**
   * Fetch free/busy information from a person within the given range
   */
  public function get_freebusy_list($email, $start, $end)
  {
    return array();
  }

}
