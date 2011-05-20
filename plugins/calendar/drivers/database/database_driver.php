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
  public $attendees = true;
  public $attachments = true;

  private $rc;
  private $cal;
  private $calendars = array();
  private $calendar_ids = '';
  private $free_busy_map = array('free' => 0, 'busy' => 1, 'out-of-office' => 2, 'outofoffice' => 2);

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
   * Read available calendars for the current user and store them internally
   */
  private function _read_calendars()
  {
    if (!empty($this->rc->user->ID)) {
      $calendar_ids = array();
      $result = $this->rc->db->query(
        "SELECT * FROM calendars 
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
      "INSERT INTO calendars 
       (user_id, name, color)
       VALUES (?, ?, ?)",
       $this->rc->user->ID,
       $prop['name'],
       $prop['color']
    );
    
    if ($result)
      return $this->rc->db->insert_id('calendars');
    
    return false;
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
        "INSERT INTO events
         (calendar_id, created, changed, uid, start, end, all_day, recurrence, title, description, location, categories, free_busy, priority, alarms)
         VALUES (?, %s, %s, ?, %s, %s, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
          $this->rc->db->now(),
          $this->rc->db->now(),
          $this->rc->db->fromunixtime($event['start']),
          $this->rc->db->fromunixtime($event['end'])
        ),
        $event['calendar'],
        strval($event['uid']),
        intval($event['allday']),
        $event['recurrence'],
        strval($event['title']),
        strval($event['description']),
        strval($event['location']),
        strval($event['categories']),
        intval($event['free_busy']),
        intval($event['priority']),
        $event['alarms']
      );
      return $this->rc->db->insert_id('events');
    }
    
    return false;
  }

  /**
   * Update an event entry with the given data
   *
   * @param array Hash array with event properties
   * @see Driver:new_event()
   */
  public function edit_event($event)
  {
    if (!empty($this->calendars)) {
      $event = $this->_save_preprocess($event);
      $query = $this->rc->db->query(sprintf(
        "UPDATE events
         SET   changed=%s, start=%s, end=%s, all_day=?, recurrence=?, title=?, description=?, location=?, categories=?, free_busy=?, priority=?, alarms=?
         WHERE event_id=?
         AND   calendar_id IN (" . $this->calendar_ids . ")",
          $this->rc->db->now(),
          $this->rc->db->fromunixtime($event['start']),
          $this->rc->db->fromunixtime($event['end'])
        ),
        intval($event['allday']),
        $event['recurrence'],
        strval($event['title']),
        strval($event['description']),
        strval($event['location']),
        strval($event['categories']),
        intval($event['free_busy']),
        intval($event['priority']),
        $event['alarms'],
        $event['id']
      );
      return $this->rc->db->affected_rows($query);
    }
    
    return false;
  }

  /**
   * Convert save data to be used in SQL statements
   */
  private function _save_preprocess($event)
  {
    // compose vcalendar-style recurrencue rule from structured data
    $rrule = '';
    if (is_array($event['recurrence'])) {
      foreach ($event['recurrence'] as $k => $val) {
        $k = strtoupper($k);
        switch ($k) {
          case 'UNTIL':
            $val = gmdate('Ymd\THis', $val);
            break;
        }
        $rrule .= $k . '=' . $val . ';';
      }
    }
    else if (is_string($event['recurrence']))
      $rrule = $event['recurrence'];
      
    $event['recurrence'] = rtrim($rrule, ';');
    $event['free_busy'] = intval($this->free_busy_map[strtolower($event['free_busy'])]);
    $event['allday'] = $event['allday'] ? 1 : 0;
    
    return $event;
  }

  /**
   * Move a single event
   *
   * @param array Hash array with event properties
   * @see Driver:move_event()
   */
  public function move_event($event)
  {
    if (!empty($this->calendars)) {
      $query = $this->rc->db->query(sprintf(
        "UPDATE events 
         SET   changed=%s, start=%s, end=%s, all_day=?
         WHERE event_id=?
         AND calendar_id IN (" . $this->calendar_ids . ")",
          $this->rc->db->now(),
          $this->rc->db->fromunixtime($event['start']),
          $this->rc->db->fromunixtime($event['end'])
        ),
        $event['allday'] ? 1 : 0,
        $event['id']
      );
      return $this->rc->db->affected_rows($query);
    }
    
    return false;
  }

  /**
   * Resize a single event
   *
   * @param array Hash array with event properties
   * @see Driver:resize_event()
   */
  public function resize_event($event)
  {
    if (!empty($this->calendars)) {
      $query = $this->rc->db->query(sprintf(
        "UPDATE events 
         SET   changed=%s, start=%s, end=%s
         WHERE event_id=?
         AND calendar_id IN (" . $this->calendar_ids . ")",
          $this->rc->db->now(),
          $this->rc->db->fromunixtime($event['start']),
          $this->rc->db->fromunixtime($event['end'])
        ),
        $event['id']
      );
      return $this->rc->db->affected_rows($query);
    }
    
    return false;
  }

  /**
   * Remove a single event from the database
   *
   * @param array Hash array with event properties
   * @see Driver:remove_event()
   */
  public function remove_event($event)
  {
    if (!empty($this->calendars)) {
      $query = $this->rc->db->query(
        "DELETE FROM events
         WHERE event_id=?
         AND calendar_id IN (" . $this->calendar_ids . ")",
         $event['id']
      );
      return $this->rc->db->affected_rows($query);
    }
    
    return false;
  }

  /**
   * Get event data
   *
   * @see Driver:load_events()
   */
  public function load_events($start, $end, $calendars = null)
  {
    if (empty($calendars))
      $calendars = array_keys($this->calendars);
    else if (is_string($calendars))
      $calendars = explode(',', $calendars);
    
    // only allow to select from calendars of this use
    $calendars = array_intersect($calendars, array_keys($this->calendars));
    
    $events = array();
    $free_busy_map = array_flip($this->free_busy_map);
    
    if (!empty($calendars)) {
      $result = $this->rc->db->query(sprintf(
        "SELECT * FROM events 
         WHERE calendar_id IN (%s)
         AND start >= %s AND end <= %s",
         $this->calendar_ids,
         $this->rc->db->fromunixtime($start),
         $this->rc->db->fromunixtime($end)
       ));

      while ($result && ($event = $this->rc->db->fetch_assoc($result))) {
        $event['id'] = $event['event_id'];
        $event['start'] = strtotime($event['start']);
        $event['end'] = strtotime($event['end']);
        $event['free_busy'] = $free_busy_map[$event['free_busy']];
        $event['calendar'] = $event['calendar_id'];
        
        // parse recurrence rule
        if ($event['recurrence'] && preg_match_all('/([A-Z]+)=([^;]+);?/', $event['recurrence'], $m, PREG_SET_ORDER)) {
          $event['recurrence'] = array();
          foreach ($m as $rr) {
            if (is_numeric($rr[2]))
              $rr[2] = intval($rr[2]);
            else if ($rr[1] == 'UNTIL')
              $rr[2] = strtotime($rr[2]);
            $event['recurrence'][$rr[1]] = $rr[2];
          }
        }
        
        unset($event['event_id'], $event['calendar_id']);
        $events[] = $event;
      }
    }
    
    return $events;
  }

  /**
   * Search events
   *
   * @see Driver:search_events()
   */
  public function search_events($start, $end, $query, $calendars = null)
  {
    
  }

  /**
   * Save an attachment related to the given event
   */
  function add_attachment($attachment, $event_id)
  {
    // TBD.
    return false;
  }

  /**
   * Remove a specific attachment from the given event
   */
  function remove_attachment($attachment, $event_id)
  {
    // TBD.
    return false;
  }

  /**
   * Remove the given category
   */
  public function remove_category($name)
  {
    // TBD. alter events accordingly
    return false;
  }

  /**
   * Update/replace a category
   */
  public function replace_category($oldname, $name, $color)
  {
    // TBD. alter events accordingly
    return false;
  }

}
