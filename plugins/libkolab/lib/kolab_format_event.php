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

class kolab_format_event extends kolab_format
{
    public $CTYPE = 'application/calendar+xml';

    private $sensitivity_map = array(
        'public'       => kolabformat::ClassPublic,
        'private'      => kolabformat::ClassPrivate,
        'confidential' => kolabformat::ClassConfidential,
    );

    private $role_map = array(
        'REQ-PARTICIPANT' => kolabformat::Required,
        'OPT-PARTICIPANT' => kolabformat::Optional,
        'NON-PARTICIPANT' => kolabformat::NonParticipant,
        'CHAIR' => kolabformat::Chair,
    );

    private $status_map = array(
        'UNKNOWN' => kolabformat::PartNeedsAction,
        'NEEDS-ACTION' => kolabformat::PartNeedsAction,
        'TENTATIVE' => kolabformat::PartTentative,
        'ACCEPTED' => kolabformat::PartAccepted,
        'DECLINED' => kolabformat::PartDeclined,
        'DELEGATED' => kolabformat::PartDelegated,
      );

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
    function __construct()
    {
        $this->obj = new Event;
    }

    /**
     * Load Contact object data from the given XML block
     *
     * @param string XML data
     */
    public function load($xml)
    {
        $this->obj = kolabformat::readEvent($xml, false);
    }

    /**
     * Write Contact object data to XML format
     *
     * @return string XML data
     */
    public function write()
    {
        $xml = kolabformat::writeEvent($this->obj);
        parent::update_uid();
        return $xml;
    }

    /**
     * Set contact properties to the kolabformat object
     *
     * @param array  Contact data as hash array
     */
    public function set(&$object)
    {
        // set some automatic values if missing
        if (!$this->obj->created()) {
            if (!empty($object['created']))
                $object['created'] = new DateTime('now', self::$timezone);
            $this->obj->setCreated(self::get_datetime($object['created']));
        }

        if (!empty($object['uid']))
            $this->obj->setUid($object['uid']);

        // TODO: increase sequence
        // $this->obj->setSequence($this->obj->sequence()+1);

        // do the hard work of setting object values
        $this->obj->setStart(self::get_datetime($object['start'], null, $object['allday']));
        $this->obj->setEnd(self::get_datetime($object['end'], null, $object['allday']));

        $this->obj->setSummary($object['title']);
        $this->obj->setLocation($object['location']);
        $this->obj->setDescription($object['description']);
        $this->obj->setPriority($object['priority']);
        $this->obj->setClassification($this->sensitivity_map[$object['sensitivity']]);
        $this->obj->setCategories(self::array2vector($object['categories']));
        $this->obj->setTransparency($object['free_busy'] == 'free');

        $status = kolabformat::StatusUndefined;
        if ($object['free_busy'] == 'tentative')
            $status = kolabformat::StatusTentative;
        if ($object['cancelled'])
            $status = kolabformat::StatusCancelled;
        $this->obj->setStatus($status);

        // process event attendees
        $organizer = new ContactReference;
        $attendees = new vectorattendee;
        foreach ((array)$object['attendees'] as $attendee) {
            $cr = new ContactReference(ContactReference::EmailReference, $attendee['email']);
            $cr->setName($attendee['name']);

            if ($attendee['role'] == 'ORGANIZER') {
                $organizer = $cr;
            }
            else {
                $att = new Attendee;
                $att->setContact($cr);
                $att->setPartStat($this->status_map[$attendee['status']]);
                $att->setRole($this->role_map[$attendee['role']] ? $this->role_map[$attendee['role']] : kolabformat::Required);
                $att->setRSVP((bool)$attendee['rsvp']);

                if ($att->isValid())
                    $attendees->push($att);
            }
        }
        $this->obj->setOrganizer($organizer);
        $this->obj->setAttendees($attendees);

        // TODO: save recurrence rule

        // TODO: save alarm
        $valarms = new vectoralarm;
        if ($object['alarms']) {
          $alarm = new Alarm;
            list($duration, $type) = explode(":", $object['alarms']);
            
            $valarms->push($alarm);
        }
        $this->obj->setAlarms($valarms);

        // TODO: save attachments

        // cache this data
        unset($object['_formatobj']);
        $this->data = $object;
    }

    /**
     *
     */
    public function is_valid()
    {
        return $this->data || (is_object($this->obj) && $this->obj->isValid());
    }

    /**
     * Convert the Contact object into a hash array data structure
     *
     * @return array  Contact data as hash array
     */
    public function to_array()
    {
        // return cached result
        if (!empty($this->data))
            return $this->data;

        $sensitivity_map = array_flip($this->sensitivity_map);

        // read object properties
        $object = array(
            'uid'         => $this->obj->uid(),
            'changed'     => self::php_datetime($this->obj->lastModified()),
            'title'       => $this->obj->summary(),
            'location'    => $this->obj->location(),
            'description' => $this->obj->description(),
            'allday'      => $this->obj->start()->isDateOnly(),
            'start'       => self::php_datetime($this->obj->start()),
            'end'         => self::php_datetime($this->obj->end()),
            'categories'  => self::vector2array($this->obj->categories()),
            'free_busy'   => $this->obj->transparency() ? 'free' : 'busy',  // TODO: transparency is only boolean
            'sensitivity' => $sensitivity_map[$this->obj->classification()],
            'priority'    => $this->obj->priority(),
        );

        // status defines different event properties...
        $status = $this->obj->status();
        if ($status == kolabformat::StatusTentative)
          $object['free_busy'] = 'tentative';
        else if ($status == kolabformat::StatusCancelled)
          $objec['cancelled'] = true;

        // read organizer and attendees
        if ($organizer = $this->obj->organizer()) {
            $object['attendees'][] = array(
                'role' => 'ORGANIZER',
                'email' => $organizer->email(),
                'name' => $organizer->name(),
            );
        }

        $role_map = array_flip($this->role_map);
        $status_map = array_flip($this->status_map);
        $attvec = $this->obj->attendees();
        for ($i=0; $i < $attvec->size(); $i++) {
            $attendee = $attvec->get($i);
            $cr = $attendee->contact();
            $object['attendees'][] = array(
                'role' => $role_map[$attendee->role()],
                'status' => $status_map[$attendee->partStat()],
                'rsvp' => $attendee->rsvp(),
                'email' => $cr->email(),
                'name' => $cr->name(),
            );
        }

        // TODO: hanlde recurrence rules, alarms and attachments

        $this->data = $object;
        return $this->data;
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

        if ($allday) {  // in Roundcube all-day events go from 12:00 to 13:00
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
            'start' => $rec['start-date'],
            'end' => $rec['end-date'],
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
    }
}
