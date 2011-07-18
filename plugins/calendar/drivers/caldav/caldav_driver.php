<?php
/**
 * RoundCube Calendar
 *
 * CalDAV backend based on exemplary DAViCal / AWL client.
 *
 * @version 0.2 BETA 2
 * @author Michael Duelli
 * @url http://rc-calendar.lazlo.me
 * @licence GNU GPL
 * @copyright (c) 2010 Lazlo Westerhof - Netherlands
 */
require_once('caldav-client.php');

class caldav_driver extends calendar_driver
{
  private $rcmail = null;
  private $cal = null;
  private $calendar = null;
  
  /**
   * @param object rcmail   The RoundCube instance.
   * @param string server   The CalDAV server.
   * @param string user     The user name.
   * @param string pass     The user's password.
   * @param string calendar The user calendar.
   */
  public function __construct($rcmail, $server, $user, $pass, $calendar) {
    $this->rcmail = $rcmail;
    $this->calendar = '/' . $calendar;

    $this->cal = new CalDAVClient($server. "/" . $user, $user, $pass, $calendar /* is ignored currently */);
    $this->cal->setUserAgent('RoundCube');
  }
  
  public function new_event($event) {
    // FIXME Implement
  }

  public function edit_event($event) {
    // FIXME Implement
  }

  public function move_event($event) {
    // FIXME Implement. Can be done via editEvent
  }
  
  public function resize_event($event) {
    // FIXME Implement. Can be done via editEvent
  }

  public function remove_event($event, $force = true) {
    // FIXME Implement.
  }
  
  public function load_events($start, $end, $calendars = null) {
    if (!empty($this->rcmail->user->ID)) {
      // Fetch events.
      $result = $this->cal->GetEvents($this->GMT_to_iCalendar($start), $this->GMT_to_iCalendar($end), $this->calendar);

      $events = array();
      foreach ($result as $k => $event) {
        $lines = explode("\n", $event['data']);

        $n = count($lines);
        $eventid = null;

	$flag = true;
	for ($i = 0; $i < $n; $i++) {
	  if ($flag) {
	    if (strpos($lines[$i], "BEGIN:VEVENT") === 0)
	      $flag = false;

	    continue;
	  }

	  if (strpos($lines[$i], "END:VEVENT") === 0)
	    break;

	  if (empty($lines[$i]))
	    continue; // FIXME

	  $tmp = explode(":", $lines[$i]);

	  if (count($tmp) !== 2)
	    continue; // FIXME

	  list($id, $value) = $tmp;

	  if (!isset($id) || !isset($value))
	    continue; // FIXME

	  if (is_null($eventid) && strpos($id, "UID") === 0)
	    $eventid = $value;
	  elseif (!isset($event['start']) && strpos($id, "DTSTART") === 0) {
	    $event['start'] = $this->iCalendar_to_Unix($value);
            
	    // Check for all-day event.
	    $event['all_day'] = (strlen($value) === 8 ? 0 : 1);
	  } elseif (!isset($event['end']) && strpos($id, "DTEND") === 0)
	    $event['end'] = $this->iCalendar_to_Unix($value);
	  elseif (!isset($event['title']) && strpos($id, "SUMMARY") === 0)
	    $event['title'] = $value;
	  elseif (!isset($event['description']) && strpos($id, "DESCRIPTION") === 0) {
	    $event['description'] = $value;
	    
	    // FIXME Problem with multiple lines!
//	    if ($i+1 < $n && $lines[$i+1] does not contain keyword...) {
//              Add line to description
//		$i++;
//          }
	  } elseif (!isset($event['location']) && strpos($id, "LOCATION") === 0)
	    $event['location'] = $value;
	  elseif (!isset($event['categories']) && strpos($id, "CATEGORIES") === 0)
	    $event['categories'] = $value;
	}
	
        $events[]=array( 
          'event_id'    => $eventid,
          'start'       => $event['start'],
          'end'         => $event['end'],
          'title'       => strval($event['title']),
          'description' => strval($event['description']),
          'location'    => strval($event['location']),
          'categories'  => $event['categories'],
          'allDay'      => $event['all_day'],
        );
      }

      return $events;
    }
  }

  /**
   * Convert a GMT time stamp ('Y-m-d H:i:s') to the iCalendar format as defined in
   * RFC 5545, Section 3.2.19, http://tools.ietf.org/html/rfc5545#section-3.2.19.
   *
   * @param timestamp A GMT time stamp ('Y-m-d H:i:s')
   * @return An iCalendar time stamp, e.g. yyyymmddThhmmssZ
   */
  private function GMT_to_iCalendar($timestamp) {
    $unix_timestamp = strtotime($timestamp);
    return date("Ymd", $unix_timestamp) . "T" . date("His", $unix_timestamp) . "Z";
  }

  /**
   * Convert a time stamp in iCalendar format as defined in
   * RFC 5545, Section 3.2.19, http://tools.ietf.org/html/rfc5545#section-3.2.19
   * to a Unix time stamp. Further conversion is done in jsonEvents.
   *
   * @param timestamp An iCalendar time stamp, e.g. yyyymmddThhmmssZ
   * @return A Unix time stamp
   */
  private function iCalendar_to_Unix($timestamp) {
    return strtotime($timestamp);
  }
}
?>