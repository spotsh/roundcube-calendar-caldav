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

    private $sync_period = 5; // seconds

    /**
     *  Default constructor for calendar synchronization adapter.
     *
     * @param int Calendar id.
     * @param array Hash array with ical properties:
     *   url: Absolute URL to iCAL resource.
     */
    public function __construct($cal_id, $props)
    {
        $this->cal_id = $cal_id;
        $this->url = $props["url"];
    }

    /**
     * Determines whether current calendar needs to be synced.
     *
     * @see ical_sync::$sync_period Which defines amount of time after which the remote calendar ctag
     *      is going to be re-checked. Within this time range, to remote sync will be triggered.
     *
     * @return True if the current calendar needs to be synched, false otherwise.
     */
    public function is_synced()
    {
        $last_sync = $_SESSION["calendar_ical_last_sync"];
        return (!$last_sync || (time() - $last_sync) >= $this->sync_period);
    }

    /**
     * Fetches events from iCAL resource and returns updates.
     *
     * @param array List of local events.
     * @param array List of iCAL properties for each event.
     * @return array Tuple containing the following lists:
     *
     * Properties for events to be created or to be updated with the keys:
     *  local_event: The local event in case of an update.
     * remote_event: The current event retrieved from the iCAL resource.
     *
     * A list of event ids that are in sync.
     */
    public function get_updates($events, $ical_props)
    {
        // TODO
    }
}

;
?>