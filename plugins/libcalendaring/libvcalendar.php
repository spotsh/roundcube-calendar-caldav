<?php

/**
 * iCalendar functions for the libcalendaring plugin
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2013, Kolab Systems AG <contact@kolabsys.com>
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

use \Sabre\VObject;

// load Sabre\VObject classes
if (!class_exists('\Sabre\VObject\Reader')) {
    require_once __DIR__ . '/lib/Sabre/VObject/includes.php';
}

/**
 * Class to parse and build vCalendar (iCalendar) files
 *
 * Uses the SabreTooth VObject library, version 2.1.
 *
 * Download from https://github.com/fruux/sabre-vobject/archive/2.1.0.zip
 * and place the lib files in this plugin's lib directory
 *
 */
class libvcalendar implements Iterator
{
    private $timezone;
    private $attach_uri = null;
    private $prodid = '-//Roundcube//Roundcube libcalendaring//Sabre//Sabre VObject//EN';
    private $type_component_map = array('event' => 'VEVENT', 'task' => 'VTODO');
    private $attendee_keymap = array('name' => 'CN', 'status' => 'PARTSTAT', 'role' => 'ROLE', 'cutype' => 'CUTYPE', 'rsvp' => 'RSVP');
    private $iteratorkey = 0;
    private $charset;
    private $forward_exceptions;
    private $fp;

    public $method;
    public $agent = '';
    public $objects = array();
    public $freebusy = array();

    /**
     * Default constructor
     */
    function __construct($tz = null)
    {
        $this->timezone = $tz;
        $this->prodid = '-//Roundcube//Roundcube libcalendaring ' . RCUBE_VERSION . '//Sabre//Sabre VObject ' . VObject\Version::VERSION . '//EN';
    }

    /**
     * Setter for timezone information
     */
    public function set_timezone($tz)
    {
        $this->timezone = $tz;
    }

    /**
     * Setter for URI template for attachment links
     */
    public function set_attach_uri($uri)
    {
        $this->attach_uri = $uri;
    }

    /**
     * Setter for a custom PRODID attribute
     */
    public function set_prodid($prodid)
    {
        $this->prodid = $prodid;
    }

    /**
     * Setter for a user-agent string to tweak input/output accordingly
     */
    public function set_agent($agent)
    {
        $this->agent = $agent;
    }

    /**
     * Free resources by clearing member vars
     */
    public function reset()
    {
        $this->method = '';
        $this->objects = array();
        $this->freebusy = array();
        $this->iteratorkey = 0;

        if ($this->fp) {
            fclose($this->fp);
            $this->fp = null;
        }
    }

    /**
    * Import events from iCalendar format
    *
    * @param  string vCalendar input
    * @param  string Input charset (from envelope)
    * @param  boolean True if parsing exceptions should be forwarded to the caller
    * @return array List of events extracted from the input
    */
    public function import($vcal, $charset = 'UTF-8', $forward_exceptions = false, $memcheck = true)
    {
        // TODO: convert charset to UTF-8 if other

        try {
            // estimate the memory usage and try to avoid fatal errors when allowed memory gets exhausted
            if ($memcheck) {
                $count = substr_count($vcal, 'BEGIN:VEVENT');
                $expected_memory = $count * 70*1024;  // assume ~ 70K per event (empirically determined)

                if (!rcube_utils::mem_check($expected_memory)) {
                    throw new Exception("iCal file too big");
                }
            }

            $vobject = VObject\Reader::read($vcal, VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);
            if ($vobject)
                return $this->import_from_vobject($vobject);
        }
        catch (Exception $e) {
            if ($forward_exceptions) {
                throw $e;
            }
            else {
                rcube::raise_error(array(
                    'code' => 600, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "iCal data parse error: " . $e->getMessage()),
                    true, false);
            }
        }

        return array();
    }

    /**
    * Read iCalendar events from a file
    *
    * @param  string File path to read from
    * @param  string Input charset (from envelope)
    * @param  boolean True if parsing exceptions should be forwarded to the caller
    * @return array List of events extracted from the file
    */
    public function import_from_file($filepath, $charset = 'UTF-8', $forward_exceptions = false)
    {
        if ($this->fopen($filepath, $charset, $forward_exceptions)) {
            while ($this->_parse_next(false)) {
                // nop
            }

            fclose($this->fp);
            $this->fp = null;
        }

        return $this->objects;
    }

    /**
     * Open a file to read iCalendar events sequentially
     *
     * @param  string File path to read from
     * @param  string Input charset (from envelope)
     * @param  boolean True if parsing exceptions should be forwarded to the caller
     * @return boolean True if file contents are considered valid
     */
    public function fopen($filepath, $charset = 'UTF-8', $forward_exceptions = false)
    {
        $this->reset();

        // just to be sure...
        @ini_set('auto_detect_line_endings', true);

        $this->charset = $charset;
        $this->forward_exceptions = $forward_exceptions;
        $this->fp = fopen($filepath, 'r');

        // check file content first
        $begin = fread($this->fp, 1024);
        if (!preg_match('/BEGIN:VCALENDAR/i', $begin)) {
            return false;
        }

        // read vcalendar header (with timezone defintion)
        $this->vhead = '';
        fseek($this->fp, 0);
        while (($line = fgets($this->fp, 512)) !== false) {
            if (preg_match('/BEGIN:(VEVENT|VTODO|VFREEBUSY)/i', $line))
                break;
            $this->vhead .= $line;
        }
        fseek($this->fp, -strlen($line), SEEK_CUR);

        return $this->_parse_next();
    }

    /**
     * Parse the next event/todo/freebusy object from the input file
     */
    private function _parse_next($reset = true)
    {
        if ($reset) {
            $this->iteratorkey = 0;
            $this->objects = array();
            $this->freebusy = array();
        }

        $next = $this->_next_component();
        $buffer = $next;

        // load the next component(s) too, as they could contain recurrence exceptions
        while (preg_match('/(RRULE|RECURRENCE-ID)[:;]/i', $next)) {
            $next = $this->_next_component();
            $buffer .= $next;
        }

        // parse the vevent block surrounded with the vcalendar heading
        if (strlen($buffer) && preg_match('/BEGIN:(VEVENT|VTODO|VFREEBUSY)/i', $buffer)) {
            try {
                $this->import($this->vhead . $buffer . "END:VCALENDAR", $this->charset, true, false);
            }
            catch (Exception $e) {
                if ($this->forward_exceptions) {
                    throw new VObject\ParseException($e->getMessage() . " in\n" . $buffer);
                }
                else {
                    // write the failing section to error log
                    rcube::raise_error(array(
                        'code' => 600, 'type' => 'php',
                        'file' => __FILE__, 'line' => __LINE__,
                        'message' => $e->getMessage() . " in\n" . $buffer),
                        true, false);
                }

                // advance to next
                return $this->_parse_next($reset);
            }

            return count($this->objects) > 0;
        }

        return false;
    }

    /**
     * Helper method to read the next calendar component from the file
     */
    private function _next_component()
    {
        $buffer = '';
        while (($line = fgets($this->fp, 1024)) !== false) {
            $buffer .= $line;
            if (preg_match('/END:(VEVENT|VTODO|VFREEBUSY)/i', $line)) {
                break;
            }
        }

        return $buffer;
    }

    /**
     * Import objects from an already parsed Sabre\VObject\Component object
     *
     * @param object Sabre\VObject\Component to read from
     * @return array List of events extracted from the file
     */
    public function import_from_vobject($vobject)
    {
        $seen = array();

        if ($vobject->name == 'VCALENDAR') {
            $this->method = strval($vobject->METHOD);
            $this->agent  = strval($vobject->PRODID);

            foreach ($vobject->getBaseComponents() ?: $vobject->getComponents() as $ve) {
                if ($ve->name == 'VEVENT' || $ve->name == 'VTODO') {
                    // convert to hash array representation
                    $object = $this->_to_array($ve);

                    if (!$seen[$object['uid']]++) {
                        // parse recurrence exceptions
                        if ($object['recurrence']) {
                            foreach ($vobject->children as $i => $component) {
                                if ($component->name == 'VEVENT' && isset($component->{'RECURRENCE-ID'})) {
                                    $object['recurrence']['EXCEPTIONS'][] = $this->_to_array($component);
                                }
                            }
                        }

                        $this->objects[] = $object;
                    }
                }
                else if ($ve->name == 'VFREEBUSY') {
                    $this->objects[] = $this->_parse_freebusy($ve);
                }
            }
        }

        return $this->objects;
    }

    /**
     * Getter for free-busy periods
     */
    public function get_busy_periods()
    {
        $out = array();
        foreach ((array)$this->freebusy['periods'] as $period) {
            if ($period[2] != 'FREE') {
                $out[] = $period;
            }
        }

        return $out;
    }

    /**
     * Helper method to determine whether the connected client is an Apple device
     */
    private function is_apple()
    {
        return stripos($this->agent, 'Apple') !== false
            || stripos($this->agent, 'Mac OS X') !== false
            || stripos($this->agent, 'iOS/') !== false;
    }

    /**
     * Convert the given VEvent object to a libkolab compatible array representation
     *
     * @param object Vevent object to convert
     * @return array Hash array with object properties
     */
    private function _to_array($ve)
    {
        $event = array(
            'uid'     => strval($ve->UID),
            'title'   => strval($ve->SUMMARY),
            '_type'   => $ve->name == 'VTODO' ? 'task' : 'event',
            // set defaults
            'priority' => 0,
            'attendees' => array(),
            'x-custom' => array(),
        );

        // Catch possible exceptions when date is invalid (Bug #2144)
        // We can skip these fields, they aren't critical
        foreach (array('CREATED' => 'created', 'LAST-MODIFIED' => 'changed', 'DTSTAMP' => 'changed') as $attr => $field) {
            try {
                if (!$event[$field] && $ve->{$attr}) {
                    $event[$field] = $ve->{$attr}->getDateTime();
                }
            } catch (Exception $e) {}
        }

        // map other attributes to internal fields
        $_attendees = array();
        foreach ($ve->children as $prop) {
            if (!($prop instanceof VObject\Property))
                continue;

            switch ($prop->name) {
            case 'DTSTART':
            case 'DTEND':
            case 'DUE':
                $propmap = array('DTSTART' => 'start', 'DTEND' => 'end', 'DUE' => 'due');
                $event[$propmap[$prop->name]] =  self::convert_datetime($prop);
                break;

            case 'TRANSP':
                $event['free_busy'] = $prop->value == 'TRANSPARENT' ? 'free' : 'busy';
                break;

            case 'STATUS':
                if ($prop->value == 'TENTATIVE')
                    $event['free_busy'] = 'tentative';
                else if ($prop->value == 'CANCELLED')
                    $event['cancelled'] = true;
                else if ($prop->value == 'COMPLETED')
                    $event['complete'] = 100;
                break;

            case 'PRIORITY':
                if (is_numeric($prop->value))
                    $event['priority'] = $prop->value;
                break;

            case 'RRULE':
                $params = array();
                // parse recurrence rule attributes
                foreach (explode(';', $prop->value) as $par) {
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
                $event['recurrence']['EXDATE'] = array_merge((array)$event['recurrence']['EXDATE'], (array)self::convert_datetime($prop));
                break;

            case 'RECURRENCE-ID':
                $event['recurrence_date'] = self::convert_datetime($prop);
                break;

            case 'RELATED-TO':
                if ($prop->offsetGet('RELTYPE') == 'PARENT') {
                    $event['parent_id'] = $prop->value;
                }
                break;

            case 'SEQUENCE':
                $event['sequence'] = intval($prop->value);
                break;

            case 'PERCENT-COMPLETE':
                $event['complete'] = intval($prop->value);
                break;

            case 'LOCATION':
            case 'DESCRIPTION':
                if ($this->is_apple()) {
                    $event[strtolower($prop->name)] = str_replace('\,', ',', $prop->value);
                    break;
                }
                // else: fall through

            case 'URL':
                $event[strtolower($prop->name)] = $prop->value;
                break;

            case 'CATEGORY':
            case 'CATEGORIES':
                $event['categories'] = $prop->getParts();
                break;

            case 'CLASS':
            case 'X-CALENDARSERVER-ACCESS':
                $event['sensitivity'] = strtolower($prop->value);
                break;

            case 'X-MICROSOFT-CDO-BUSYSTATUS':
                if ($prop->value == 'OOF')
                    $event['free_busy'] = 'outofoffice';
                else if (in_array($prop->value, array('FREE', 'BUSY', 'TENTATIVE')))
                    $event['free_busy'] = strtolower($prop->value);
                break;

            case 'ATTENDEE':
            case 'ORGANIZER':
                $params = array();
                foreach ($prop->parameters as $param) {
                    switch ($param->name) {
                        case 'RSVP': $params[$param->name] = strtolower($param->value) == 'true'; break;
                        default:     $params[$param->name] = $param->value; break;
                    }
                }
                $attendee = self::map_keys($params, array_flip($this->attendee_keymap));
                $attendee['email'] = preg_replace('/^mailto:/i', '', $prop->value);

                if ($prop->name == 'ORGANIZER') {
                    $attendee['role'] = 'ORGANIZER';
                    $attendee['status'] = 'ACCEPTED';
                    $event['organizer'] = $attendee;
                }
                else if ($attendee['email'] != $event['organizer']['email']) {
                    $event['attendees'][] = $attendee;
                }
                break;

            case 'ATTACH':
                $params = self::parameters_array($prop);
                if (substr($prop->value, 0, 4) == 'http' && !strpos($prop->value, ':attachment:')) {
                    $event['links'][] = $prop->value;
                }
                else if (strlen($prop->value) && strtoupper($params['VALUE']) == 'BINARY') {
                    $attachment = self::map_keys($params, array('FMTTYPE' => 'mimetype', 'X-LABEL' => 'name'));
                    $attachment['data'] = base64_decode($prop->value);
                    $attachment['size'] = strlen($attachment['data']);
                    $event['attachments'][] = $attachment;
                }
                break;

            default:
                if (substr($prop->name, 0, 2) == 'X-')
                    $event['x-custom'][] = array($prop->name, strval($prop->value));
                break;
            }
        }

        // check DURATION property if no end date is set
        if (empty($event['end']) && $ve->DURATION) {
            try {
                $duration = new DateInterval(strval($ve->DURATION));
                $end = clone $event['start'];
                $end->add($duration);
                $event['end'] = $end;
            }
            catch (\Exception $e) {
                trigger_error(strval($e), E_USER_WARNING);
            }
        }

        // validate event dates
        if ($event['_type'] == 'event') {
            // check for all-day dates
            if ($event['start']->_dateonly) {
                $event['allday'] = true;
            }

            // shift end-date by one day (except Thunderbird)
            if ($event['allday'] && is_object($event['end'])) {
                $event['end']->sub(new \DateInterval('PT23H'));
            }

            // sanity-check and fix end date
            if (!empty($event['end']) && $event['end'] < $event['start']) {
                $event['end'] = clone $event['start'];
            }
        }

        // make organizer part of the attendees list for compatibility reasons
        if (!empty($event['organizer']) && is_array($event['attendees'])) {
            array_unshift($event['attendees'], $event['organizer']);
        }

        // find alarms
        foreach ($ve->select('VALARM') as $valarm) {
            $action = 'DISPLAY';
            $trigger = null;

            foreach ($valarm->children as $prop) {
                switch ($prop->name) {
                case 'TRIGGER':
                    foreach ($prop->parameters as $param) {
                        if ($param->name == 'VALUE' && $param->value == 'DATE-TIME') {
                            $trigger = '@' . $prop->getDateTime()->format('U');
                        }
                    }
                    if (!$trigger) {
                        $trigger = preg_replace('/PT?/', '', $prop->value);
                    }
                    break;

                case 'ACTION':
                    $action = $prop->value;
                    break;
                }
            }

            if ($trigger && strtoupper($action) != 'NONE') {
                $event['alarms'] = $trigger . ':' . $action;
                break;
            }
        }

        // assign current timezone to event start/end
        if ($event['start'] instanceof DateTime) {
            if ($this->timezone)
                $event['start']->setTimezone($this->timezone);
        }
        else {
            unset($event['start']);
        }

        if ($event['end'] instanceof DateTime) {
            if ($this->timezone)
                $event['end']->setTimezone($this->timezone);
        }
        else {
            unset($event['end']);
        }

        // minimal validation
        if (empty($event['uid']) || ($event['_type'] == 'event' && empty($event['start']) != empty($event['end']))) {
            throw new VObject\ParseException('Object validation failed: missing mandatory object properties');
        }

        return $event;
    }

    /**
     * Parse the given vfreebusy component into an array representation
     */
    private function _parse_freebusy($ve)
    {
        $this->freebusy = array('_type' => 'freebusy', 'periods' => array());
        $seen = array();

        foreach ($ve->children as $prop) {
            if (!($prop instanceof VObject\Property))
                continue;

            switch ($prop->name) {
            case 'DTSTART':
            case 'DTEND':
                $propmap = array('DTSTART' => 'start', 'DTEND' => 'end');
                $this->freebusy[$propmap[$prop->name]] =  self::convert_datetime($prop);
                break;

            case 'ORGANIZER':
                $this->freebusy['organizer'] = preg_replace('/^mailto:/i', '', $prop->value);
                break;

            case 'FREEBUSY':
                // The freebusy component can hold more than 1 value, separated by commas.
                $periods = explode(',', $prop->value);
                $fbtype = strval($prop['FBTYPE']) ?: 'BUSY';

                // skip dupes
                if ($seen[$prop->value.':'.$fbtype]++)
                    continue;

                foreach ($periods as $period) {
                    // Every period is formatted as [start]/[end]. The start is an
                    // absolute UTC time, the end may be an absolute UTC time, or
                    // duration (relative) value.
                    list($busyStart, $busyEnd) = explode('/', $period);

                    $busyStart = VObject\DateTimeParser::parse($busyStart);
                    $busyEnd = VObject\DateTimeParser::parse($busyEnd);
                    if ($busyEnd instanceof \DateInterval) {
                        $tmp = clone $busyStart;
                        $tmp->add($busyEnd);
                        $busyEnd = $tmp;
                    }

                    if ($busyEnd && $busyEnd > $busyStart)
                        $this->freebusy['periods'][] = array($busyStart, $busyEnd, $fbtype);
                }
                break;

            case 'COMMENT':
                $this->freebusy['comment'] = $prop->value;
            }
        }

        return $this->freebusy;
    }

    /**
     * Helper method to correctly interpret an all-day date value
     */
    public static function convert_datetime($prop)
    {
        if (empty($prop)) {
            return null;
        }
        else if ($prop instanceof VObject\Property\MultiDateTime) {
            $dt = array();
            $dateonly = ($prop->getDateType() & VObject\Property\DateTime::DATE);
            foreach ($prop->getDateTimes() as $item) {
                $item->_dateonly = $dateonly;
                $dt[] = $item;
            }
        }
        else if ($prop instanceof VObject\Property\DateTime) {
            $dt = $prop->getDateTime();
            if ($prop->getDateType() & VObject\Property\DateTime::DATE) {
                $dt->_dateonly = true;
            }
        }
        else if ($prop instanceof DateTime) {
            $dt = $prop;
        }

        return $dt;
    }


    /**
     * Create a Sabre\VObject\Property instance from a PHP DateTime object
     *
     * @param string Property name
     * @param object DateTime
     */
    public static function datetime_prop($name, $dt, $utc = false, $dateonly = null)
    {
        $is_utc = $utc || (($tz = $dt->getTimezone()) && in_array($tz->getName(), array('UTC','GMT','Z')));
        $is_dateonly = $dateonly === null ? (bool)$dt->_dateonly : (bool)$dateonly;
        $vdt = new VObject\Property\DateTime($name);
        $vdt->setDateTime($dt, $is_dateonly ? VObject\Property\DateTime::DATE :
            ($is_utc ? VObject\Property\DateTime::UTC : VObject\Property\DateTime::LOCALTZ));
        return $vdt;
    }

    /**
     * Copy values from one hash array to another using a key-map
     */
    public static function map_keys($values, $map)
    {
        $out = array();
        foreach ($map as $from => $to) {
            if (isset($values[$from]))
                $out[$to] = $values[$from];
        }
        return $out;
    }

    /**
     *
     */
    private static function parameters_array($prop)
    {
        $params = array();
        foreach ($prop->parameters as $param) {
            $params[strtoupper($param->name)] = $param->value;
        }
        return $params;
    }


    /**
     * Export events to iCalendar format
     *
     * @param  array   Events as array
     * @param  string  VCalendar method to advertise
     * @param  boolean Directly send data to stdout instead of returning
     * @param  callable Callback function to fetch attachment contents, false if no attachment export
     * @return string  Events in iCalendar format (http://tools.ietf.org/html/rfc5545)
     */
    public function export($objects, $method = null, $write = false, $get_attachment = false, $recurrence_id = null)
    {
        $memory_limit = parse_bytes(ini_get('memory_limit'));
        $this->method = $method;

        // encapsulate in VCALENDAR container
        $vcal = VObject\Component::create('VCALENDAR');
        $vcal->version = '2.0';
        $vcal->prodid = $this->prodid;
        $vcal->calscale = 'GREGORIAN';

        if (!empty($method)) {
            $vcal->METHOD = $method;
        }

        // TODO: include timezone information

        // write vcalendar header
        if ($write) {
            echo preg_replace('/END:VCALENDAR[\r\n]*$/m', '', $vcal->serialize());
        }

        foreach ($objects as $object) {
            $this->_to_ical($object, !$write?$vcal:false, $get_attachment);
        }

        if ($write) {
            echo "END:VCALENDAR\r\n";
            return true;
        }
        else {
            return $vcal->serialize();
        }
    }

    /**
     * Build a valid iCal format block from the given event
     *
     * @param  array    Hash array with event/task properties from libkolab
     * @param  object   VCalendar object to append event to or false for directly sending data to stdout
     * @param  callable Callback function to fetch attachment contents, false if no attachment export
     * @param  object   RECURRENCE-ID property when serializing a recurrence exception
     */
    private function _to_ical($event, $vcal, $get_attachment, $recurrence_id = null)
    {
        $type = $event['_type'] ?: 'event';
        $ve = VObject\Component::create($this->type_component_map[$type]);
        $ve->add('UID', $event['uid']);

        // set DTSTAMP according to RFC 5545, 3.8.7.2.
        $dtstamp = !empty($event['changed']) && !empty($this->method) ? $event['changed'] : new DateTime();
        $ve->add(self::datetime_prop('DTSTAMP', $dtstamp, true));

        // all-day events end the next day
        if ($event['allday'] && !empty($event['end'])) {
            $event['end'] = clone $event['end'];
            $event['end']->add(new \DateInterval('P1D'));
            $event['end']->_dateonly = true;
        }
        if (!empty($event['created']))
            $ve->add(self::datetime_prop('CREATED', $event['created'], true));
        if (!empty($event['changed']))
            $ve->add(self::datetime_prop('LAST-MODIFIED', $event['changed'], true));
        if (!empty($event['start']))
            $ve->add(self::datetime_prop('DTSTART', $event['start'], false, (bool)$event['allday']));
        if (!empty($event['end']))
            $ve->add(self::datetime_prop('DTEND',   $event['end'], false, (bool)$event['allday']));
        if (!empty($event['due']))
            $ve->add(self::datetime_prop('DUE',   $event['due'], false));

        if ($recurrence_id)
            $ve->add($recurrence_id);

        $ve->add('SUMMARY', $event['title']);

        if ($event['location'])
            $ve->add($this->is_apple() ? new vobject_location_property('LOCATION', $event['location']) : new VObject\Property('LOCATION', $event['location']));
        if ($event['description'])
            $ve->add('DESCRIPTION', strtr($event['description'], array("\r\n" => "\n", "\r" => "\n"))); // normalize line endings

        if ($event['sequence'])
            $ve->add('SEQUENCE', $event['sequence']);

        if ($event['recurrence'] && !$recurrence_id) {
            if ($exdates = $event['recurrence']['EXDATE']) {
                unset($event['recurrence']['EXDATE']);  // don't serialize EXDATEs into RRULE value
            }

            $ve->add('RRULE', libcalendaring::to_rrule($event['recurrence']));

            // add EXDATEs each one per line (for Thunderbird Lightning)
            if ($exdates) {
                foreach ($exdates as $ex) {
                    if ($ex instanceof \DateTime) {
                        $exd = clone $event['start'];
                        $exd->setDate($ex->format('Y'), $ex->format('n'), $ex->format('j'));
                        $exd->setTimeZone(new \DateTimeZone('UTC'));
                        $ve->add(new VObject\Property('EXDATE', $exd->format('Ymd\\THis\\Z')));
                    }
                }
            }
        }

        if ($event['categories']) {
            $cat = VObject\Property::create('CATEGORIES');
            $cat->setParts((array)$event['categories']);
            $ve->add($cat);
        }

        if (!empty($event['free_busy'])) {
            $ve->add('TRANSP', $event['free_busy'] == 'free' ? 'TRANSPARENT' : 'OPAQUE');

            // for Outlook clients we provide the X-MICROSOFT-CDO-BUSYSTATUS property
            if (stripos($this->agent, 'outlook') !== false) {
                $ve->add('X-MICROSOFT-CDO-BUSYSTATUS', $event['free_busy'] == 'outofoffice' ? 'OOF' : strtoupper($event['free_busy']));
            }
        }

        if ($event['priority'])
          $ve->add('PRIORITY', $event['priority']);

        if ($event['cancelled'])
            $ve->add('STATUS', 'CANCELLED');
        else if ($event['free_busy'] == 'tentative')
            $ve->add('STATUS', 'TENTATIVE');
        else if ($event['complete'] == 100)
            $ve->add('STATUS', 'COMPLETED');

        if (!empty($event['sensitivity']))
            $ve->add('CLASS', strtoupper($event['sensitivity']));

        if (!empty($event['complete'])) {
            $ve->add('PERCENT-COMPLETE', intval($event['complete']));
            // Apple iCal required the COMPLETED date to be set in order to consider a task complete
            if ($event['complete'] == 100)
                $ve->add(self::datetime_prop('COMPLETED', $event['changed'] ?: new DateTime('now - 1 hour'), true));
        }

        if ($event['alarms']) {
            $va = VObject\Component::create('VALARM');
            list($trigger, $va->action) = explode(':', $event['alarms']);
            $val = libcalendaring::parse_alaram_value($trigger);
            $period = $val[1] && preg_match('/[HMS]$/', $val[1]) ? 'PT' : 'P';
            if ($val[1]) $va->add('TRIGGER', preg_replace('/^([-+])P?T?(.+)/', "\\1$period\\2", $trigger));
            else         $va->add('TRIGGER', gmdate('Ymd\THis\Z', $val[0]), array('VALUE' => 'DATE-TIME'));
            $ve->add($va);
        }

        foreach ((array)$event['attendees'] as $attendee) {
            if ($attendee['role'] == 'ORGANIZER') {
                if (empty($event['organizer']))
                    $event['organizer'] = $attendee;
            }
            else if (!empty($attendee['email'])) {
                $attendee['rsvp'] = $attendee['rsvp'] ? 'TRUE' : null;
                $ve->add('ATTENDEE', 'mailto:' . $attendee['email'], self::map_keys($attendee, $this->attendee_keymap));
            }
        }

        if ($event['organizer']) {
            $ve->add('ORGANIZER', 'mailto:' . $event['organizer']['email'], self::map_keys($event['organizer'], array('name' => 'CN')));
        }

        foreach ((array)$event['url'] as $url) {
            if (!empty($url)) {
                $ve->add('URL', $url);
            }
        }

        if (!empty($event['parent_id'])) {
            $ve->add('RELATED-TO', $event['parent_id'], array('RELTYPE' => 'PARENT'));
        }

        // export attachments
        if (!empty($event['attachments'])) {
            foreach ((array)$event['attachments'] as $attach) {
                // check available memory and skip attachment export if we can't buffer it
                if (is_callable($get_attachment) && $memory_limit > 0 && ($memory_used = function_exists('memory_get_usage') ? memory_get_usage() : 16*1024*1024)
                    && $attach['size'] && $memory_used + $attach['size'] * 3 > $memory_limit) {
                    continue;
                }
                // embed attachments using the given callback function
                if (is_callable($get_attachment) && ($data = call_user_func($get_attachment, $attach['id'], $event))) {
                    // embed attachments for iCal
                    $ve->add('ATTACH',
                        base64_encode($data),
                        array('VALUE' => 'BINARY', 'ENCODING' => 'BASE64', 'FMTTYPE' => $attach['mimetype'], 'X-LABEL' => $attach['name']));
                    unset($data);  // attempt to free memory
                }
                // list attachments as absolute URIs
                else if (!empty($this->attach_uri)) {
                    $ve->add('ATTACH',
                        strtr($this->attach_uri, array(
                            '{{id}}'       => urlencode($attach['id']),
                            '{{name}}'     => urlencode($attach['name']),
                            '{{mimetype}}' => urlencode($attach['mimetype']),
                        )),
                        array('FMTTYPE' => $attach['mimetype'], 'VALUE' => 'URI'));
                }
            }
        }

        foreach ((array)$event['links'] as $uri) {
            $ve->add('ATTACH', $uri);
        }

        // add custom properties
        foreach ((array)$event['x-custom'] as $prop) {
            $ve->add($prop[0], $prop[1]);
        }

        // append to vcalendar container
        if ($vcal) {
            $vcal->add($ve);
        }
        else {   // serialize and send to stdout
            echo $ve->serialize();
        }

        // append recurrence exceptions
        if ($event['recurrence']['EXCEPTIONS']) {
            foreach ($event['recurrence']['EXCEPTIONS'] as $ex) {
                $exdate = clone $event['start'];
                $exdate->setDate($ex['start']->format('Y'), $ex['start']->format('n'), $ex['start']->format('j'));
                $recurrence_id = self::datetime_prop('RECURRENCE-ID', $exdate, true);
                // if ($ex['thisandfuture'])  // not supported by any client :-(
                //    $recurrence_id->add('RANGE', 'THISANDFUTURE');
                $this->_to_ical($ex, $vcal, $get_attachment, $recurrence_id);
            }
        }
    }


    /*** Implement PHP 5 Iterator interface to make foreach work ***/

    function current()
    {
        return $this->objects[$this->iteratorkey];
    }

    function key()
    {
        return $this->iteratorkey;
    }

    function next()
    {
        $this->iteratorkey++;

        // read next chunk if we're reading from a file
        if (!$this->objects[$this->iteratorkey] && $this->fp) {
            $this->_parse_next(true);
        }

        return $this->valid();
    }

    function rewind()
    {
        $this->iteratorkey = 0;
    }

    function valid()
    {
        return !empty($this->objects[$this->iteratorkey]);
    }

}


/**
 * Override Sabre\VObject\Property that quotes commas in the location property
 * because Apple clients treat that property as list.
 */
class vobject_location_property extends VObject\Property
{
    /**
     * Turns the object back into a serialized blob.
     *
     * @return string
     */
    public function serialize()
    {
        $str = $this->name;

        foreach ($this->parameters as $param) {
            $str.=';' . $param->serialize();
        }

        $src = array(
            '\\',
            "\n",
            ',',
        );
        $out = array(
            '\\\\',
            '\n',
            '\,',
        );
        $str.=':' . str_replace($src, $out, $this->value);

        $out = '';
        while (strlen($str) > 0) {
            if (strlen($str) > 75) {
                $out.= mb_strcut($str, 0, 75, 'utf-8') . "\r\n";
                $str = ' ' . mb_strcut($str, 75, strlen($str), 'utf-8');
            } else {
                $out.= $str . "\r\n";
                $str = '';
                break;
            }
        }

        return $out;
    }
}
