<?php

/**
 * Kolab Event model class
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_format_event extends kolab_format_xcal
{
    protected $read_func = 'kolabformat::readEvent';
    protected $write_func = 'kolabformat::writeEvent';

    private $kolab2_rolemap = array(
        'required' => 'REQ-PARTICIPANT',
        'optional' => 'OPT-PARTICIPANT',
        'resource' => 'CHAIR',
    );
    private $kolab2_statusmap = array(
        'none'      => 'NEEDS-ACTION',
        'tentative' => 'TENTATIVE',
        'accepted'  => 'CONFIRMED',
        'accepted'  => 'ACCEPTED',
        'declined'  => 'DECLINED',
    );
    private $kolab2_monthmap = array('', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december');


    /**
     * Default constructor
     */
    function __construct($xmldata = null)
    {
        $this->obj = new Event;
        $this->xmldata = $xmldata;
    }

    /**
     * Set event properties to the kolabformat object
     *
     * @param array  Event data as hash array
     */
    public function set(&$object)
    {
        $this->init();

        // set common xcal properties
        parent::set($object);

        // do the hard work of setting object values
        $this->obj->setStart(self::get_datetime($object['start'], null, $object['allday']));
        $this->obj->setEnd(self::get_datetime($object['end'], null, $object['allday']));
        $this->obj->setTransparency($object['free_busy'] == 'free');

        $status = kolabformat::StatusUndefined;
        if ($object['free_busy'] == 'tentative')
            $status = kolabformat::StatusTentative;
        if ($object['cancelled'])
            $status = kolabformat::StatusCancelled;
        $this->obj->setStatus($status);

        // save attachments
        $vattach = new vectorattachment;
        foreach ((array)$object['_attachments'] as $cid => $attr) {
            if (empty($attr))
                continue;
            $attach = new Attachment;
            $attach->setLabel((string)$attr['name']);
            $attach->setUri('cid:' . $cid, $attr['mimetype']);
            $vattach->push($attach);
        }
        $this->obj->setAttachments($vattach);

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
    }

    /**
     *
     */
    public function is_valid()
    {
        return $this->data || (is_object($this->obj) && $this->obj->isValid() && $this->obj->uid());
    }

    /**
     * Convert the Event object into a hash array data structure
     *
     * @return array  Event data as hash array
     */
    public function to_array()
    {
        // return cached result
        if (!empty($this->data))
            return $this->data;

        $this->init();

        // read common xcal props
        $object = parent::to_array();

        // read object properties
        $object += array(
            'end'         => self::php_datetime($this->obj->end()),
            'allday'      => $this->obj->start()->isDateOnly(),
            'free_busy'   => $this->obj->transparency() ? 'free' : 'busy',  // TODO: transparency is only boolean
            'attendees'   => array(),
        );

        // organizer is part of the attendees list in Roundcube
        if ($object['organizer']) {
            $object['organizer']['role'] = 'ORGANIZER';
            array_unshift($object['attendees'], $object['organizer']);
        }

        // status defines different event properties...
        $status = $this->obj->status();
        if ($status == kolabformat::StatusTentative)
          $object['free_busy'] = 'tentative';
        else if ($status == kolabformat::StatusCancelled)
          $objec['cancelled'] = true;

        // handle attachments
        $vattach = $this->obj->attachments();
        for ($i=0; $i < $vattach->size(); $i++) {
            $attach = $vattach->get($i);

            // skip cid: attachments which are mime message parts handled by kolab_storage_folder
            if (substr($attach->uri(), 0, 4) != 'cid') {
                $name = $attach->label();
                $data = $attach->data();
                $object['_attachments'][$name] = array(
                    'name' => $name,
                    'mimetype' => $attach->mimetype(),
                    'size' => strlen($data),
                    'content' => $data,
                );
            }
        }

        $this->data = $object;
        return $this->data;
    }

    /**
     * Callback for kolab_storage_cache to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags()
    {
        $tags = array();

        foreach ((array)$this->data['categories'] as $cat) {
            $tags[] = rcube_utils::normalize_string($cat);
        }

        if (!empty($this->data['alarms'])) {
            $tags[] = 'x-has-alarms';
        }

        return $tags;
    }

    /**
     * Load data from old Kolab2 format
     */
    public function fromkolab2($rec)
    {
        if (PEAR::isError($rec))
            return;

        $start_time = date('H:i:s', $rec['start-date']);
        $allday = $rec['_is_all_day'] || ($start_time == '00:00:00' && $start_time == date('H:i:s', $rec['end-date']));

        // in Roundcube all-day events go from 12:00 to 13:00
        if ($allday) {
            $now = new DateTime('now', self::$timezone);
            $gmt_offset = $now->getOffset();

            $rec['start-date'] += 12 * 3600;
            $rec['end-date']   -= 11 * 3600;
            $rec['end-date']   -= $gmt_offset - date('Z', $rec['end-date']);    // shift times from server's timezone to user's timezone
            $rec['start-date'] -= $gmt_offset - date('Z', $rec['start-date']);  // because generated with mktime() in Horde_Kolab_Format_Date::decodeDate()
            // sanity check
            if ($rec['end-date'] <= $rec['start-date'])
              $rec['end-date'] += 86400;
        }

        // convert alarm time into internal format
        if ($rec['alarm']) {
            $alarm_value = $rec['alarm'];
            $alarm_unit = 'M';
            if ($rec['alarm'] % 1440 == 0) {
                $alarm_value /= 1440;
                $alarm_unit = 'D';
            }
            else if ($rec['alarm'] % 60 == 0) {
                $alarm_value /= 60;
                $alarm_unit = 'H';
            }
            $alarm_value *= -1;
        }

        // convert recurrence rules into internal pseudo-vcalendar format
        if ($recurrence = $rec['recurrence']) {
            $rrule = array(
                'FREQ' => strtoupper($recurrence['cycle']),
                'INTERVAL' => intval($recurrence['interval']),
            );

            if ($recurrence['range-type'] == 'number')
                $rrule['COUNT'] = intval($recurrence['range']);
            else if ($recurrence['range-type'] == 'date')
                $rrule['UNTIL'] = $recurrence['range'];

            if ($recurrence['day']) {
                $byday = array();
                $prefix = ($rrule['FREQ'] == 'MONTHLY' || $rrule['FREQ'] == 'YEARLY') ? intval($recurrence['daynumber'] ? $recurrence['daynumber'] : 1) : '';
                foreach ($recurrence['day'] as $day)
                    $byday[] = $prefix . substr(strtoupper($day), 0, 2);
                $rrule['BYDAY'] = join(',', $byday);
            }
            if ($recurrence['daynumber']) {
                if ($recurrence['type'] == 'monthday' || $recurrence['type'] == 'daynumber')
                    $rrule['BYMONTHDAY'] = $recurrence['daynumber'];
                else if ($recurrence['type'] == 'yearday')
                    $rrule['BYYEARDAY'] = $recurrence['daynumber'];
            }
            if ($recurrence['month']) {
                $monthmap = array_flip($this->kolab2_monthmap);
                $rrule['BYMONTH'] = strtolower($monthmap[$recurrence['month']]);
            }

            if ($recurrence['exclusion']) {
                foreach ((array)$recurrence['exclusion'] as $excl)
                    $rrule['EXDATE'][] = strtotime($excl . date(' H:i:s', $rec['start-date']));  // use time of event start
            }
        }

        $attendees = array();
        if ($rec['organizer']) {
            $attendees[] = array(
                'role' => 'ORGANIZER',
                'name' => $rec['organizer']['display-name'],
                'email' => $rec['organizer']['smtp-address'],
                'status' => 'ACCEPTED',
            );
            $_attendees .= $rec['organizer']['display-name'] . ' ' . $rec['organizer']['smtp-address'] . ' ';
        }

        foreach ((array)$rec['attendee'] as $attendee) {
            $attendees[] = array(
                'role' => $this->kolab2_rolemap[$attendee['role']],
                'name' => $attendee['display-name'],
                'email' => $attendee['smtp-address'],
                'status' => $this->kolab2_statusmap[$attendee['status']],
                'rsvp' => $attendee['request-response'],
            );
            $_attendees .= $rec['organizer']['display-name'] . ' ' . $rec['organizer']['smtp-address'] . ' ';
        }

        $this->data = array(
            'uid' => $rec['uid'],
            'title' => $rec['summary'],
            'location' => $rec['location'],
            'description' => $rec['body'],
            'start' => new DateTime('@'.$rec['start-date']),
            'end'   => new DateTime('@'.$rec['end-date']),
            'allday' => $allday,
            'recurrence' => $rrule,
            'alarms' => $alarm_value . $alarm_unit,
            'categories' => explode(',', $rec['categories']),
            'attachments' => $attachments,
            'attendees' => $attendees,
            'free_busy' => $rec['show-time-as'],
            'priority' => $rec['priority'],
            'sensitivity' => $rec['sensitivity'],
            'changed' => $rec['last-modification-date'],
        );

        // assign current timezone to event start/end
        $this->data['start']->setTimezone(self::$timezone);
        $this->data['end']->setTimezone(self::$timezone);
    }
}
