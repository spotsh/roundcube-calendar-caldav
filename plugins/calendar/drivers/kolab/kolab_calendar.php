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

class kolab_calendar
{
  public $id;
  public $ready = false;
  public $readonly = true;
  
  private $storage;
  private $events;
  private $id2uid;
  private $imap_folder = 'INBOX/Calendar';
  private $sensitivity_map = array('public', 'private', 'confidential');
  
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
    // use Horde classes to compute recurring instances
    require_once 'Horde/Date/Recurrence.php';
    
    $this->_fetch_events();
    
    $events = array();
    foreach ($this->events as $id => $event) {
      // list events in requested time window
      if ($event['start'] <= $end && $event['end'] >= $start) {
        $events[] = $event;
      }
      
      // resolve recurring events (maybe move to _fetch_events() for general use?)
      if ($event['recurrence']) {
        $recurrence = new Horde_Date_Recurrence($event['start']);
        $recurrence->fromRRule20(calendar::to_rrule($event['recurrence']));
        
        $duration = $event['end'] - $event['start'];
        $next = new Horde_Date($event['start']);
        while ($next = $recurrence->nextActiveRecurrence(array('year' => $next->year, 'month' => $next->month, 'mday' => $next->mday + 1, 'hour' => $next->hour, 'min' => $next->min, 'sec' => $next->sec))) {
          $rec_start = $next->timestamp();
          $rec_end = $rec_start + $duration;
          
          // add to output if in range
          if ($rec_start <= $end && $rec_end >= $start) {
            $rec_event = $event;
            $rec_event['recurrence_id'] = $event['id'];
            $rec_event['start'] = $rec_start;
            $rec_event['end'] = $rec_end;
            $events[] = $rec_event;
          }
          else if ($start_ts > $end)  // stop loop if out of range
            break;
        }
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
      
    // convert alarm time into internal format
    if ($rec['alarm']) {
      $alarm_value = $rec['alarm'];
      $alarm_unit = 'M';
      if ($rec['alarm'] % 1440 == 0) {
        $alarm_value /= 1440;
        $alarm_unit = 'D';
      }
      else if ($rec['alarm'] % 60 == 0) {
        $alarm_value /= 60;
        $alarm_unit = 'H';
      }
      $alarm_value *= -1;
    }
    
    // convert recurrence rules into internal pseudo-vcalendar format
    if ($recurrence = $rec['recurrence']) {
      $rrule = array(
        'FREQ' => strtoupper($recurrence['cycle']),
        'INTERVAL' => intval($recurrence['interval']),
      );
      
      if ($recurrence['range-type'] == 'number')
        $rrule['COUNT'] = intval($recurrence['range']);
      else if ($recurrence['range-type'] == 'date')
        $rrule['UNTIL'] = $recurrence['range'];
      
      if ($recurrence['day']) {
        $byday = array();
        $prefix = ($rrule['FREQ'] == 'MONTHLY' || $rrule['FREQ'] == 'YEARLY') ? intval($recurrence['daynumber'] ? $recurrence['daynumber'] : 1) : '';
        foreach ($recurrence['day'] as $day)
          $byday[] = $prefix . substr(strtoupper($day), 0, 2);
        $rrule['BYDAY'] = join(',', $byday);
      }
      if ($recurrence['daynumber']) {
        if ($recurrence['type'] == 'monthday')
          $rrule['BYMONTHDAY'] = $recurrence['daynumber'];
        else if ($recurrence['type'] == 'yearday')
          $rrule['BYYEARDAY'] = $recurrence['daynumber'];
      }
      if ($rec['month']) {
        $monthmap = array('january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12);
        $rrule['BYMONTH'] = strtolower($monthmap[$recurrence['month']]);
      }
      
      if ($recurrence['exclusion']) {
        foreach ((array)$recurrence['exclusion'] as $excl)
          $rrule['EXDATE'][] = strtotime($excl);
      }
    }
    
    $sensitivity_map = array_flip($this->sensitivity_map);
    
    return array(
      'id' => $rec['uid'],
      'uid' => $rec['uid'],
      'title' => $rec['summary'],
      'location' => $rec['location'],
      'description' => $rec['body'],
      'start' => $rec['start-date'],
      'end' => $rec['end-date'],
      'all_day' => $allday,
      'recurrence' => $rrule,
      'alarms' => $alarm_value . $alarm_unit,
      'categories' => $rec['categories'],
      'free_busy' => $rec['show-time-as'],
      'priority' => 1, // normal
      'sensitivity' => $sensitivity_map[$rec['sensitivity']],
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
