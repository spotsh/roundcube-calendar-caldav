<?php
/*
 +-------------------------------------------------------------------------+
 | Driver interface for the Calendar Plugin                                |
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

/**
 * Struct of an internal event object how it passed from/to the driver classes:
 *
 *  $event = array(
 *            'id' => 'Event ID used for editing',
 *           'uid' => 'Unique identifier of this event',
 *      'calendar' => 'Calendar identifier to add event to or where the event is stored',
 *         'start' => <unixtime>,  // Event start date/time as unix timestamp
 *           'end' => <unixtime>,  // Event end date/time as unix timestamp
 *        'allday' => true|false,  // Boolean flag if this is an all-day event
 *         'title' => 'Event title/summary',
 *      'location' => 'Location string',
 *   'description' => 'Event description',
 *    'recurrence' => array(   // Recurrence definition according to iCalendar (RFC 2445) specification as list of key-value pairs
 *            'FREQ' => 'DAILY|WEEKLY|MONTHLY|YEARLY',
 *        'INTERVAL' => 1...n,
 *           'UNTIL' => <unixtime>,
 *           'COUNT' => 1..n,   // number of times
 *                      // + more properties (see http://www.kanzaki.com/docs/ical/recur.html)
 *          'EXDATE' => array(),  // list of <unixtime>s of exception Dates/Times
 *    ),
 * 'recurrence_id' => 'ID of the recurrence group',   // usually the ID of the starting event
 *    'categories' => 'Event category',
 *     'free_busy' => 'free|busy|outofoffice|tentative',  // Show time as
 *      'priority' => 1|0|2,   // Event priority (0=low, 1=normal, 2=high)
 *   'sensitivity' => 0|1|2,   // Event sensitivity (0=public, 1=private, 2=confidential)
 *        'alarms' => '-15M:DISPLAY',  // Reminder settings inspired by valarm definition (e.g. display alert 15 minutes before event)
 *      'savemode' => 'all|future|current|new',   // How changes on recurring event should be handled
 *   'attachments' => array(   // List of attachments
 *            'name' => 'File name',
 *        'mimetype' => 'Content type',
 *            'size' => 1..n, // in bytes
 *              'id' => 'Attachment identifier'
 *   ),
 * 'deleted_attachments' => array(), // array of attachment identifiers to delete when event is updated
 *     'attendees' => array(   // List of event participants
 *            'name' => 'Participant name',
 *           'email' => 'Participant e-mail address',  // used as identifier
 *            'role' => 'ORGANIZER|REQ-PARTICIPANT|OPT-PARTICIPANT|CHAIR',
 *          'status' => 'NEEDS-ACTION|UNKNOWN|ACCEPTED|TENTATIVE|DECLINED'
 *    ),
 *  );
 */

/**
 * Interface definition for calendar driver classes
 */
abstract class calendar_driver
{
  // features supported by backend
  public $alarms = false;
  public $attendees = false;
  public $freebusy = false;
  public $attachments = false;
  public $undelete = false; // event undelete action
  public $categoriesimmutable = false;
  public $alarm_types = array('DISPLAY');

  /**
   * Get a list of available calendars from this source
   */
  abstract function list_calendars();

  /**
   * Create a new calendar assigned to the current user
   *
   * @param array Hash array with calendar properties
   *    name: Calendar name
   *   color: The color of the calendar
   * @return mixed ID of the calendar on success, False on error
   */
  abstract function create_calendar($prop);

  /**
   * Update properties of an existing calendar
   *
   * @param array Hash array with calendar properties
   *      id: Calendar Identifier
   *    name: Calendar name
   *   color: The color of the calendar
   * @return boolean True on success, Fales on failure
   */
  abstract function edit_calendar($prop);

  /**
   * Delete the given calendar with all its contents
   *
   * @param array Hash array with calendar properties
   *      id: Calendar Identifier
   * @return boolean True on success, Fales on failure
   */
  abstract function remove_calendar($prop);

  /**
   * Add a single event to the database
   *
   * @param array Hash array with event properties (see header of this file)
   * @return mixed New event ID on success, False on error
   */
  abstract function new_event($event);

  /**
   * Update an event entry with the given data
   *
   * @param array Hash array with event properties (see header of this file)
   * @return boolean True on success, False on error
   */
  abstract function edit_event($event);

  /**
   * Move a single event
   *
   * @param array Hash array with event properties:
   *      id: Event identifier
   *   start: Event start date/time as unix timestamp
   *     end: Event end date/time as unix timestamp
   *  allday: Boolean flag if this is an all-day event
   * @return boolean True on success, False on error
   */
  abstract function move_event($event);

  /**
   * Resize a single event
   *
   * @param array Hash array with event properties:
   *      id: Event identifier
   *   start: Event start date/time as unix timestamp in user timezone
   *     end: Event end date/time as unix timestamp in user timezone
   * @return boolean True on success, False on error
   */
  abstract function resize_event($event);

  /**
   * Remove a single event from the database
   *
   * @param array   Hash array with event properties:
   *      id: Event identifier
   * @param boolean Remove event irreversible (mark as deleted otherwise,
   *                if supported by the backend)
   *
   * @return boolean True on success, False on error
   */
  abstract function remove_event($event, $force = true);

  /**
   * Restores a single deleted event (if supported)
   *
   * @param array Hash array with event properties:
   *      id: Event identifier
   *
   * @return boolean True on success, False on error
   */
  public function restore_event($event)
  {
    return false;
  }

  /**
   * Get events from source.
   *
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  string  Search query (optional)
   * @param  mixed   List of calendar IDs to load events from (either as array or comma-separated string)
   * @return array A list of event objects (see header of this file for struct of an event)
   */
  abstract function load_events($start, $end, $query = null, $calendars = null);

  /**
   * Get a list of pending alarms to be displayed to the user
   *
   * @param  integer Current time (unix timestamp)
   * @param  mixed   List of calendar IDs to show alarms for (either as array or comma-separated string)
   * @return array A list of alarms, each encoded as hash array:
   *         id: Event identifier
   *        uid: Unique identifier of this event
   *      start: Event start date/time as unix timestamp
   *        end: Event end date/time as unix timestamp
   *     allday: Boolean flag if this is an all-day event
   *      title: Event title/summary
   *   location: Location string
   */
  abstract function pending_alarms($time, $calendars = null);

  /**
   * (User) feedback after showing an alarm notification
   * This should mark the alarm as 'shown' or snooze it for the given amount of time
   *
   * @param  string  Event identifier
   * @param  integer Suspend the alarm for this number of seconds
   */
  abstract function dismiss_alarm($event_id, $snooze = 0);

  /**
   * Get list of event's attachments.
   * Drivers can return list of attachments as event property.
   * If they will do not do this list_attachments() method will be used.
   *
   * @param array $event Hash array with event properties:
   *         id: Event identifier
   *   calendar: Calendar identifier
   *
   * @return array List of attachments, each as hash array:
   *         id: Attachment identifier
   *       name: Attachment name
   *   mimetype: MIME content type of the attachment
   *       size: Attachment size
   */
  public function list_attachments($event) { }

  /**
   * Get attachment properties
   *
   * @param string $id    Attachment identifier
   * @param array  $event Hash array with event properties:
   *         id: Event identifier
   *   calendar: Calendar identifier
   *
   * @return array Hash array with attachment properties:
   *         id: Attachment identifier
   *       name: Attachment name
   *   mimetype: MIME content type of the attachment
   *       size: Attachment size
   */
  public function get_attachment($id, $event) { }

  /**
   * Get attachment body
   *
   * @param string $id    Attachment identifier
   * @param array  $event Hash array with event properties:
   *         id: Event identifier
   *   calendar: Calendar identifier
   *
   * @return string Attachment body
   */
  public function get_attachment_body($id, $event) { }

  /**
   * List availabale categories
   * The default implementation reads them from config/user prefs
   */
  public function list_categories()
  {
    $rcmail = rcmail::get_instance();
    return $rcmail->config->get('calendar_categories', array());
  }

  /**
   * Create a new category
   */
  public function add_category($name, $color) { }

  /**
   * Remove the given category
   */
  public function remove_category($name) { }

  /**
   * Update/replace a category
   */
  public function replace_category($oldname, $name, $color) { }

  /**
   * Fetch free/busy information from a person within the given range
   *
   * @param string E-mail address of attendee
   * @param integer Requested period start date/time as unix timestamp
   * @param integer Requested period end date/time as unix timestamp
   * @return array List of busy timeslots within the requested range
   */
  public function get_freebusy_list($email, $start, $end)
  {
    return false;
  }
  
  /**
   * Callback function to append additional elements to the calendar create/edit form
   *
   * @param string Request action 'form-edit|form-new'
   * @param array Calendar properties (e.g. id)
   * @param string HTML code for default edit form
   * @return string HTML to be appended to form
   */
  public function calendar_form($action, $calendar, $html)
  {
    return $html;
  }

}