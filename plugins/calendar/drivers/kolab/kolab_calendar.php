<?php
/*
 +-------------------------------------------------------------------------+
 | Kolab calendar storage class                                            |
 |                                                                         |
 | Copyright (C) 2011, Kolab Systems AG                                    |
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
 | Author: Aleksander Machniak <machniak@kolabsys.com>                     |
 +-------------------------------------------------------------------------+
*/

class kolab_calendar
{
  public $id;
  public $ready = false;
  public $readonly = true;
  public $attachments = true;
  public $alarms = false;

  private $cal;
  private $storage;
  private $events;
  private $id2uid;
  private $imap_folder = 'INBOX/Calendar';
  private $namespace;
  private $search_fields = array('title', 'description', 'location');
  private $sensitivity_map = array('public', 'private', 'confidential');
  private $priority_map = array('low', 'normal', 'high');
  private $month_map = array('', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december');
  private $weekday_map = array('MO'=>'monday', 'TU'=>'tuesday', 'WE'=>'wednesday', 'TH'=>'thursday', 'FR'=>'friday', 'SA'=>'saturday', 'SU'=>'sunday');


  /**
   * Default constructor
   */
  public function __construct($imap_folder, $calendar)
  {
    $this->cal = $calendar;

    if (strlen($imap_folder))
      $this->imap_folder = $imap_folder;

    // ID is derrived from folder name
    $this->id = rcube_kolab::folder_id($this->imap_folder);

    // fetch objects from the given IMAP folder
    $this->storage = rcube_kolab::get_storage($this->imap_folder);

    $this->ready = !PEAR::isError($this->storage);

    // Set readonly and alarms flags according to folder permissions
    if ($this->ready) {
      if ($this->get_owner() == $_SESSION['username']) {
        $this->readonly = false;
        $this->alarms = true;
      }
      else {
        $acl = $this->storage->_folder->getACL();
        $acl = $acl[$_SESSION['username']];
        if (strpos($acl, 'i') !== false)
          $this->readonly = false;
      }
    }
  }


  /**
   * Getter for a nice and human readable name for this calendar
   * See http://wiki.kolab.org/UI-Concepts/Folder-Listing for reference
   *
   * @return string Name of this calendar
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
    return $this->storage->_folder->getOwner();
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
   * Getter for the top-end calendar folder name (not the entire path)
   *
   * @return string Name of this calendar
   */
  public function get_foldername()
  {
    $parts = explode('/', $this->imap_folder);
    return rcube_charset_convert(end($parts), 'UTF7-IMAP');
  }

  /**
   * Return color to display this calendar
   */
  public function get_color()
  {
    // Store temporarily calendar color in user prefs (will be changed)
    $prefs = $this->cal->rc->config->get('kolab_calendars', array());

    if (!empty($prefs[$this->id]) && !empty($prefs[$this->id]['color']))
      return $prefs[$this->id]['color'];

    return 'cc0000';
  }


  /**
   * Getter for the attachment body
   */
  public function get_attachment_body($id)
  {
    return $this->storage->getAttachment($id);
  }


  /**
   * Getter for a single event object
   */
  public function get_event($id)
  {
    $this->_fetch_events();
    
    // event not found, maybe a recurring instance is requested
    if (!$this->events[$id]) {
      $master_id = preg_replace('/-\d+$/', '', $id);
      if ($this->events[$master_id] && $this->events[$master_id]['recurrence']) {
        $master = $this->events[$master_id];
        $this->_get_recurring_events($master, $master['start'], $master['start'] + 86400 * 365 * 10, $id);
      }
    }
    
    return $this->events[$id];
  }


  /**
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  string  Search query (optional)
   * @return array A list of event records
   */
  public function list_events($start, $end, $search = null)
  {
    $this->_fetch_events();
    
    $events = array();
    foreach ($this->events as $id => $event) {
      // filter events by search query
      if (!empty($search)) {
        $hit = false;
        foreach ($this->search_fields as $col) {
          if (empty($event[$col]))
            continue;
          
          // do a simple substring matching (to be improved)
          $val = mb_strtolower($event[$col]);
          if (strpos($val, $search) !== false) {
            $hit = true;
            break;
          }
        }
        
        if (!$hit)  // skip this event if not match with search term
          continue;
      }
      
      // list events in requested time window
      if ($event['start'] <= $end && $event['end'] >= $start) {
        $events[] = $event;
      }
      
      // resolve recurring events
      if ($event['recurrence']) {
        $events = array_merge($events, $this->_get_recurring_events($event, $start, $end));
      }
    }

    return $events;
  }


  /**
   * Create a new event record
   *
   * @see Driver:new_event()
   * 
   * @return mixed The created record ID on success, False on error
   */
  public function insert_event($event)
  {
    if (!is_array($event))
      return false;

    //generate new event from RC input
    $object = $this->_from_rcube_event($event);
    $saved = $this->storage->save($object);
    
    if (PEAR::isError($saved)) {
      raise_error(array(
        'code' => 600, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Error saving event object to Kolab server:" . $saved->getMessage()),
        true, false);
      $saved = false;
    }
    
    return $saved;
  }

  /**
   * Update a specific event record
   *
   * @see Driver:new_event()
   * @return boolean True on success, False on error
   */

  public function update_event($event)
  {
    $updated = false;
    $old = $this->storage->getObject($event['id']);
    $object = array_merge($old, $this->_from_rcube_event($event));
    $saved = $this->storage->save($object, $event['id']);
    if (PEAR::isError($saved)) {
      raise_error(array(
        'code' => 600, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Error saving event object to Kolab server:" . $saved->getMessage()),
        true, false);
    }
    else {
      $updated = true;
    }

    return $updated;
  }

  /**
   * Delete an event record
   *
   * @see Driver:remove_event()
   * @return boolean True on success, False on error
   */
  public function delete_event($event)
  {
    $deleted = false;
    $deleteme = $this->storage->delete($event['id']);
    if (PEAR::isError($deleteme)) {
      raise_error(array(
        'code' => 600, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Error deleting event object from Kolab server:" . $deleteme->getMessage()),
        true, false);
    }
    else {
      $deleted = true;
    }
    
    return $deleted;
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
   * Create instances of a recurring event
   */
  private function _get_recurring_events($event, $start, $end, $event_id = null)
  {
    // use Horde classes to compute recurring instances
    require_once($this->cal->home . '/lib/Horde_Date_Recurrence.php');
    
    $recurrence = new Horde_Date_Recurrence($event['start']);
    $recurrence->fromRRule20(calendar::to_rrule($event['recurrence']));
    
    foreach ((array)$event['recurrence']['EXDATE'] as $exdate)
      $recurrence->addException(date('Y', $exdate), date('n', $exdate), date('j', $exdate));
    
    $events = array();
    $duration = $event['end'] - $event['start'];
    $next = new Horde_Date($event['start']);
    $i = 0;
    while ($next = $recurrence->nextActiveRecurrence(array('year' => $next->year, 'month' => $next->month, 'mday' => $next->mday + 1, 'hour' => $next->hour, 'min' => $next->min, 'sec' => $next->sec))) {
      $rec_start = $next->timestamp();
      $rec_end = $rec_start + $duration;
      $rec_id = $event['id'] . '-' . ++$i;
      
      // add to output if in range
      if (($rec_start <= $end && $rec_end >= $start) || ($event_id && $rec_id == $event_id)) {
        $rec_event = $event;
        $rec_event['id'] = $rec_id;
        $rec_event['recurrence_id'] = $event['id'];
        $rec_event['start'] = $rec_start;
        $rec_event['end'] = $rec_end;
        $rec_event['_instance'] = $i;
        $events[] = $rec_event;
        
        if ($rec_id == $event_id) {
          $this->events[$rec_id] = $rec_event;
          break;
        }
      }
      else if ($rec_start > $end)  // stop loop if out of range
        break;
    }
    
    return $events;
  }

  /**
   * Convert from Kolab_Format to internal representation
   */
  private function _to_rcube_event($rec)
  {
    $start_time = date('H:i:s', $rec['start-date']);
    $allday = $start_time == '00:00:00' && $start_time == date('H:i:s', $rec['end-date']);
    if ($allday) {  // in Roundcube all-day events only go until 23:59:59 of the last day
      $rec['end-date']--;
      $rec['end-date'] -= $this->cal->timezone * 3600 - date('Z');   // shift 00 times from server's timezone to user's timezone
      $rec['start-date'] -= $this->cal->timezone * 3600 - date('Z');  // because generated with mktime() in Horde_Kolab_Format_Date::decodeDate()
    }
    
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
        if ($recurrence['type'] == 'monthday' || $recurrence['type'] == 'daynumber')
          $rrule['BYMONTHDAY'] = $recurrence['daynumber'];
        else if ($recurrence['type'] == 'yearday')
          $rrule['BYYEARDAY'] = $recurrence['daynumber'];
      }
      if ($recurrence['month']) {
        $monthmap = array_flip($this->month_map);
        $rrule['BYMONTH'] = strtolower($monthmap[$recurrence['month']]);
      }
      
      if ($recurrence['exclusion']) {
        foreach ((array)$recurrence['exclusion'] as $excl)
          $rrule['EXDATE'][] = strtotime($excl . date(' H:i:s', $rec['start-date']));  // use time of event start
      }
    }

    $sensitivity_map = array_flip($this->sensitivity_map);
    $priority_map = array_flip($this->priority_map);

    if (!empty($rec['_attachments'])) {
      foreach ($rec['_attachments'] as $name => $attachment) {
        // @TODO: 'type' and 'key' are the only supported (no 'size')
        $attachments[] = array(
          'id' => $attachment['key'],
          'mimetype' => $attachment['type'],
          'name' => $name,
        );
      }
    }
    
    if ($rec['organizer']) {
      $attendees[] = array(
        'role' => 'OWNER',
        'name' => $rec['organizer']['display-name'],
        'email' => $rec['organizer']['smtp-address'],
        'status' => 'accepted',
      );
    }
    
    foreach ((array)$rec['attendee'] as $attendee) {
      $attendees[] = array(
        'role' => strtoupper($attendee['role']),
        'name' => $attendee['display-name'],
        'email' => $attendee['smtp-address'],
        'status' => $attendee['status'],
      );
    }

    return array(
      'id' => $rec['uid'],
      'uid' => $rec['uid'],
      'title' => $rec['summary'],
      'location' => $rec['location'],
      'description' => $rec['body'],
      'start' => $rec['start-date'],
      'end' => $rec['end-date'],
      'allday' => $allday,
      'recurrence' => $rrule,
      'alarms' => $alarm_value . $alarm_unit,
      'categories' => $rec['categories'],
      'attachments' => $attachments,
      'attendees' => $attendees,
      'free_busy' => $rec['show-time-as'],
      'priority' => isset($priority_map[$rec['priority']]) ? $priority_map[$rec['priority']] : 1,
      'sensitivity' => $sensitivity_map[$rec['sensitivity']],
      'calendar' => $this->id,
    );
  }

   /**
   * Convert the given event record into a data structure that can be passed to Kolab_Storage backend for saving
   * (opposite of self::_to_rcube_event())
   */
  private function _from_rcube_event($event)
  {
    $priority_map = $this->priority_map;
    $tz_offset = $this->cal->timezone * 3600;

    $object = array(
    // kolab         => roundcube
      'uid'          => $event['uid'],
      'summary'      => $event['title'],
      'location'     => $event['location'],
      'body'         => $event['description'],
      'categories'   => $event['categories'],
      'start-date'   => $event['start'],
      'end-date'     => $event['end'],
      'sensitivity'  =>$this->sensitivity_map[$event['sensitivity']],
      'show-time-as' => $event['free_busy'],
      'priority'     => isset($priority_map[$event['priority']]) ? $priority_map[$event['priority']] : 1,
    );
    
    //handle alarms
    if ($event['alarms']) {
      //get the value
      $alarmbase = explode(":", $event['alarms']);
      
      //get number only
      $avalue = preg_replace('/[^0-9]/', '', $alarmbase[0]); 
      
      if (preg_match("/H/",$alarmbase[0])) {
        $object['alarm'] = $avalue*60;
      } else if (preg_match("/D/",$alarmbase[0])) {
        $object['alarm'] = $avalue*24*60;
      } else {
        $object['alarm'] = $avalue;
      }
    }
    
    //recurr object/array
    if (count($event['recurrence']) > 1) {
      $ra = $event['recurrence'];
      
      //Frequency abd interval
      $object['recurrence']['cycle'] = strtolower($ra['FREQ']);
      $object['recurrence']['interval'] = intval($ra['INTERVAL']);

      //Range Type
      if ($ra['UNTIL']) {
        $object['recurrence']['range-type'] = 'date';
        $object['recurrence']['range'] = $ra['UNTIL'];
      }
      if ($ra['COUNT']) {
        $object['recurrence']['range-type'] = 'number';
        $object['recurrence']['range'] = $ra['COUNT'];
      }
      
      //weekly
      if ($ra['FREQ'] == 'WEEKLY') {
        if ($ra['BYDAY']) {
          foreach (split(",", $ra['BYDAY']) as $day)
            $object['recurrence']['day'][] = $this->weekday_map[$day];
        }
        else {
          // use weekday of start date if empty
          $object['recurrence']['day'][] = strtolower(gmdate('l', $event['start'] + $tz_offset));
        }
      }
      
      //monthly (temporary hack to follow current Horde logic)
      if ($ra['FREQ'] == 'MONTHLY') {
        if ($ra['BYDAY'] && preg_match('/(-?[1-4])([A-Z]+)/', $ra['BYDAY'], $m)) {
          $object['recurrence']['daynumber'] = $m[1];
          $object['recurrence']['day'] = array($this->weekday_map[$m[2]]);
          $object['recurrence']['cycle'] = 'monthly';
          $object['recurrence']['type']  = 'weekday';
        }
        else {
          $object['recurrence']['daynumber'] = date('j', $event['start']);
          $object['recurrence']['cycle'] = 'monthly';
          $object['recurrence']['type']  = 'daynumber';
        }
      }
      
      //yearly
      if ($ra['FREQ'] == 'YEARLY') {
        if (!$ra['BYMONTH'])
          $ra['BYMONTH'] = gmdate('n', $event['start'] + $tz_offset);
        
        $object['recurrence']['cycle'] = 'yearly';
        $object['recurrence']['month'] = $this->month_map[intval($ra['BYMONTH'])];
        
        if ($ra['BYDAY'] && preg_match('/(-?[1-4])([A-Z]+)/', $ra['BYDAY'], $m)) {
          $object['recurrence']['type'] = 'weekday';
          $object['recurrence']['daynumber'] = $m[1];
          $object['recurrence']['day'] = array($this->weekday_map[$m[2]]);
        }
        else {
          $object['recurrence']['type'] = 'monthday';
          $object['recurrence']['daynumber'] = gmdate('j', $event['start'] + $tz_offset);
        }
      }
      
      //exclusions
      foreach ((array)$ra['EXDATE'] as $excl) {
        $object['recurrence']['exclusion'][] = gmdate('Y-m-d', $excl + $tz_offset);
      }
    }
    
    // whole day event
    if ($event['allday']) {
      $object['end-date'] += 60;  // end is at 23:59 => jump to the next day
      $object['end-date'] += $tz_offset - date('Z');   // shift 00 times from user's timezone to server's timezone 
      $object['start-date'] += $tz_offset - date('Z');  // because Horde_Kolab_Format_Date::encodeDate() uses strftime()
      $object['_is_all_day'] = 1;
    }

    // in Horde attachments are indexed by name
    $object['_attachments'] = array();
    if (!empty($event['attachments'])) {
      $collisions = array();
      foreach ($event['attachments'] as $idx => $attachment) {
        // Roundcube ID has nothing to do with Horde ID, remove it
        if ($attachment['content'])
          unset($attachment['id']);
        
        // Horde code assumes that there will be no more than
        // one file with the same name: make filenames unique
        $filename = $attachment['name'];
        if ($collisions[$filename]++) {
          $ext = preg_match('/(\.[a-z0-9]{1,6})$/i', $filename, $m) ? $m[1] : null;
          $attachment['name'] = basename($filename, $ext) . '-' . $collisions[$filename] . $ext;
        }
        
        $object['_attachments'][$attachment['name']] = $attachment;
        unset($event['attachments'][$idx]);
      }
    }
    
    // process event attendees
    foreach ((array)$event['attendees'] as $attendee) {
      $role = $attendee['role'];
      if ($role == 'OWNER') {
        $object['organizer'] = array(
          'display-name' => $attendee['name'],
          'smtp-address' => $attendee['email'],
        );
      }
      else {
        $object['attendee'][] = array(
          'display-name' => $attendee['name'],
          'smtp-address' => $attendee['email'],
          'status' => $attendee['status'],
          'role' => strtolower($role),
        );
      }
    }

    return $object;
  }


}
