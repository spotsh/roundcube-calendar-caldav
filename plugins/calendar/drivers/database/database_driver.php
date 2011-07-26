<?php
/*
 +-------------------------------------------------------------------------+
 | Database driver for the Calendar Plugin                                 |
 | Version 0.3 beta                                                        |
 |                                                                         |
 | This program is free software; you can redistribute it and/or modify    |
 | it under the terms of the GNU General Public License version 2          |
 | as published by the Free Software Foundation.                           |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 |                                                                         |
 | You should have received a copy of the GNU General Public License along |
 | with this program; if not, write to the Free Software Foundation, Inc., |
 | 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.             |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Lazlo Westerhof <hello@lazlo.me>                                |
 |         Thomas Bruederli <roundcube@gmail.com>                          |
 +-------------------------------------------------------------------------+
*/

class database_driver extends calendar_driver
{
  // features this backend supports
  public $alarms = true;
  public $attendees = true;
  public $freebusy = false;
  public $attachments = true;
  public $alarm_types = array('DISPLAY','EMAIL');

  private $rc;
  private $cal;
  private $calendars = array();
  private $calendar_ids = '';
  private $free_busy_map = array('free' => 0, 'busy' => 1, 'out-of-office' => 2, 'outofoffice' => 2, 'tentative' => 3);
  
  private $db_events = 'events';
  private $db_calendars = 'calendars';
  private $db_attachments = 'attachments';
  private $sequence_events = 'event_ids';
  private $sequence_calendars = 'calendar_ids';
  private $sequence_attachments = 'attachment_ids';


  /**
   * Default constructor
   */
  public function __construct($cal)
  {
    $this->cal = $cal;
    $this->rc = $cal->rc;
    
    // load library classes
    require_once($this->cal->home . '/lib/Horde_Date_Recurrence.php');
    
    // read database config
    $this->db_events = $this->rc->config->get('db_table_events', $this->db_events);
    $this->db_calendars = $this->rc->config->get('db_table_calendars', $this->db_calendars);
    $this->db_attachments = $this->rc->config->get('db_table_attachments', $this->db_attachments);
    $this->sequence_events = $this->rc->config->get('db_sequence_events', $this->sequence_events);
    $this->sequence_calendars = $this->rc->config->get('db_sequence_calendars', $this->sequence_calendars);
    $this->sequence_attachments = $this->rc->config->get('db_sequence_attachments', $this->sequence_attachments);
    
    $this->_read_calendars();
  }

  /**
   * Read available calendars for the current user and store them internally
   */
  private function _read_calendars()
  {
    if (!empty($this->rc->user->ID)) {
      $calendar_ids = array();
      $result = $this->rc->db->query(
        "SELECT *, calendar_id AS id FROM " . $this->db_calendars . "
         WHERE user_id=?",
         $this->rc->user->ID
      );
      while ($result && ($arr = $this->rc->db->fetch_assoc($result))) {
        $this->calendars[$arr['calendar_id']] = $arr;
        $calendar_ids[] = $this->rc->db->quote($arr['calendar_id']);
      }
      $this->calendar_ids = join(',', $calendar_ids);
    }
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
    $result = $this->rc->db->query(
      "INSERT INTO " . $this->db_calendars . "
       (user_id, name, color)
       VALUES (?, ?, ?)",
       $this->rc->user->ID,
       $prop['name'],
       $prop['color']
    );
    
    if ($result)
      return $this->rc->db->insert_id($this->sequence_calendars);
    
    return false;
  }
  
  /**
   * Update properties of an existing calendar
   *
   * @see calendar_driver::edit_calendar()
   */
  public function edit_calendar($prop)
  {
    $query = $this->rc->db->query(
      "UPDATE " . $this->db_calendars . "
       SET   name=?, color=?
       WHERE calendar_id=?
       AND   user_id=?",
      $prop['name'],
      $prop['color'],
      $prop['id'],
      $this->rc->user->ID
    );
    
    return $this->rc->db->affected_rows($query);
  }

  /**
   * Delete the given calendar with all its contents
   *
   * @see calendar_driver::remove_calendar()
   */
  public function remove_calendar($prop)
  {
    if (!$this->calendars[$prop['id']])
      return false;

    // events and attachments will be deleted by foreign key cascade

    $query = $this->rc->db->query(
      "DELETE FROM " . $this->db_calendars . "
       WHERE calendar_id=?",
       $prop['id']
    );

    return $this->rc->db->affected_rows($query);
  }

  /**
   * Add a single event to the database
   *
   * @param array Hash array with event properties
   * @see Driver:new_event()
   */
  public function new_event($event)
  {
    if (!empty($this->calendars)) {
      if ($event['calendar'] && !$this->calendars[$event['calendar']])
        return false;
      if (!$event['calendar'])
        $event['calendar'] = reset(array_keys($this->calendars));
      
      $event = $this->_save_preprocess($event);
      $query = $this->rc->db->query(sprintf(
        "INSERT INTO " . $this->db_events . "
         (calendar_id, created, changed, uid, start, end, all_day, recurrence, title, description, location, categories, free_busy, priority, sensitivity, attendees, alarms, notifyat)
         VALUES (?, %s, %s, ?, %s, %s, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
          $this->rc->db->now(),
          $this->rc->db->now(),
          $this->rc->db->fromunixtime($event['start']),
          $this->rc->db->fromunixtime($event['end'])
        ),
        $event['calendar'],
        strval($event['uid']),
        intval($event['all_day']),
        $event['recurrence'],
        strval($event['title']),
        strval($event['description']),
        strval($event['location']),
        strval($event['categories']),
        intval($event['free_busy']),
        intval($event['priority']),
        intval($event['sensitivity']),
        $event['attendees'],
        $event['alarms'],
        $event['notifyat']
      );

      $event_id = $this->rc->db->insert_id($this->sequence_events);

      if ($event_id) {
        // add attachments
        if (!empty($event['attachments'])) {
          foreach ($event['attachments'] as $attachment) {
            $this->add_attachment($attachment, $event_id);
            unset($attachment);
          }
        }

        $this->_update_recurring($event);
      }

      return $event_id;
    }
    
    return false;
  }

  /**
   * Update an event entry with the given data
   *
   * @param array Hash array with event properties
   * @see Driver:edit_event()
   */
  public function edit_event($event)
  {
    if (!empty($this->calendars)) {
      $update_master = false;
      $update_recurring = true;
      $old = $this->get_event($event['id']);
      
      // modify a recurring event, check submitted savemode to do the right things
      if ($old['recurrence'] || $old['recurrence_id']) {
        $master = $old['recurrence_id'] ? $this->get_event($old['recurrence_id']) : $old;
        
        // keep saved exceptions (not submitted by the client)
        if ($old['recurrence']['EXDATE'])
          $event['recurrence']['EXDATE'] = $old['recurrence']['EXDATE'];
        
        switch ($event['savemode']) {
          case 'new':
            $event['uid'] = $this->cal->generate_uid();
            return $this->new_event($event);
          
          case 'current':
            // add exception to master event
            $master['recurrence']['EXDATE'][] = $old['start'];
            $update_master = true;
            
            // just update this occurence (decouple from master)
            $update_recurring = false;
            $event['recurrence_id'] = 0;
            $event['recurrence'] = array();
            break;
          
          case 'future':
            if ($master['id'] != $event['id']) {
              // set until-date on master event, then save this instance as new recurring event
              $master['recurrence']['UNTIL'] = $event['start'] - 86400;
              unset($master['recurrence']['COUNT']);
              $update_master = true;
            
              // if recurrence COUNT, update value to the correct number of future occurences
              if ($event['recurrence']['COUNT']) {
                $sqlresult = $this->rc->db->query(sprintf(
                  "SELECT event_id FROM " . $this->db_events . "
                   WHERE calendar_id IN (%s)
                   AND start >= %s
                   AND recurrence_id=?",
                  $this->calendar_ids,
                  $this->rc->db->fromunixtime($event['start'])
                  ),
                  $master['id']);
                if ($count = $this->rc->db->num_rows($sqlresult))
                  $event['recurrence']['COUNT'] = $count;
              }
            
              $update_recurring = true;
              $event['recurrence_id'] = 0;
              break;
            }
            // else: 'future' == 'all' if modifying the master event
          
          default:  // 'all' is default
            $event['id'] = $master['id'];
            $event['recurrence_id'] = 0;
            
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
            }
            break;
        }
      }
      
      $success = $this->_update_event($event, $update_recurring);
      if ($success && $update_master)
        $this->_update_event($master, true);
      
      return $success;
    }
    
    return false;
  }

  /**
   * Convert save data to be used in SQL statements
   */
  private function _save_preprocess($event)
  {
    // compose vcalendar-style recurrencue rule from structured data
    $rrule = $event['recurrence'] ? calendar::to_rrule($event['recurrence']) : '';
    $event['_exdates'] = (array)$event['recurrence']['EXDATE'];
    $event['recurrence'] = rtrim($rrule, ';');
    $event['free_busy'] = intval($this->free_busy_map[strtolower($event['free_busy'])]);
    
    if (isset($event['allday'])) {
      $event['all_day'] = $event['allday'] ? 1 : 0;
    }
    
    // compute absolute time to notify the user
    $event['notifyat'] = $this->_get_notification($event);
    
    // process event attendees
    $_attendees = '';
    foreach ((array)$event['attendees'] as $attendee) {
      $_attendees .= 'NAME="'.addcslashes($attendee['name'], '"') . '"' .
        ';STATUS=' . $attendee['status'].
        ';ROLE=' . $attendee['role'] .
        ';EMAIL=' . $attendee['email'] .
        "\n";
    }
    $event['attendees'] = rtrim($_attendees);

    return $event;
  }
  
  /**
   * Compute absolute time to notify the user
   */
  private function _get_notification($event)
  {
    if ($event['alarms']) {
      list($trigger, $action) = explode(':', $event['alarms']);
      $notify = calendar::parse_alaram_value($trigger);
      if (!empty($notify[1])){  // offset
        $mult = 1;
        switch ($notify[1]) {
          case '-M': $mult =    -60; break;
          case '+M': $mult =     60; break;
          case '-H': $mult =  -3600; break;
          case '+H': $mult =   3600; break;
          case '-D': $mult = -86400; break;
          case '+D': $mult =  86400; break;
        }
        $offset = $notify[0] * $mult;
        $refdate = $mult > 0 ? $event['end'] : $event['start'];
        $notify_at = $refdate + $offset;
      }
      else {  // absolute timestamp
        $notify_at = $notify[0];
      }

      if ($event['start'] > time())
        return date('Y-m-d H:i:s', $notify_at);
    }
    
    return null;
  }

  /**
   * Save the given event record to database
   *
   * @param array Event data, already passed through self::_save_preprocess()
   * @param boolean True if recurring events instances should be updated, too
   */
  private function _update_event($event, $update_recurring = true)
  {
    $event = $this->_save_preprocess($event);
    console($event);
    $sql_set = array();
    $set_cols = array('all_day', 'recurrence', 'recurrence_id', 'title', 'description', 'location', 'categories', 'free_busy', 'priority', 'sensitivity', 'attendees', 'alarms', 'notifyat');
    foreach ($set_cols as $col) {
      if (isset($event[$col]))
        $sql_set[] = $this->rc->db->quote_identifier($col) . '=' . $this->rc->db->quote($event[$col]);
    }
    
    $query = $this->rc->db->query(sprintf(
      "UPDATE " . $this->db_events . "
       SET   changed=%s, start=%s, end=%s %s
       WHERE event_id=?
       AND   calendar_id IN (" . $this->calendar_ids . ")",
        $this->rc->db->now(),
        $this->rc->db->fromunixtime($event['start']),
        $this->rc->db->fromunixtime($event['end']),
        ($sql_set ? ', ' . join(', ', $sql_set) : '')
      ),
      $event['id']
    );

    $success = $this->rc->db->affected_rows($query);

    // add attachments
    if ($success && !empty($event['attachments'])) {
      foreach ($event['attachments'] as $attachment) {
        $this->add_attachment($attachment, $event['id']);
        unset($attachment);
      }
    }

    // remove attachments
    if ($success && !empty($event['deleted_attachments'])) {
      foreach ($event['deleted_attachments'] as $attachment) {
        $this->remove_attachment($attachment, $event['id']);
      }
    }

    if ($success && $update_recurring)
      $this->_update_recurring($event);

    return $success;
  }

  /**
   * Insert "fake" entries for recurring occurences of this event
   */
  private function _update_recurring($event)
  {
    if (empty($this->calendars))
      return;
    
    // clear existing recurrence copies
    $this->rc->db->query(
      "DELETE FROM " . $this->db_events . "
       WHERE recurrence_id=?
       AND calendar_id IN (" . $this->calendar_ids . ")",
       $event['id']
    );
    
    // create new fake entries
    if ($event['recurrence']) {
      // TODO: replace Horde classes with something that has less than 6'000 lines of code
      $recurrence = new Horde_Date_Recurrence($event['start']);
      $recurrence->fromRRule20($event['recurrence']);
      
      foreach ((array)$event['_exdates'] as $exdate)
        $recurrence->addException(date('Y', $exdate), date('n', $exdate), date('j', $exdate));
      
      $duration = $event['end'] - $event['start'];
      $next = new Horde_Date($event['start']);
      while ($next = $recurrence->nextActiveRecurrence(array('year' => $next->year, 'month' => $next->month, 'mday' => $next->mday + 1, 'hour' => $next->hour, 'min' => $next->min, 'sec' => $next->sec))) {
        $next_ts = $next->timestamp();
        $notify_at = $this->_get_notification(array('alarms' => $event['alarms'], 'start' => $next_ts, 'end' => $next_ts + $duration));
        $query = $this->rc->db->query(sprintf(
          "INSERT INTO " . $this->db_events . "
           (calendar_id, recurrence_id, created, changed, uid, start, end, all_day, recurrence, title, description, location, categories, free_busy, priority, sensitivity, alarms, notifyat)
            SELECT calendar_id, ?, %s, %s, uid, %s, %s, all_day, recurrence, title, description, location, categories, free_busy, priority, sensitivity, alarms, ?
            FROM  " . $this->db_events . " WHERE event_id=? AND calendar_id IN (" . $this->calendar_ids . ")",
            $this->rc->db->now(),
            $this->rc->db->now(),
            $this->rc->db->fromunixtime($next_ts),
            $this->rc->db->fromunixtime($next_ts + $duration)
          ),
          $event['id'],
          $notify_at,
          $event['id']
        );
        
        if (!$this->rc->db->affected_rows($query))
          break;
        
        // stop adding events for inifinite recurrence after 20 years
        if (++$count > 999 || (!$recurrence->recurEnd && !$recurrence->recurCount && $next->year > date('Y') + 20))
          break;
      }
    }
  }

  /**
   * Move a single event
   *
   * @param array Hash array with event properties
   * @see Driver:move_event()
   */
  public function move_event($event)
  {
    // let edit_event() do all the magic
    return $this->edit_event($event + (array)$this->get_event($event['id']));
  }

  /**
   * Resize a single event
   *
   * @param array Hash array with event properties
   * @see Driver:resize_event()
   */
  public function resize_event($event)
  {
    // let edit_event() do all the magic
    return $this->edit_event($event + (array)$this->get_event($event['id']));
  }

  /**
   * Remove a single event from the database
   *
   * @param array   Hash array with event properties
   * @param boolean Remove record irreversible (@TODO)
   *
   * @see Driver:remove_event()
   */
  public function remove_event($event, $force = true)
  {
    if (!empty($this->calendars)) {
      $event += (array)$this->get_event($event['id']);
      $master = $event;
      $update_master = false;
      $savemode = 'all';

      // read master if deleting a recurring event
      if ($event['recurrence'] || $event['recurrence_id']) {
        $master = $event['recurrence_id'] ? $this->get_event($old['recurrence_id']) : $event;
        $savemode = $event['savemode'];
      }

      switch ($savemode) {
        case 'current':
          // add exception to master event
          $master['recurrence']['EXDATE'][] = $event['start'];
          $update_master = true;
          
          // just delete this single occurence
          $query = $this->rc->db->query(
            "DELETE FROM " . $this->db_events . "
             WHERE calendar_id IN (" . $this->calendar_ids . ")
             AND event_id=?",
            $event['id']
          );
          break;

        case 'future':
          if ($master['id'] != $event['id']) {
            // set until-date on master event
            $master['recurrence']['UNTIL'] = $event['start'] - 86400;
            unset($master['recurrence']['COUNT']);
            $update_master = true;
            
            // delete this and all future instances
            $query = $this->rc->db->query(
              "DELETE FROM " . $this->db_events . "
               WHERE calendar_id IN (" . $this->calendar_ids . ")
               AND start >= " . $this->rc->db->fromunixtime($old['start']) . "
               AND recurrence_id=?",
              $master['id']
            );
            break;
          }
          // else: future == all if modifying the master event

        default:  // 'all' is default
          $query = $this->rc->db->query(
            "DELETE FROM " . $this->db_events . "
             WHERE (event_id=? OR recurrence_id=?)
             AND calendar_id IN (" . $this->calendar_ids . ")",
             $master['id'],
             $master['id']
          );
          break;
      }

      $success = $this->rc->db->affected_rows($query);
      if ($success && $update_master)
        $this->_update_event($master, true);

      return $success;
    }
    
    return false;
  }

  /**
   * Return data of a specific event
   * @param string Event ID
   * @return array Hash array with event properties
   */
  public function get_event($id)
  {
    static $cache = array();
    
    if ($cache[$id])
      return $cache[$id];
    
    $result = $this->rc->db->query(sprintf(
      "SELECT * FROM " . $this->db_events . "
       WHERE calendar_id IN (%s)
       AND event_id=?",
       $this->calendar_ids
      ),
      $id);

    if ($result && ($event = $this->rc->db->fetch_assoc($result))) {
      $cache[$id] = $this->_read_postprocess($event);
      return $cache[$id];
    }

    return false;
  }

  /**
   * Get event data
   *
   * @see Driver:load_events()
   */
  public function load_events($start, $end, $query = null, $calendars = null)
  {
    if (empty($calendars))
      $calendars = array_keys($this->calendars);
    else if (is_string($calendars))
      $calendars = explode(',', $calendars);
      
    // only allow to select from calendars of this use
    $calendar_ids = array_map(array($this->rc->db, 'quote'), array_intersect($calendars, array_keys($this->calendars)));
    
    // compose (slow) SQL query for searching
    // FIXME: improve searching using a dedicated col and normalized values
    if ($query) {
      foreach (array('title','location','description','categories','attendees') as $col)
        $sql_query[] = $this->rc->db->ilike($col, '%'.$query.'%');
      $sql_add = 'AND (' . join(' OR ', $sql_query) . ')';
    }
    
    $events = array();
    if (!empty($calendar_ids)) {
      $result = $this->rc->db->query(sprintf(
        "SELECT e.*, COUNT(a.attachment_id) AS _attachments FROM " . $this->db_events . " AS e
         LEFT JOIN " . $this->db_attachments . " AS a ON (a.event_id = e.event_id OR a.event_id = e.recurrence_id)
         WHERE e.calendar_id IN (%s)
         AND e.start <= %s AND e.end >= %s
         %s
         GROUP BY e.event_id",
         join(',', $calendar_ids),
         $this->rc->db->fromunixtime($end),
         $this->rc->db->fromunixtime($start),
         $sql_add
       ));

      while ($result && ($event = $this->rc->db->fetch_assoc($result))) {
        $events[] = $this->_read_postprocess($event);
      }
    }
    
    return $events;
  }

  /**
   * Convert sql record into a rcube style event object
   */
  private function _read_postprocess($event)
  {
    $free_busy_map = array_flip($this->free_busy_map);
    
    $event['id'] = $event['event_id'];
    $event['start'] = strtotime($event['start']);
    $event['end'] = strtotime($event['end']);
    $event['allday'] = intval($event['all_day']);
    $event['free_busy'] = $free_busy_map[$event['free_busy']];
    $event['calendar'] = $event['calendar_id'];
    $event['recurrence_id'] = intval($event['recurrence_id']);
    
    // parse recurrence rule
    if ($event['recurrence'] && preg_match_all('/([A-Z]+)=([^;]+);?/', $event['recurrence'], $m, PREG_SET_ORDER)) {
      $event['recurrence'] = array();
      foreach ($m as $rr) {
        if (is_numeric($rr[2]))
          $rr[2] = intval($rr[2]);
        else if ($rr[1] == 'UNTIL')
          $rr[2] = strtotime($rr[2]);
        else if ($rr[1] == 'EXDATE')
          $rr[2] = array_map('strtotime', explode(',', $rr[2]));
        $event['recurrence'][$rr[1]] = $rr[2];
      }
    }
    
    if ($event['_attachments'] > 0)
      $event['attachments'] = (array)$this->list_attachments($event);
    
    // decode serialized event attendees
    if ($event['attendees']) {
      $attendees = array();
      foreach (explode("\n", $event['attendees']) as $line) {
        $att = array();
        foreach (rcube_explode_quoted_string(';', $line) as $prop) {
          list($key, $value) = explode("=", $prop);
          $att[strtolower($key)] = stripslashes(trim($value, '""'));
        }
        $attendees[] = $att;
      }
      $event['attendees'] = $attendees;
    }

    unset($event['event_id'], $event['calendar_id'], $event['notifyat'], $event['_attachments']);
    return $event;
  }

  /**
   * Get a list of pending alarms to be displayed to the user
   *
   * @see Driver:pending_alarms()
   */
  public function pending_alarms($time, $calendars = null)
  {
    if (empty($calendars))
      $calendars = array_keys($this->calendars);
    else if (is_string($calendars))
      $calendars = explode(',', $calendars);
    
    // only allow to select from calendars of this use
    $calendar_ids = array_map(array($this->rc->db, 'quote'), array_intersect($calendars, array_keys($this->calendars)));
    
    $alarms = array();
    if (!empty($calendar_ids)) {
      $result = $this->rc->db->query(sprintf(
        "SELECT * FROM " . $this->db_events . "
         WHERE calendar_id IN (%s)
         AND notifyat <= %s AND end > %s",
         join(',', $calendar_ids),
         $this->rc->db->fromunixtime($time),
         $this->rc->db->fromunixtime($time)
       ));

      while ($result && ($event = $this->rc->db->fetch_assoc($result)))
        $alarms[] = $this->_read_postprocess($event);
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
    $notify_at = $snooze > 0 ? date('Y-m-d H:i:s', time() + $snooze) : null;
    
    $query = $this->rc->db->query(sprintf(
      "UPDATE " . $this->db_events . "
       SET   changed=%s, notifyat=?
       WHERE event_id=?
       AND calendar_id IN (" . $this->calendar_ids . ")",
        $this->rc->db->now()),
      $notify_at,
      $event_id
    );
    
    return $this->rc->db->affected_rows($query);
  }

  /**
   * Save an attachment related to the given event
   */
  private function add_attachment($attachment, $event_id)
  {
    $data = $attachment['data'] ? $attachment['data'] : file_get_contents($attachment['path']);
    
    $query = $this->rc->db->query(
      "INSERT INTO " . $this->db_attachments .
      " (event_id, filename, mimetype, size, data)" .
      " VALUES (?, ?, ?, ?, ?)",
      $event_id,
      $attachment['name'],
      $attachment['mimetype'],
      strlen($data),
      base64_encode($data)
    );

    return $this->rc->db->affected_rows($query);
  }

  /**
   * Remove a specific attachment from the given event
   */
  private function remove_attachment($attachment_id, $event_id)
  {
    $query = $this->rc->db->query(
      "DELETE FROM " . $this->db_attachments .
      " WHERE attachment_id = ?" .
        " AND event_id IN (SELECT event_id FROM " . $this->db_events .
          " WHERE event_id = ?"  .
            " AND calendar_id IN (" . $this->calendar_ids . "))",
      $attachment_id,
      $event_id
    );

    return $this->rc->db->affected_rows($query);
  }

  /**
   * List attachments of specified event
   */
  public function list_attachments($event)
  {
    $attachments = array();
    $event_id = $event['recurrence_id'] ? $event['recurrence_id'] : $event['event_id'];

    if (!empty($this->calendar_ids)) {
      $result = $this->rc->db->query(
        "SELECT attachment_id AS id, filename AS name, mimetype, size " .
        " FROM " . $this->db_attachments .
        " WHERE event_id IN (SELECT event_id FROM " . $this->db_events .
          " WHERE event_id=?"  .
            " AND calendar_id IN (" . $this->calendar_ids . "))".
        " ORDER BY filename",
        $event['recurrence_id'] ? $event['recurrence_id'] : $event['event_id']
      );

      while ($result && ($arr = $this->rc->db->fetch_assoc($result))) {
        $attachments[] = $arr;
      }
    }

    return $attachments;
  }

  /**
   * Get attachment properties
   */
  public function get_attachment($id, $event)
  {
    if (!empty($this->calendar_ids)) {
      $result = $this->rc->db->query(
        "SELECT attachment_id AS id, filename AS name, mimetype, size " .
        " FROM " . $this->db_attachments .
        " WHERE attachment_id=?".
          " AND event_id=?",
        $id,
        $event['recurrence_id'] ? $event['recurrence_id'] : $event['id']
      );

      if ($result && ($arr = $this->rc->db->fetch_assoc($result))) {
        return $arr;
      }
    }

    return null;
  }

  /**
   * Get attachment body
   */
  public function get_attachment_body($id, $event)
  {
    if (!empty($this->calendar_ids)) {
      $result = $this->rc->db->query(
        "SELECT data " .
        " FROM " . $this->db_attachments .
        " WHERE attachment_id=?".
          " AND event_id=?",
        $id,
        $event['id']
      );

      if ($result && ($arr = $this->rc->db->fetch_assoc($result))) {
        return base64_decode($arr['data']);
      }
    }

    return null;
  }

  /**
   * Remove the given category
   */
  public function remove_category($name)
  {
    $query = $this->rc->db->query(
      "UPDATE " . $this->db_events . "
       SET   categories=''
       WHERE categories=?
       AND   calendar_id IN (" . $this->calendar_ids . ")",
      $name
    );
    
    return $this->rc->db->affected_rows($query);
  }

  /**
   * Update/replace a category
   */
  public function replace_category($oldname, $name, $color)
  {
    $query = $this->rc->db->query(
      "UPDATE " . $this->db_events . "
       SET   categories=?
       WHERE categories=?
       AND   calendar_id IN (" . $this->calendar_ids . ")",
      $name,
      $oldname
    );
    
    return $this->rc->db->affected_rows($query);
  }

}
