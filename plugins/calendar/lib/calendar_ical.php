<?php
/*
 +-------------------------------------------------------------------------+
 | iCalendar functions for the Calendar Plugin                             |
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
 |         Bogomil "Bogo" Shopov <shopov@kolabsys.com>                     | 
 +-------------------------------------------------------------------------+
*/

class calendar_ical
{
  const EOL = "\r\n";
  
  private $rc;
  private $driver;

  function __construct($rc, $driver)
  {
    $this->rc = $rc;
    $this->driver = $driver;
  }
    
  /**
   * Import events from iCalendar format
   *
   * @param  array Associative events array
   * @access public
   */
  public function import($events)
  {
    //TODO
    // for ($events as $event)
    //   $this->backend->newEvent(...);
  }
  
  /**
   * Export events to iCalendar format
   *
   * @param  array Events as array
   * @return string  Events in iCalendar format (http://tools.ietf.org/html/rfc5545)
   */
  public function export($events, $method = null)
  {
    if (!empty($this->rc->user->ID)) {
      $ical = "BEGIN:VCALENDAR" . self::EOL;
      $ical .= "VERSION:2.0" . self::EOL;
      $ical .= "PRODID:-//Roundcube Webmail " . RCMAIL_VERSION . "//NONSGML Calendar//EN" . self::EOL;
      $ical .= "CALSCALE:GREGORIAN" . self::EOL;
      
      if ($method)
        $ical .= "METHOD:" . strtoupper($method) . self::EOL;
      
      foreach ($events as $event) {
        $ical .= "BEGIN:VEVENT" . self::EOL;
        $ical .= "UID:" . self::escpape($event['uid']) . self::EOL;
        $ical .= "DTSTART:" . gmdate('Ymd\THis\Z', $event['start']) . self::EOL;
        $ical .= "DTEND:" . gmdate('Ymd\THis\Z', $event['end']) . self::EOL;
        $ical .= "SUMMARY:" . self::escpape($event['title']) . self::EOL;
        $ical .= "DESCRIPTION:" . self::escpape($event['description']) . self::EOL;
        
        if (!empty($event['attendees'])){
          $ical .= $this->_get_attendees($event['attendees']);
        }

        if (!empty($event['location'])) {
          $ical .= "LOCATION:" . self::escpape($event['location']) . self::EOL;
        }
        if ($event['recurrence']) {
          $ical .= "RRULE:" . calendar::to_rrule($event['recurrence']) . self::EOL;
        }
        if(!empty($event['categories'])) {
          $ical .= "CATEGORIES:" . self::escpape(strtoupper($event['categories'])) . self::EOL;
        }
        if ($event['sensitivity'] > 0) {
          $ical .= "X-CALENDARSERVER-ACCESS:CONFIDENTIAL";
        }
        if ($event['alarms']) {
          list($trigger, $action) = explode(':', $event['alarms']);
          $val = calendar::parse_alaram_value($trigger);
          
          $ical .= "BEGIN:VALARM\n";
          if ($val[1]) $ical .= "TRIGGER:" . preg_replace('/^([-+])(.+)/', '\\1PT\\2', $trigger) . self::EOL;
          else         $ical .= "TRIGGER;VALUE=DATE-TIME:" . gmdate('Ymd\THis\Z', $val[0]) . self::EOL;
          if ($action) $ical .= "ACTION:" . self::escpape(strtoupper($action)) . self::EOL;
          $ical .= "END:VALARM\n";
        }
        $ical .= "TRANSP:" . ($event['free_busy'] == 'free' ? 'TRANSPARENT' : 'OPAQUE') . self::EOL;
        
        // TODO: export attachments
        
        $ical .= "END:VEVENT" . self::EOL;
      }
      
      $ical .= "END:VCALENDAR" . self::EOL;

      // fold lines to 75 chars
      return rcube_vcard::rfc2425_fold($ical);
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
          $organizer .= 'CN="' . $at['name'] . '":';
        $organizer .= "mailto:". $at['email'] . self::EOL;
      }
      else {
        //I am an attendee 
        $attendees .= "ATTENDEE;ROLE=" . $at['role'] . ";PARTSTAT=" . $at['status'];
        if (!empty($at['name']))
          $attendees .= ';CN="' . $at['name'] . '":';
        $attendees .= "mailto:" . $at['email'] . self::EOL;
      }
    }

    return $organizer . $attendees;
  }

}