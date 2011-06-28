<?php
/*
 +-------------------------------------------------------------------------+
 | Kolab driver for the Calendar Plugin                                    |
 | Version 0.3 beta                                                        |
 |                                                                         |
 | Copyright (C) 2011, Kolab Systems AG                                    |
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
 | Author: Aleksander Machniak <machniak@kolabsys.com>                     |
 +-------------------------------------------------------------------------+
*/

require_once(dirname(__FILE__) . '/kolab_calendar.php');

class kolab_driver extends calendar_driver
{
  // features this backend supports
  public $alarms = true;
  public $attendees = false;
  public $attachments = false;
  public $categoriesimmutable = true;

  private $rc;
  private $cal;
  private $calendars;

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
    $this->calendars = array();

    if (PEAR::isError($folders)) {
      raise_error(array(
        'code' => 600, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Failed to list calendar folders from Kolab server:" . $folders->getMessage()),
      true, false);
    }
    else {
      // convert to UTF8 and sort
      $names = array();
      foreach ($folders as $folder)
        $names[$folder->name] = rcube_charset_convert($folder->name, 'UTF7-IMAP');

      asort($names, SORT_LOCALE_STRING);

      foreach ($names as $utf7name => $name) {
        $calendar = new kolab_calendar($utf7name, $this->cal);
        $this->calendars[$calendar->id] = $calendar;
      }
    }

    return $this->calendars;
  }


  /**
   * Get a list of available calendars from this source
   */
  public function list_calendars()
  {
    // attempt to create a default calendar for this user
    if (empty($this->calendars)) {
      if ($this->create_calendar(array('name' => 'Calendar', 'color' => 'cc0000')))
        $this->_read_calendars();
    }

    $calendars = $names = array();

    foreach ($this->calendars as $id => $cal) {
      if ($cal->ready) {
        $name = $origname = $cal->get_name();

        // find folder prefix to truncate (the same code as in kolab_addressbook plugin)
        for ($i = count($names)-1; $i >= 0; $i--) {
          if (strpos($name, $names[$i].' &raquo; ') === 0) {
            $length = strlen($names[$i].' &raquo; ');
            $prefix = substr($name, 0, $length);
            $count  = count(explode(' &raquo; ', $prefix));
            $name   = str_repeat('&nbsp;&nbsp;', $count-1) . '&raquo; ' . substr($name, $length);
            break;
          }
        }

        $names[] = $origname;

        $calendars[$cal->id] = array(
          'id'       => $cal->id,
          'name'     => $name,
          'editname' => $cal->get_foldername(),
          'color'    => $cal->get_color(),
          'readonly' => $cal->readonly,
          'class_name' => $cal->get_namespace(),
        );
      }
    }

    return $calendars;
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
    $folder = rcube_charset_convert($prop['name'], RCMAIL_CHARSET, 'UTF7-IMAP');

    // add namespace prefix (when needed)
    $this->rc->imap_init();
    $folder = $this->rc->imap->mod_mailbox($folder, 'in');

    // create ID
    $id = rcube_kolab::folder_id($folder);

    // create IMAP folder
    if (rcube_kolab::folder_create($folder, 'event')) {
      // save color in user prefs (temp. solution)
      $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', array());
      $prefs['kolab_calendars'][$id]['color'] = $prop['color'];

      $this->rc->user->save_prefs($prefs);

      return $id;
    }

    return false;
  }


  /**
   * Update properties of an existing calendar
   *
   * @see calendar_driver::edit_calendar()
   */
  public function edit_calendar($prop)
  {
    if ($prop['id'] && ($cal = $this->calendars[$prop['id']])) {
      $newfolder = rcube_charset_convert($prop['name'], RCMAIL_CHARSET, 'UTF7-IMAP');
      $oldfolder = $cal->get_realname();
      // add namespace prefix (when needed)
      $this->rc->imap_init();
      $newfolder = $this->rc->imap->mod_mailbox($newfolder, 'in');

      if ($newfolder != $oldfolder)
        $result = rcube_kolab::folder_rename($oldfolder, $newfolder);
      else
        $result = true;

      if ($result) {
        // create ID
        $id = rcube_kolab::folder_id($newfolder);
        // save color in user prefs (temp. solution)
        $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', array());
        $prefs['kolab_calendars'][$id]['color'] = $prop['color'];
        unset($prefs['kolab_calendars'][$prop['id']]);

        $this->rc->user->save_prefs($prefs);

        return true;
      }
    }

  	 return false;
  }


  /**
   * Delete the given calendar with all its contents
   *
   * @see calendar_driver::remove_calendar()
   */
  public function remove_calendar($prop)
  {
    if ($prop['id'] && ($cal = $this->calendars[$prop['id']])) {
      $folder = $cal->get_realname();
  	  if (rcube_kolab::folder_delete($folder)) {
        // remove color in user prefs (temp. solution)
        $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', array());
        unset($prefs['kolab_calendars'][$prop['id']]);

        $this->rc->user->save_prefs($prefs);
        return true;
  	  }
    }

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
    if ($storage = $this->calendars($cid))
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
    if ($storage = $this->calendars[$event['calendar']])
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
    if (($storage = $this->calendars[$event['calendar']]) && ($ev = $storage->get_event($event['id'])))
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
  	if (($storage = $this->calendars[$event['calendar']]) && ($ev = $storage->get_event($event['id'])))
      return $storage->delete_event($event);

    return false;
  }

  /**
   * Get events from source.
   *
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  mixed   List of calendar IDs to load events from (either as array or comma-separated string)
   * @param  string  Search query (optional)
   * @return array A list of event records
   */
  public function load_events($start, $end, $calendars = null, $search = null)
  {
    if ($calendars && is_string($calendars))
      $calendars = explode(',', $calendars);

    $events = array();
    foreach ($this->calendars as $cid => $calendar) {
      if ($calendars && !in_array($cid, $calendars))
        continue;

      $events = array_merge($events, $this->calendars[$cid]->list_events($start, $end, $search));
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
    // delegate request to load_events()
    return $this->load_events($start, $end, $calendars, $query);
  }

  /**
   * Get a list of pending alarms to be displayed to the user
   *
   * @see Driver:pending_alarms()
   */
  public function pending_alarms($time, $calendars = null)
  {
    $events = array();
    foreach ($this->load_events($time, $time + 86400 * 365, $calendars) as $e) {
      // add to list if alarm is set
      if ($e['_alarm'] && ($notifyat = $e['start'] - $e['_alarm'] * 60) <= $time) {
        $id = $e['id'];
        $events[$id] = $e;
        $events[$id]['notifyat'] = $notifyat;
      }
    }

    // get alarm information stored in local database
    if (!empty($events)) {
      $event_ids = array_map(array($this->rc->db, 'quote'), array_keys($events));
      $result = $this->rc->db->query(sprintf(
        "SELECT * FROM kolab_alarms
         WHERE event_id IN (%s)",
         join(',', $event_ids),
         $this->rc->db->now()
       ));

      while ($result && ($e = $this->rc->db->fetch_assoc($result))) {
        $dbdata[$e['event_id']] = $e;
      }
    }
    
    $alarms = array();
    foreach ($events as $id => $e) {
      // skip dismissed
      if ($dbdata[$id]['dismissed'])
        continue;
      
      // snooze function may have shifted alarm time
      $notifyat = $dbdata[$id]['notifyat'] ? strtotime($dbdata[$id]['notifyat']) : $e['notifyat'];
      if ($notifyat <= $time)
        $alarms[] = $e;
    }
    
    return $alarms;
  }

  /**
   * Feedback after showing/sending an alarm notification
   *
   * @see Driver:dismiss_alarm()
   */
  public function dismiss_alarm($event_id, $snooze = 0)
  {
    // set new notifyat time or unset if not snoozed
    $notifyat = $snooze > 0 ? date('Y-m-d H:i:s', time() + $snooze) : null;
    
    $query = $this->rc->db->query(
      "REPLACE INTO kolab_alarms
       (event_id, dismissed, notifyat)
       VALUES(?, ?, ?)",
      $event_id,
      $snooze > 0 ? 0 : 1, 
      $notifyat
    );
    
    return $this->rc->db->affected_rows($query);
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
   * List availabale categories
   * The default implementation reads them from config/user prefs
   */
  public function list_categories()
  {
    # fixed list according to http://www.kolab.org/doc/kolabformat-2.0rc7-html/c300.html
    return array(
      'important' => 'cc0000',
      'business' => '333333',
      'personal' => '333333',
      'vacation' => '333333',
      'must-attend' => '333333',
      'travel-required' => '333333',
      'needs-preparation' => '333333',
      'birthday' => '333333',
      'anniversary' => '333333',
      'phone-call' => '333333',
    );
  }

  /**
   * Fetch free/busy information from a person within the given range
   */
  public function get_freebusy_list($email, $start, $end)
  {
    return array();
  }

}
