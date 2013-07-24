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

/**
 * Class to parse and build vCalendar (iCalendar) files
 *
 * Uses the SabreTooth VObject library, version 2.1.
 *
 * Download from https://github.com/fruux/sabre-vobject/archive/2.1.0.zip
 * and place the lib files in this plugin's lib directory
 *
 */
class libvcalendar
{
    private $timezone;
    private $attach_uri = null;
    private $prodid = '-//Roundcube//Roundcube libcalendaring//Sabre//Sabre VObject//EN';
    private $type_component_map = array('event' => 'VEVENT', 'task' => 'VTODO');
    private $attendee_keymap = array('name' => 'CN', 'status' => 'PARTSTAT', 'role' => 'ROLE', 'cutype' => 'CUTYPE', 'rsvp' => 'RSVP');

    public $method;
    public $objects = array();

    /**
     * Default constructor
     */
    function __construct($tz = null)
    {
        // load Sabre\VObject classes
        if (!class_exists('\Sabre\VObject\Reader')) {
            require_once(__DIR__ . '/lib/Sabre/VObject/includes.php');
        }

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
     * Free resources by clearing member vars
     */
    public function reset()
    {
        $this->method = '';
        $this->objects = array();
    }

    /**
    * Import events from iCalendar format
    *
    * @param  string vCalendar input
    * @param  string Input charset (from envelope)
    * @return array List of events extracted from the input
    */
    public function import($vcal, $charset = 'UTF-8')
    {
        // TODO: convert charset to UTF-8 if other

        try {
            $vobject = VObject\Reader::read($vcal, VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);
            if ($vobject)
                return $this->import_from_vobject($vobject);
        }
        catch (Exception $e) {
            throw $e;
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "iCal data parse error: " . $e->getMessage()),
                true, false);
        }

        return array();
    }

    /**
    * Read iCalendar events from a file
    *
    * @param string File path to read from
    * @return array List of events extracted from the file
    */
    public function import_from_file($filepath)
    {
        $this->objects = array();
        $fp = fopen($filepath, 'r');

        // check file content first
        $begin = fread($fp, 1024);
        if (!preg_match('/BEGIN:VCALENDAR/i', $begin)) {
            return $this->objects;
        }
        fclose($fp);

        return $this->import(file_get_contents($filepath));
    }

    /**
     * Import objects from an already parsed Sabre\VObject\Component object
     *
     * @param object Sabre\VObject\Component to read from
     * @return array List of events extracted from the file
     */
    public function import_from_vobject($vobject)
    {
        $this->objects = $seen = array();

        if ($vobject->name == 'VCALENDAR') {
            $this->method = strval($vobject->METHOD);

            foreach ($vobject->getBaseComponents() as $ve) {
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
            }
        }

        return $this->objects;
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
            'created' => $ve->CREATED ? $ve->CREATED->getDateTime() : null,
            'changed' => null,
            '_type'   => $ve->name == 'VTODO' ? 'task' : 'event',
            // set defaults
            'free_busy' => 'busy',
            'priority' => 0,
            'attendees' => array(),
        );

        if ($ve->{'LAST-MODIFIED'}) {
            $event['changed'] = $ve->{'LAST-MODIFIED'}->getDateTime();
        }
        else if ($ve->DTSTAMP) {
            $event['changed'] = $ve->DTSTAMP->getDateTime();
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
                // $event['recurrence_id'] = self::convert_datetime($prop);
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

            case 'DESCRIPTION':
            case 'LOCATION':
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
                    $event['free_busy'] == 'outofoffice';
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

        // check for all-day dates
        if ($event['start']->_dateonly) {
            $event['allday'] = true;
        }

        // shift end-date by one day (except Thunderbird)
        if ($event['allday'] && is_object($event['end'])) {
            $event['end']->sub(new \DateInterval('PT23H'));
        }

        // sanity-check and fix end date
        if (empty($event['end'])) {
            $event['end'] = clone $event['start'];
        }
        else if ($event['end'] < $event['start']) {
            $event['end'] = clone $event['start'];
        }

        // make organizer part of the attendees list for compatibility reasons
        if (!empty($event['organizer']) && is_array($event['attendees'])) {
            array_unshift($event['attendees'], $event['organizer']);
        }

        // find alarms
        if ($valarms = $ve->select('VALARM')) {
            $action = 'DISPLAY';
            $trigger = null;

            $valarm = reset($valarms);
            foreach ($valarm->children as $prop) {
                switch ($prop->name) {
                case 'TRIGGER':
                    foreach ($prop->parameters as $param) {
                        if ($param->name == 'VALUE' && $param->value == 'DATE-TIME') {
                            $trigger = '@' . $prop->getDateTime()->format('U');
                        }
                    }
                    if (!$trigger) {
                        $trigger = preg_replace('/PT/', '', $prop->value);
                    }
                    break;

                case 'ACTION':
                    $action = $prop->value;
                    break;
                }
            }

            if ($trigger)
                $event['alarms'] = $trigger . ':' . $action;
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
        if (empty($event['uid']) || empty($event['start']) != empty($event['end'])) {
            throw new VObject\ParseException('Object validation failed: missing mandatory object properties');
        }

        return $event;
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
    public static function datetime_prop($name, $dt, $utc = false)
    {
        $vdt = new VObject\Property\DateTime($name);
        $vdt->setDateTime($dt, $dt->_dateonly ? VObject\Property\DateTime::DATE :
            ($utc ? VObject\Property\DateTime::UTC : VObject\Property\DateTime::LOCALTZ));
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

        // all-day events end the next day
        if ($event['allday'] && !empty($event['end'])) {
            $event['end'] = clone $event['end'];
            $event['end']->add(new \DateInterval('P1D'));
            $event['end']->_dateonly = true;
        }
        if (!empty($event['created']))
            $ve->add(self::datetime_prop('CREATED', $event['created'], true));
        if (!empty($event['changed']))
            $ve->add(self::datetime_prop('DTSTAMP', $event['changed'], true));
        if (!empty($event['start']))
            $ve->add(self::datetime_prop('DTSTART', $event['start'], false));
        if (!empty($event['end']))
            $ve->add(self::datetime_prop('DTEND',   $event['end'], false));
        if (!empty($event['due']))
            $ve->add(self::datetime_prop('DUE',   $event['due'], false));

        if ($recurrence_id)
            $ve->add($recurrence_id);

        $ve->add('SUMMARY', $event['title']);

        if ($event['location'])
            $ve->add('LOCATION', $event['location']);
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

        $ve->add('TRANSP', $event['free_busy'] == 'free' ? 'TRANSPARENT' : 'OPAQUE');

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

        if (isset($event['complete'])) {
            $ve->add('PERCENT-COMPLETE', intval($event['complete']));
            // Apple iCal required the COMPLETED date to be set in order to consider a task complete
            if ($event['complete'] == 100)
                $ve->add(self::datetime_prop('COMPLETED', $event['changed'] ?: new DateTime('now - 1 hour'), true));
        }

        if ($event['alarms']) {
            $va = VObject\Component::create('VALARM');
            list($trigger, $va->action) = explode(':', $event['alarms']);
            $val = libcalendaring::parse_alaram_value($trigger);
            if ($val[1]) $va->add('TRIGGER', preg_replace('/^([-+])(.+)/', '\\1PT\\2', $trigger));
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

}
