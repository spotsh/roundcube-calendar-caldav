<?php
/*
 +-------------------------------------------------------------------------+
 | Kolab calendar storage class                                            |
 |                                                                         |
 | This program is free software; you can redistribute it and/or modify    |
 | it under the terms of the GNU General Public License version 2          |
 | as published by the Free Software Foundation.                           |
 |                                                                         |
 | PURPOSE:                                                                |
 |   Storage object for a single calendar folder on Kolab                  |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                          |
 +-------------------------------------------------------------------------+
*/

class Kolab_calendar
{
  public $id;
  public $ready = false;
  public $readonly = true;
  
  private $storage;
  private $events;
  private $id2uid;
  private $imap_folder = 'INBOX/Calendar';
  
  /**
   * Default constructor
   */
  public function __construct($imap_folder = null)
  {
    if ($imap_folder)
      $this->imap_folder = $imap_folder;

    // ID is derrived from folder name
    $this->id = strtolower(asciiwords(strtr($this->imap_folder, '/.', '--')));

    // fetch objects from the given IMAP folder
    $this->storage = rcube_kolab::get_storage($this->imap_folder);

    $this->ready = !PEAR::isError($this->storage);
  }


  /**
   * Getter for a nice and human readable name for this calendar
   * See http://wiki.kolab.org/UI-Concepts/Folder-Listing for reference
   *
   * @return string Name of this calendar
   */
  public function get_name()
  {
    $dispname = preg_replace(array('!INBOX/Calendar/!', '!^INBOX/!', '!^shared/!', '!^user/([^/]+)/!'), array('','','','(\\1) '), $this->imap_folder);
    return strlen($dispname) ? $dispname : $this->imap_folder;
  }

  /**
   * Return color to display this calendar
   */
  public function get_color()
  {
    // TODO: read color from backend (not yet supported)
    return '0000cc';
  }
  
  
  /**
   * Getter for a single event object
   */
  public function get_event($id)
  {
    $this->_fetch_events();
    return $this->events[$id];
  }


  /**
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @return array A list of event records
   */
  public function list_events($start, $end)
  {
    $this->_fetch_events();
    
    $events = array();
    foreach ($this->events as $id => $event) {
      // TODO: also list recurring events
      if ($event['start'] <= $end && $event['end'] >= $start) {
        $events[] = $event;
      }
    }
    
    return $events;
  }


  /**
   * Create a new event record
   *
   * @see Driver:new_event()
   * @return mixed The created record ID on success, False on error
   */
  public function insert_event($event)
  {
    return false;
  }

  /**
   * Update a specific event record
   *
   * @see Driver:new_event()
   * @return boolean True on success, False on error
   */

  public function update_event($event)
  {
    
    return false;
  }


  /**
   * Simply fetch all records and store them in private member vars
   * We thereby rely on cahcing done by the Horde classes
   */
  private function _fetch_events()
  {
    if (!isset($this->events)) {
      $this->events = array();
      foreach ((array)$this->storage->getObjects() as $record) {
        $event = $this->_to_rcube_event($record);
        $this->events[$event['id']] = $event;
      }
    }
  }

  /**
   * Convert from Kolab_Format to internal representation
   */
  private function _to_rcube_event($rec)
  {
    $start_time = date('H:i:s', $rec['start-date']);
    $allday = $start_time == '00:00:00' && $start_time == date('H:i:s', $rec['end-date']);
    if ($allday)  // in Roundcube all-day events only go until 23:59:59 of the last day
      $rec['end-date']--;
    
    return array(
      'id' => $rec['uid'],
      'uid' => $rec['uid'],
      'title' => $rec['summary'],
      'location' => $rec['location'],
      'description' => $rec['body'],
      'start' => $rec['start-date'],
      'end' => $rec['end-date'],
      'all_day' => $allday,
      'categories' => $rec['categories'],
      'free_busy' => $rec['show-time-as'],
      'priority' => 1, // normal
      'calendar' => $this->id,
    );
  }

  /**
   * Convert the given event record into a data structure that can be passed to Kolab_Storage backend for saving
   * (opposite of self::_to_rcube_event())
   */
  private function _from_rcube_eventt($event)
  {
    $object = array();
    
    // set end-date to 00:00:00 of the following day
    if ($event['all_day'])
      $event['end']++;
    
    
    return $object;
  }

}
