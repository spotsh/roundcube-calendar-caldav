<?php
/**
 * CalDAV sync for the Calendar plugin
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

require_once (dirname(__FILE__).'/../../lib/caldav-client.php');

class caldav_sync
{
    const ACTION_NONE = 1;
    const ACTION_UPDATE = 2;
    const ACTION_CREATE = 4;

    private $cal_id = null;
    private $ctag = null;
    private $user = null;
    private $pass = null;
    private $url = null;

    /**
     *  Default constructor for calendar synchronization adapter.
     *
     * @param int Calendar id.
     * @param array Hash array with caldav properties:
     *   url: Caldav calendar URL.
     *  user: Caldav http basic auth user.
     *  pass: Password für caldav user.
     *  ctag: Caldav ctag for calendar.
     */
    public function __construct($cal_id, $props)
    {
        $this->cal_id = $cal_id;
        
        $this->url = $props["url"];
        
        $this->ctag = isset($props["tag"]) ? $props["tag"] : null;
        $this->user = isset($props["user"]) ? $props["user"] : null;
        $this->pass = isset($props["pass"]) ? $props["pass"] : null;
        
        $this->caldav = new caldav_client($this->url, $this->user, $this->pass);
    }

    /**
     * Getter for current calendar ctag.
     * @return string
     */
    public function get_ctag()
    {
        return $this->ctag;
    }

    /**
     * Determines whether current calendar needs to be synced
     * regarding the CalDAV ctag.
     *
     * @return True if the current calendar ctag differs from the CalDAV tag which
     *         indicates that there are changes that must be synched. Returns false
     *         if the calendar is up to date, no sync necesarry.
     */
    public function is_synced($force = false)
    {
        $is_synced = $this->ctag == $this->caldav->get_ctag() && $this->ctag;
        caldav_driver::debug_log("Ctag indicates that calendar \"$this->cal_id\" ".($is_synced ? "is synced." : "needs update!"));

        return $is_synced;
    }

    /**
     * Synchronizes given events with caldav server and returns updates.
     *
     * @param array List of local events.
     * @param array List of caldav properties for each event.
     * @return array Tuple containing the following lists:
     *
     * Caldav properties for events to be created or to be updated with the keys:
     *          url: Event ical URL relative to calendar URL
     *         etag: Remote etag of the event
     *  local_event: The local event in case of an update.
     * remote_event: The current event retrieved from caldav server.
     *
     * A list of event ids that are in sync.
     */
    public function get_updates($events, $caldav_props)
    {
        $ctag = $this->caldav->get_ctag();

        if($ctag)
        {
            $this->ctag = $ctag;
            $etags = $this->caldav->get_etags();

            list($updates, $synced_event_ids) = $this->_get_event_updates($events, $caldav_props, $etags);
            return array($this->_get_event_data($updates), $synced_event_ids);
        }
        else
        {
            caldav_driver::debug_log("Unkown error while fetching calendar ctag for calendar \"$this->cal_id\"!");
        }
        
        return null;
    }

    /**
     * Determines sync status and requried updates for the given events using given list of etags.
     *
     * @param array List of local events.
     * @param array List of caldav properties for each event.
     * @param array List of current remote etags.
     * @return array Tuple containing the following lists:
     *
     * Caldav properties for events to be created or to be updated with the keys:
     *          url: Event ical URL relative to calendar URL
     *         etag: Remote etag of the event
     *  local_event: The local event in case of an update.
     *
     * A list of event ids that are in sync.
     */
    private function _get_event_updates($events, $caldav_props, $etags)
    {
        $updates = array();
        $in_sync = array();

        foreach ($etags as $etag)
        {
            $url = $etag["url"];
            $etag = $etag["etag"];
            $event_found = false;
            for($i = 0; $i < sizeof($events); $i ++)
            {
                if ($caldav_props[$i]["url"] == $url)
                {
                    $event_found = true;

                    if ($caldav_props[$i]["tag"] != $etag)
                    {
                        caldav_driver::debug_log("Event ".$events[$i]["uid"]." needs update.");

                        array_push($updates, array(
                            "local_event" => $events[$i],
                            "etag" => $etag,
                            "url" => $url
                        ));
                    }
                    else
                    {
                        array_push($in_sync, $events[$i]["id"]);
                    }
                }
            }

            if (!$event_found)
            {
                caldav_driver::debug_log("Found new event ".$url);

                array_push($updates, array(
                    "url" => $url,
                    "etag" => $etag
                ));
            }
        }

        return array($updates, $in_sync);
    }

    /**
     * Fetches event data and attaches it to the given update properties.
     *
     * @param $updates List of update properties.
     * @return array List of update properties with additional key "remote_event" containing the current caldav event.
     */
    private function _get_event_data($updates)
    {
        $urls = array();

        foreach ($updates as $update)
        {
            array_push($urls, $update["url"]);
        }

        $events = $this->caldav->get_events($urls);
        foreach($updates as &$update)
        {
            // Attach remote events to the appropriate updates.
            // Note that this assumes unique event URL's!
            $url = $update["url"];
            if($events[$url]) {
                $update["remote_event"] = $events[$url];
                $update["remote_event"]["calendar"] = $this->cal_id;
            }
        }

        return $updates;
    }

    /**
     * Creates the given event on the caldav server.
     *
     * @param array Hash array with event properties.
     * @return Caldav properties with created URL on success, false on error.
     */
    public function create_event($event)
    {
        $props = array(
            "url" => parse_url($this->url, PHP_URL_PATH)."/".$event["uid"].".ics",
            "tag" => null
        );

        caldav_driver::debug_log("Push new event to url ".$props["url"]);
        $result = $this->caldav->put_event($props["url"], $event);

        if($result == false || $result < 0) return false;
        return $props;
    }

    /**
     * Updates the given event on the caldav server.
     *
     * @param array Hash array with event properties to update.
     * @param array Hash array with caldav properties "url" and "tag" for the event.
     * @return True on success, false on error, -1 if the given event/etag is not up to date.
     */
    public function update_event($event, $props)
    {
        caldav_driver::debug_log("Updating event uid \"".$event["uid"]."\".");
        return $this->caldav->put_event($props["url"], $event, $props["tag"]);
    }

    /**
     * Removes the given event from the caldav server.
     *
     * @param array Hash array with caldav properties "url" and "tag" for the event.
     * @return True on success, false on error.
     */
    public function remove_event($props)
    {
        caldav_driver::debug_log("Removing event url \"".$props["url"]."\".");
        return $this->caldav->remove_event($props["url"]);
    }
};
?>