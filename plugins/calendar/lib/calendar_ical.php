<?php

/**
 * iCalendar functions for the Calendar plugin
 *
 * @version @package_version@
 * @author Lazlo Westerhof <hello@lazlo.me>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Bogomil "Bogo" Shopov <shopov@kolabsys.com>
 *
 * Copyright (C) 2010, Lazlo Westerhof <hello@lazlo.me>
 * Copyright (C) 2011, Kolab Systems AG <contact@kolabsys.com>
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


/**
 * Class to parse and build vCalendar (iCalendar) files
 *
 * Uses the Horde:iCalendar class for parsing. To install:
 * > pear channel-discover pear.horde.org
 * > pear install horde/Horde_Icalendar
 *
 */
class calendar_ical
{
  const EOL = "\r\n";
  
  private $rc;
  private $cal;

  public $method;
  public $events = array();

  function __construct($cal)
  {
    $this->cal = $cal;
    $this->rc = $cal->rc;
  }

  /**
   * Import events from iCalendar format
   *
   * @param  string vCalendar input
   * @param  string Input charset (from envelope)
   * @return array List of events extracted from the input
   */
  public function import($vcal, $charset = RCMAIL_CHARSET)
  {
    $parser = $this->get_parser();
    $parser->parsevCalendar($vcal, 'VCALENDAR', $charset);
    $this->method = $parser->getAttributeDefault('METHOD', '');
    $this->events = $seen = array();
    if ($data = $parser->getComponents()) {
      foreach ($data as $comp) {
        if ($comp->getType() == 'vEvent') {
          $event = $this->_to_rcube_format($comp);
          if (!$seen[$event['uid']]++)
            $this->events[] = $event;
        }
      }
    }
    
    return $this->events;
  }

  /**
   * Read iCalendar events from a file
   *
   * @param string File path to read from
   * @return array List of events extracted from the file
   */
  public function import_from_file($filepath)
  {
    $this->events = $seen = array();
    $fp = fopen($filepath, 'r');

    // check file content first
    $begin = fread($fp, 1024);
    if (!preg_match('/BEGIN:VCALENDAR/i', $begin))
      return $this->events;

    $parser = $this->get_parser();
    $buffer = '';

    fseek($fp, 0);
    while (($line = fgets($fp, 2048)) !== false) {
      $buffer .= $line;
      if (preg_match('/END:VEVENT/i', $line)) {
        $parser->parsevCalendar($buffer, 'VCALENDAR', RCMAIL_CHARSET, false);
        $buffer = '';
      }
    }
    fclose($fp);

    if ($data = $parser->getComponents()) {
      foreach ($data as $comp) {
        if ($comp->getType() == 'vEvent') {
          $event = $this->_to_rcube_format($comp);
          if (!$seen[$event['uid']]++)
            $this->events[] = $event;
        }
      }
    }

    return $this->events;
  }

  /**
   * Load iCal parser from the Horde lib
   */
  public function get_parser()
  {
    // use Horde:iCalendar to parse vcalendar file format
    // @include_once('Horde/iCalendar.php');

    if (!class_exists('Horde_iCalendar'))
      require_once($this->cal->home . '/lib/Horde_iCalendar.php');

    // set target charset for parsed events
    $GLOBALS['_HORDE_STRING_CHARSET'] = RCMAIL_CHARSET;

    return new Horde_iCalendar;
  }

  /**
   * Convert the given File_IMC_Parse_Vcalendar_Event object to the internal event format
   */
  private function _to_rcube_format($ve)
  {
    $event = array(
      'uid' => $ve->getAttributeDefault('UID'),
      'changed' => $ve->getAttributeDefault('DTSTAMP', 0),
      'title' => $ve->getAttributeDefault('SUMMARY'),
      'start' => $this->_date2time($ve->getAttribute('DTSTART')),
      'end' => $this->_date2time($ve->getAttribute('DTEND')),
      // set defaults
      'free_busy' => 'busy',
      'priority' => 0,
    );

    // check for all-day dates
    if (is_array($ve->getAttribute('DTSTART')))
      $event['allday'] = true;

    if ($event['allday'])
      $event['end']->sub(new DateInterval('PT23H'));

    // assign current timezone to event start/end
    $event['start']->setTimezone($this->cal->timezone);
    $event['end']->setTimezone($this->cal->timezone);

    // map other attributes to internal fields
    $_attendees = array();
    foreach ($ve->getAllAttributes() as $attr) {
      switch ($attr['name']) {
        case 'ORGANIZER':
          $organizer = array(
            'name' => $attr['params']['CN'],
            'email' => preg_replace('/^mailto:/i', '', $attr['value']),
            'role' => 'ORGANIZER',
            'status' => 'ACCEPTED',
          );
          if (isset($_attendees[$organizer['email']])) {
            $i = $_attendees[$organizer['email']];
            $event['attendees'][$i]['role'] = $organizer['role'];
          }
          break;
        
        case 'ATTENDEE':
          $attendee = array(
            'name' => $attr['params']['CN'],
            'email' => preg_replace('/^mailto:/i', '', $attr['value']),
            'role' => $attr['params']['ROLE'] ? $attr['params']['ROLE'] : 'REQ-PARTICIPANT',
            'status' => $attr['params']['PARTSTAT'],
            'rsvp' => $attr['params']['RSVP'] == 'TRUE',
          );
          if ($organizer && $organizer['email'] == $attendee['email'])
            $attendee['role'] = 'ORGANIZER';
          
          $event['attendees'][] = $attendee;
          $_attendees[$attendee['email']] = count($event['attendees']) - 1;
          break;
          
        case 'TRANSP':
          $event['free_busy'] = $attr['value'] == 'TRANSPARENT' ? 'free' : 'busy';
          break;
        
        case 'STATUS':
          if ($attr['value'] == 'TENTATIVE')
            $event['free_busy'] == 'tentative';
          break;
        
        case 'PRIORITY':
          if (is_numeric($attr['value'])) {
            $event['priority'] = $attr['value'];
          }
          break;
        
        case 'RRULE':
          // parse recurrence rule attributes
          foreach (explode(';', $attr['value']) as $par) {
            list($k, $v) = explode('=', $par);
            $params[$k] = $v;
          }
          if ($params['UNTIL'])
            $params['UNTIL'] = date_create($params['UNTIL']);
          if (!$params['INTERVAL'])
            $params['INTERVAL'] = 1;
          
          $event['recurrence'] = $params;
          break;
        
        case 'EXDATE':
          break;
          
        case 'RECURRENCE-ID':
          $event['recurrence_id'] = $this->_date2time($attr['value']);
          break;
        
        case 'SEQUENCE':
          $event['sequence'] = intval($attr['value']);
          break;
        
        case 'DESCRIPTION':
        case 'LOCATION':
          $event[strtolower($attr['name'])] = $attr['value'];
          break;
        
        case 'CLASS':
        case 'X-CALENDARSERVER-ACCESS':
          $sensitivity_map = array('PUBLIC' => 0, 'PRIVATE' => 1, 'CONFIDENTIAL' => 2);
          $event['sensitivity'] = $sensitivity_map[$attr['value']];
          break;

        case 'X-MICROSOFT-CDO-BUSYSTATUS':
          if ($attr['value'] == 'OOF')
            $event['free_busy'] == 'outofoffice';
          else if (in_array($attr['value'], array('FREE', 'BUSY', 'TENTATIVE')))
            $event['free_busy'] = strtolower($attr['value']);
          break;
      }
    }

    // find alarms
    if ($valarm = $ve->findComponent('valarm')) {
      $action = 'DISPLAY';
      $trigger = null;
      
      foreach ($valarm->getAllAttributes() as $attr) {
        switch ($attr['name']) {
          case 'TRIGGER':
            if ($attr['params']['VALUE'] == 'DATE-TIME') {
              $trigger = '@' . $attr['value'];
            }
            else {
              $trigger = $attr['value'];
              $offset = abs($trigger);
              $unit = 'S';
              if ($offset % 86400 == 0) {
                $unit = 'D';
                $trigger = intval($trigger / 86400);
              }
              else if ($offset % 3600 == 0) {
                $unit = 'H';
                $trigger = intval($trigger / 3600);
              }
              else if ($offset % 60 == 0) {
                $unit = 'M';
                $trigger = intval($trigger / 60);
              }
            }
            break;

          case 'ACTION':
            $action = $attr['value'];
            break;
        }
      }
      if ($trigger)
        $event['alarms'] = $trigger . $unit . ':' . $action;
    }

    // add organizer to attendees list if not already present
    if ($organizer && !isset($_attendees[$organizer['email']]))
      array_unshift($event['attendees'], $organizer);

    // make sure the event has an UID
    if (!$event['uid'])
      $event['uid'] = $this->cal->$this->generate_uid();
    
    return $event;
  }
  
  /**
   * Helper method to correctly interpret an all-day date value
   */
  private function _date2time($prop)
  {
    // create timestamp at 12:00 in user's timezone
    if (is_array($prop))
      return date_create(sprintf('%04d%02d%02dT120000', $prop['year'], $prop['month'], $prop['mday']), $this->cal->timezone);
    else if (is_numeric($prop))
      return date_create('@'.$prop);
    
    return $prop;
  }


  /**
   * Free resources by clearing member vars
   */
  public function reset()
  {
    $this->method = '';
    $this->events = array();
  }

  /**
   * Export events to iCalendar format
   *
   * @param  array   Events as array
   * @param  string  VCalendar method to advertise
   * @param  boolean Directly send data to stdout instead of returning
   * @return string  Events in iCalendar format (http://tools.ietf.org/html/rfc5545)
   */
  public function export($events, $method = null, $write = false)
  {
      $ical = "BEGIN:VCALENDAR" . self::EOL;
      $ical .= "VERSION:2.0" . self::EOL;
      $ical .= "PRODID:-//Roundcube Webmail " . RCMAIL_VERSION . "//NONSGML Calendar//EN" . self::EOL;
      $ical .= "CALSCALE:GREGORIAN" . self::EOL;
      
      if ($method)
        $ical .= "METHOD:" . strtoupper($method) . self::EOL;
        
      if ($write) {
        echo $ical;
        $ical = '';
      }
      
      foreach ($events as $event) {
        $vevent = "BEGIN:VEVENT" . self::EOL;
        $vevent .= "UID:" . self::escpape($event['uid']) . self::EOL;
        $vevent .= $this->format_datetime("DTSTAMP", $event['changed'] ?: new DateTime(), false, true) . self::EOL;
        // correctly set all-day dates
        if ($event['allday']) {
          $event['end'] = clone $event['end'];
          $event['end']->add(new DateInterval('P1D'));  // ends the next day
          $vevent .= $this->format_datetime("DTSTART", $event['start'], true) . self::EOL;
          $vevent .= $this->format_datetime("DTEND",   $event['end'], true) . self::EOL;
        }
        else {
          $vevent .= $this->format_datetime("DTSTART", $event['start'], false) . self::EOL;
          $vevent .= $this->format_datetime("DTEND",   $event['end'], false) . self::EOL;
        }
        $vevent .= "SUMMARY:" . self::escpape($event['title']) . self::EOL;
        $vevent .= "DESCRIPTION:" . self::escpape($event['description']) . self::EOL;
        
        if (!empty($event['attendees'])){
          $vevent .= $this->_get_attendees($event['attendees']);
        }

        if (!empty($event['location'])) {
          $vevent .= "LOCATION:" . self::escpape($event['location']) . self::EOL;
        }
        if ($event['recurrence']) {
          $vevent .= "RRULE:" . libcalendaring::to_rrule($event['recurrence'], self::EOL) . self::EOL;
        }
        if(!empty($event['categories'])) {
          $vevent .= "CATEGORIES:" . self::escpape(strtoupper($event['categories'])) . self::EOL;
        }
        if ($event['sensitivity'] > 0) {
          $vevent .= "CLASS:" . ($event['sensitivity'] == 2 ? 'CONFIDENTIAL' : 'PRIVATE') . self::EOL;
        }
        if ($event['alarms']) {
          list($trigger, $action) = explode(':', $event['alarms']);
          $val = libcalendaring::parse_alaram_value($trigger);
          
          $vevent .= "BEGIN:VALARM\n";
          if ($val[1]) $vevent .= "TRIGGER:" . preg_replace('/^([-+])(.+)/', '\\1PT\\2', $trigger) . self::EOL;
          else         $vevent .= "TRIGGER;VALUE=DATE-TIME:" . gmdate('Ymd\THis\Z', $val[0]) . self::EOL;
          if ($action) $vevent .= "ACTION:" . self::escpape(strtoupper($action)) . self::EOL;
          $vevent .= "END:VALARM\n";
        }
        
        $vevent .= "TRANSP:" . ($event['free_busy'] == 'free' ? 'TRANSPARENT' : 'OPAQUE') . self::EOL;
        
        if ($event['priority']) {
          $vevent .= "PRIORITY:" . $event['priority'] . self::EOL;
        }
        
        if ($event['cancelled'])
          $vevent .= "STATUS:CANCELLED" . self::EOL;
        else if ($event['free_busy'] == 'tentative')
          $vevent .= "STATUS:TENTATIVE" . self::EOL;
        
        // TODO: export attachments
        
        $vevent .= "END:VEVENT" . self::EOL;
        
        if ($write)
          echo rcube_vcard::rfc2425_fold($vevent);
        else
          $ical .= $vevent;
      }
      
      $ical .= "END:VCALENDAR" . self::EOL;
      
      if ($write) {
        echo $ical;
        return true;
      }

      // fold lines to 75 chars
      return rcube_vcard::rfc2425_fold($ical);
  }

  private function format_datetime($attr, $dt, $dateonly = false, $utc = false)
  {
    if (is_numeric($dt))
        $dt = new DateTime('@'.$dt);

    if ($utc)
      $dt->setTimezone(new DateTimeZone('UTC'));

    if ($dateonly) {
      return $attr . ';VALUE=DATE:' . $dt->format('Ymd');
    }
    else {
      // <ATTR>;TZID=Europe/Zurich:20120706T210000
      $tz = $dt->getTimezone();
      $tzid = $tz && $tz->getName() != 'UTC' ? ';TZID=' . $tz->getName() : '';
      return $attr . $tzid . ':' . $dt->format('Ymd\THis' . ($tzid ? '' : 'Z'));
    }
  }

  private function escpape($str)
  {
    return preg_replace('/(?<!\\\\)([\:\;\,\\n\\r])/', '\\\$1', $str);
  }

  /**
  * Construct the orginizer of the event.
  * @param Array Attendees and roles
  *
  */
  private function _get_attendees($ats)
  {
    $organizer = "";
    $attendees = "";
    foreach ($ats as $at) {
      if ($at['role']=="ORGANIZER") {
        //I am an orginizer
        $organizer .= "ORGANIZER;";
        if (!empty($at['name']))
          $organizer .= 'CN="' . $at['name'] . '"';
        $organizer .= ":mailto:". $at['email'] . self::EOL;
      }
      else {
        //I am an attendee 
        $attendees .= "ATTENDEE;ROLE=" . $at['role'] . ";PARTSTAT=" . $at['status'];
        if ($at['rsvp'])
          $attendees .= ";RSVP=TRUE";
        if (!empty($at['name']))
          $attendees .= ';CN="' . $at['name'] . '"';
        $attendees .= ":mailto:" . $at['email'] . self::EOL;
      }
    }

    return $organizer . $attendees;
  }

}
