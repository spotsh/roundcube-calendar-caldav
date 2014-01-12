<?php
/**
 * iCalendar sync for the Calendar plugin
 *
 * @version @package_version@
 * @author Daniel Morlock <daniel.morlock@awesome-it.de>
 *
 * Copyright (C) 2013, Awesome IT GbR <info@awesome-it.de>
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

class ical_sync
{
    const ACTION_NONE = 1;
    const ACTION_UPDATE = 2;
    const ACTION_CREATE = 4;

    private $cal_id = null;
    private $url = null;
    private $ical = null;

    private $sync_period = 300; // seconds

    /**
     *  Default constructor for calendar synchronization adapter.
     *
     * @param int Calendar id.
     * @param array Hash array with ical properties:
     *   url: Absolute URL to iCAL resource.
     */
    public function __construct($cal_id, $props)
    {
        $this->ical = libcalendaring::get_ical();
        $this->cal_id = $cal_id;
        $this->url = $props["url"];
    }

    /**
     * Determines whether current calendar needs to be synced.
     *
     * @see ical_sync::$sync_period Which defines amount of time after which the remote calendar ctag
     *      is going to be re-checked. Within this time range, to remote sync will be triggered.
     *
     * @return True if the current calendar needs to be synced, false otherwise.
     */
    public function is_synced()
    {
        if(!is_array($_SESSION["calendar_ical_last_sync"]))
            $_SESSION["calendar_ical_last_sync"] = array();

        $last_sync = $_SESSION["calendar_ical_last_sync"][$this->cal_id];

        if(!$last_sync || (time() - $last_sync) >= $this->sync_period)
        {
            $_SESSION["calendar_ical_last_sync"][$this->cal_id] = time();
            ical_driver::debug_log("Sync check: Calendar \"$this->cal_id\" needs to be synced!");
            return false;
        }
        else
        {
            ical_driver::debug_log("Sync check: Calendar \"$this->cal_id\" is in sync!");
            return true;
        }
    }

    /**
     * Fetches events from iCAL resource and returns updates.
     *
     * @param array List of local events.
     * @return array Tuple containing the following lists:
     *
     * Hash list for iCAL events to be created or to be updated with the keys:
     *  local_event: The local event in case of an update.
     * remote_event: The current event retrieved from caldav server.
     *
     * A list of event ids that are in sync.
     */
    public function get_updates($events)
    {
        $vcal = file_get_contents($this->url);
        $updates = array();
        $synced = array();
        if($vcal !== false)
        {
            // Hash existing events by uid.
            $events_hash = array();
            foreach($events as $event) {
                $events_hash[$event['uid']] = $event;
            }

            foreach ($this->ical->import($vcal) as $remote_event) {

                // Attach remote event to current calendar
                $remote_events["calendar"] = $this->cal_id;

                $local_event = null;
                if($events_hash[$remote_event['uid']])
                    $local_event = $events_hash[$remote_event['uid']];

                // Determine whether event don't need an update.
                if($local_event && $local_event["changed"] >= $remote_event["changed"])
                {
                    array_push($synced, $local_event["id"]);
                }
                else
                {
                    array_push($updates, array('local_event' => $local_event, 'remote_event' => $remote_event));
                }
            }
        }

        return array($updates, $synced);
    }
}

;
?>