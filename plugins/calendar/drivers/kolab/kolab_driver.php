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
  public $attendees = true;
  public $attachments = true;
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
    
    $this->cal->register_action('push-freebusy', array($this, 'push_freebusy'));
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

      if (!$cal->readonly && $newfolder != $oldfolder)
        $result = rcube_kolab::folder_rename($oldfolder, $newfolder);
      else
        $result = true;

      if ($result) {
        // create ID
        $id = $newfolder ? rcube_kolab::folder_id($newfolder) : $prop['id'];
        // save color in user prefs (temp. solution)
        $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', array());
        unset($prefs['kolab_calendars'][$prop['id']]);
        $prefs['kolab_calendars'][$id]['color'] = $prop['color'];

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
    if ($storage = $this->calendars[$cid]) {
      // handle attachments to add
      if (!empty($event['attachments'])) {
        foreach ($event['attachments'] as $idx => $attachment) {
          // we'll read file contacts into memory, Horde/Kolab classes does the same
          // So we cannot save memory, rcube_imap class can do this better
          $event['attachments'][$idx]['content'] = $attachment['data'] ? $attachment['data'] : file_get_contents($attachment['path']);
        }
      }

      $GLOBALS['conf']['kolab']['no_triggering'] = true;
      $success = $storage->insert_event($event);
      
      if ($success)
        $this->rc->output->command('plugin.ping_url', array('action' => 'push-freebusy', 'source' => $storage->id));
      
      return $success;
    }

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
    return $this->update_event($event);
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
      return $this->update_event($event + $ev);

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
    if (($storage = $this->calendars[$event['calendar']]) && ($ev = $storage->get_event($event['id'])))
      return $this->update_event($event + $ev);

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
    $success = false;
    
    if (($storage = $this->calendars[$event['calendar']]) && ($event = $storage->get_event($event['id']))) {
      $savemode = 'all';
      $master = $event;
      
      $GLOBALS['conf']['kolab']['no_triggering'] = true;
      
      // read master if deleting a recurring event
      if ($event['recurrence'] || $event['recurrence_id']) {
        $master = $event['recurrence_id'] ? $storage->get_event($event['recurrence_id']) : $event;
        $savemode = $event['savemode'];
      }
      
      switch ($savemode) {
        case 'current':
          // add exception to master event
          $master['recurrence']['EXDATE'][] = $event['start'];
          $success = $storage->update_event($master);
          break;
        
        case 'future':
          if ($master['id'] != $event['id']) {
            // set until-date on master event
            $master['recurrence']['UNTIL'] = $event['start'] - 86400;
            unset($master['recurrence']['COUNT']);
            $success = $storage->update_event($master);
            break;
          }
        
        default:  // 'all' is default
          $success = $storage->delete_event($master);
          break;
      }
    }

    if ($success)
      $this->rc->output->command('plugin.ping_url', array('action' => 'push-freebusy', 'source' => $storage->id));

    return $success;
  }

  /**
   * Wrapper to update an event object depending on the given savemode
   */
  private function update_event($event)
  {
    if (!($storage = $this->calendars[$event['calendar']]))
      return false;

    $success = false;
    $savemode = 'all';
    $attachments = array();
    $old = $master = $storage->get_event($event['id']);

    // delete existing attachment(s)
    if (!empty($event['deleted_attachments'])) {
      foreach ($event['deleted_attachments'] as $attachment) {
        if (!empty($old['attachments'])) {
          foreach ($old['attachments'] as $idx => $att) {
            if ($att['id'] == $attachment) {
              unset($old['attachments'][$idx]);
            }
          }
        }
      }
    }

    // handle attachments to add
    if (!empty($event['attachments'])) {
      foreach ($event['attachments'] as $attachment) {
        // skip entries without content (could be existing ones)
        if (!$attachment['data'] && !$attachment['path'])
          continue;
        // we'll read file contacts into memory, Horde/Kolab classes does the same
        // So we cannot save memory, rcube_imap class can do this better
        $attachments[] = array(
          'name' => $attachment['name'],
          'type' => $attachment['mimetype'],
          'content' => $attachment['data'] ? $attachment['data'] : file_get_contents($attachment['path']),
        );
      }
    }

    $event['attachments'] = array_merge((array)$old['attachments'], $attachments);

    // modify a recurring event, check submitted savemode to do the right things
    if ($old['recurrence'] || $old['recurrence_id']) {
      $master = $old['recurrence_id'] ? $storage->get_event($old['recurrence_id']) : $old;
      $savemode = $event['savemode'];
    }

    // keep saved exceptions (not submitted by the client)
    if ($old['recurrence']['EXDATE'])
      $event['recurrence']['EXDATE'] = $old['recurrence']['EXDATE'];

    $GLOBALS['conf']['kolab']['no_triggering'] = true;
    
    switch ($savemode) {
      case 'new':
        // save submitted data as new (non-recurring) event
        $event['recurrence'] = array();
        $event['uid'] = $this->cal->generate_uid();
        $success = $storage->insert_event($event);
        break;
        
      case 'current':
        // add exception to master event
        $master['recurrence']['EXDATE'][] = $old['start'];
        $storage->update_event($master);
        
        // insert new event for this occurence
        $event += $old;
        $event['recurrence'] = array();
        $event['uid'] = $this->cal->generate_uid();
        $success = $storage->insert_event($event);
        break;
        
      case 'future':
        if ($master['id'] != $event['id']) {
          // set until-date on master event
          $master['recurrence']['UNTIL'] = $old['start'] - 86400;
          unset($master['recurrence']['COUNT']);
          $storage->update_event($master);
          
          // save this instance as new recurring event
          $event += $old;
          $event['uid'] = $this->cal->generate_uid();
          
          // if recurrence COUNT, update value to the correct number of future occurences
          if ($event['recurrence']['COUNT']) {
            $event['recurrence']['COUNT'] -= $old['_instance'];
          }
          
          // remove fixed weekday, will be re-set to the new weekday in kolab_calendar::insert_event()
          if (strlen($event['recurrence']['BYDAY']) == 2)
            unset($event['recurrence']['BYDAY']);
          if ($master['recurrence']['BYMONTH'] == gmdate('n', $master['start']))
            unset($event['recurrence']['BYMONTH']);
          
          $success = $storage->insert_event($event);
          break;
        }

      default:  // 'all' is default
        $event['id'] = $master['id'];
        $event['uid'] = $master['uid'];

        // use start date from master but try to be smart on time or duration changes
        $old_start_date = date('Y-m-d', $old['start']);
        $old_start_time = date('H:i:s', $old['start']);
        $old_duration = $old['end'] - $old['start'];
        
        $new_start_date = date('Y-m-d', $event['start']);
        $new_start_time = date('H:i:s', $event['start']);
        $new_duration = $event['end'] - $event['start'];
        
        // shifted or resized
        if ($old_start_date == $new_start_date || $old_duration == $new_duration) {
          $event['start'] = $master['start'] + ($event['start'] - $old['start']);
          $event['end'] = $event['start'] + $new_duration;
          
          // remove fixed weekday, will be re-set to the new weekday in kolab_calendar::update_event()
          if (strlen($event['recurrence']['BYDAY']) == 2)
            unset($event['recurrence']['BYDAY']);
          if ($old['recurrence']['BYMONTH'] == gmdate('n', $old['start']))
            unset($event['recurrence']['BYMONTH']);
        }

        $success = $storage->update_event($event);
        break;
    }
    
    if ($success)
      $this->rc->output->command('plugin.ping_url', array('action' => 'push-freebusy', 'source' => $storage->id));
    
    return $success;
  }

  /**
   * Get events from source.
   *
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  string  Search query (optional)
   * @param  mixed   List of calendar IDs to load events from (either as array or comma-separated string)
   * @return array A list of event records
   */
  public function load_events($start, $end, $search = null, $calendars = null)
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
   * Get a list of pending alarms to be displayed to the user
   *
   * @see Driver:pending_alarms()
   */
  public function pending_alarms($time, $calendars = null)
  {
    $interval = 300;
    $time -= $time % 60;
    
    $slot = $time;
    $slot -= $slot % $interval;
    
    $last = $time - max(60, $this->rc->session->get_keep_alive());
    $last -= $last % $interval;
    
    // only check for alerts once in 5 minutes
    if ($last == $slot)
      return false;
    
    if ($calendars && is_string($calendars))
      $calendars = explode(',', $calendars);
    
    $time = $slot + $interval;
    
    $events = array();
    foreach ($this->calendars as $cid => $calendar) {
      // skip calendars with alarms disabled
      if (!$calendar->alarms || ($calendars && !in_array($cid, $calendars)))
        continue;

      foreach ($calendar->list_events($time, $time + 86400 * 365) as $e) {
        // add to list if alarm is set
        if ($e['_alarm'] && ($notifyat = $e['start'] - $e['_alarm'] * 60) <= $time) {
          $id = $e['id'];
          $events[$id] = $e;
          $events[$id]['notifyat'] = $notifyat;
        }
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
   * List attachments from the given event
   */
  public function list_attachments($event)
  {
    if (!($storage = $this->calendars[$event['calendar']]))
      return false;

    $event = $storage->get_event($event['id']);

    return $event['attachments'];
  }

  /**
   * Get attachment properties
   */
  public function get_attachment($id, $event)
  {
    if (!($storage = $this->calendars[$event['calendar']]))
      return false;

    $event = $storage->get_event($event['id']);

    if ($event && !empty($event['attachments'])) {
      foreach ($event['attachments'] as $att) {
        if ($att['id'] == $id) {
          return $att;
        }
      }
    }

    return null;
  }

  /**
   * Get attachment body
   */
  public function get_attachment_body($id, $event)
  {
    if (!($storage = $this->calendars[$event['calendar']]))
      return false;

    return $storage->get_attachment_body($id);
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
    require_once('Horde/iCalendar.php');
    
    if (empty($email)/* || $end < time()*/)
      return false;
    
    // ask kolab server first
    $fbdata = @file_get_contents(rcube_kolab::get_freebusy_url($email));
      
    // get free-busy url from contacts
    if (!$fbdata) {
      $fburl = null;
      foreach ((array)$this->rc->config->get('autocomplete_addressbooks', 'sql') as $book) {
        $abook = $this->rc->get_address_book($book);
        
        if ($result = $abook->search(array('email'), $email, true, true, true/*, 'freebusyurl'*/)) {
          while ($contact = $result->iterate()) {
            if ($fburl = $contact['freebusyurl']) {
              $fbdata = @file_get_contents($fburl);
              break;
            }
          }
        }
        
        if ($fbdata)
          break;
      }
    }
    
    // parse free-busy information using Horde classes
    if ($fbdata) {
      $fbcal = new Horde_iCalendar;
      $fbcal->parsevCalendar($fbdata);
      if ($fb = $fbcal->findComponent('vfreebusy')) {
        $result = array();
        foreach ($fb->getBusyPeriods() as $from => $to) {
          if ($to == null)  // no information, assume free
            break;
          $result[] = array($from, $to);
        }
        
        return $result;
      }
    }
    
    return false;
  }
  
  /**
   * Handler to push folder triggers when sent from client.
   * Used to push free-busy changes asynchronously after updating an event
   */
  public function push_freebusy()
  {
    // make shure triggering completes
    set_time_limit(0);
    ignore_user_abort(true);

    $cal = get_input_value('source', RCUBE_INPUT_GPC);
    if (!($storage = $this->calendars[$cal]))
      return false;
    
    // trigger updates on folder
    $folder = $storage->get_folder();
    $trigger = $folder->trigger();
    if (is_a($trigger, 'PEAR_Error')) {
      raise_error(array(
        'code' => 900, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Failed triggering folder. Error was " . $trigger->getMessage()),
        true, false);
    }
    
    exit;
  }

}
