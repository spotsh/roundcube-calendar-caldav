<?php
/*
 +-------------------------------------------------------------------------+
 | iCalendar functions for the Calendar Plugin                             |
 | Version 0.3 beta                                                       |
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
 +-------------------------------------------------------------------------+
*/

class calendar_ical
{
  private $rc;
  private $driver;

  function __construct($rc, $driver) {
    $this->rc = $rc;
    $this->driver = $driver;
  }
    
  /**
   * Import events from iCalendar format
   *
   * @param  array Associative events array
   * @access public
   */
  public function import($events) {
    //TODO
    // for ($events as $event)
    //   $this->backend->newEvent(...);
  }
  
  /**
   * Export events to iCalendar format
   *
   * @param  array Events as array
   * @return string  Events in iCalendar format (http://tools.ietf.org/html/rfc5545)
   * @access public
   */
  public function export($events) {
    if (!empty($this->rc->user->ID)) {
      $ical = "BEGIN:VCALENDAR\n";
      $ical .= "VERSION:2.0\n";
      $ical .= "PRODID:-//Roundcube Webmail//NONSGML Calendar//EN\n";
      foreach ($events as $event) {
        $ical .= "BEGIN:VEVENT\n";
        $ical .= "DTSTART:" . date('Ymd\THis\Z', $event['start'] - date('Z')) . "\n";
        $ical .= "DTEND:" . date('Ymd\THis\Z', $event['end'] - date('Z')) . "\n";
        $ical .= "SUMMARY:" . $event['title'] . "\n";
        $ical .= "DESCRIPTION:" . $event['description'] . "\n";
        if (!empty($event['location'])) {
          $ical .= "LOCATION:" . $event['location'] . "\n";
        }
        if(!empty($event['categories'])) {
          $ical .= "CATEGORIES:" . strtoupper($event['categories']) . "\n";
        }
        if ($event['private']) {
          $ical .= 'X-CALENDARSERVER-ACCESS:CONFIDENTIAL';
        }
        $ical .= 'TRANSP:' . ($event['free_busy'] == 'free' ? 'TRANSPARENT' : 'OPAQUE');
        $ical .= "END:VEVENT\n";
      }
      $ical .= "END:VCALENDAR";

      return $ical;
    }
  }
}