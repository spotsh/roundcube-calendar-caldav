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
abstract class calendar_driver
{
  // backend features
  public $alarms = false;
  public $attendees = false;
  public $attachments = false;

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
   * Add a single event to the database
   *
   * @param array Hash array with vent properties:
   *     calendar: Calendar identifier to add event to (optional)
   *          uid: Unique identifier of this event
   *        start: Event start date/time as unix timestamp
   *          end: Event end date/time as unix timestamp
   *       allday: Boolean flag if this is an all-day event
   *        title: Event title/summary
   *     location: Location string
   *  description: Event description
   *   recurrence: Recurrence definition according to iCalendar specification
   *   categories: Event categories (comma-separated list)
   *    free_busy: Show time as free/busy/outofoffice
   *     priority: Event priority
   *       alarms: Reminder settings (TBD.)
   * @return mixed New event ID on success, False on error
   */
  abstract function new_event($event);

  /**
   * Update an event entry with the given data
   *
   * @see Driver:new_event()
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
   * @param array Hash array with event properties:
   *      id: Event identifier 
   * @return boolean True on success, False on error
   */
  abstract function remove_event($event);

  /**
   * Get events from source.
   *
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  mixed   List of calendar IDs to load events from (either as array or comma-separated string)
   * @return array A list of event records
   */
  abstract function load_events($start, $end, $calendars = null);

  /**
   * Search events using the given query
   *
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  string  Search query
   * @param  mixed   List of calendar IDs to load events from (either as array or comma-separated string)
   * @return array A list of event records
   */
  abstract function search_events($start, $end, $query, $calendars = null);

  /**
   * Get a list of pending alarms to be displayed to the user
   *
   * @param  integer Current time (unix timestamp)
   * @param  mixed   List of calendar IDs to show alarms for (either as array or comma-separated string)
   * @return array A list of alarms
   */
  abstract function pending_alarms($time, $calendars = null);


  /**
   * Save an attachment related to the given event
   */
  public function add_attachment($attachment, $event_id) { }

  /**
   * Remove a specific attachment from the given event
   */
  public function remove_attachment($attachment, $event_id) { }

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
   */
  public function get_freebusy_list($email, $start, $end)
  {
    return array();
  }

}