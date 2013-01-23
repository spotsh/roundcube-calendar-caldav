<?php

/**
 * Xcal based Kolab format class wrapping libkolabxml bindings
 *
 * Base class for xcal-based Kolab groupware objects such as event, todo, journal
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

abstract class kolab_format_xcal extends kolab_format
{
    public $CTYPE = 'application/calendar+xml';

    public static $fulltext_cols = array('title', 'description', 'location', 'attendees:name', 'attendees:email', 'categories');

    protected $sensitivity_map = array(
        'public'       => kolabformat::ClassPublic,
        'private'      => kolabformat::ClassPrivate,
        'confidential' => kolabformat::ClassConfidential,
    );

    protected $role_map = array(
        'REQ-PARTICIPANT' => kolabformat::Required,
        'OPT-PARTICIPANT' => kolabformat::Optional,
        'NON-PARTICIPANT' => kolabformat::NonParticipant,
        'CHAIR' => kolabformat::Chair,
    );

    protected $rrule_type_map = array(
        'MINUTELY' => RecurrenceRule::Minutely,
        'HOURLY' => RecurrenceRule::Hourly,
        'DAILY' => RecurrenceRule::Daily,
        'WEEKLY' => RecurrenceRule::Weekly,
        'MONTHLY' => RecurrenceRule::Monthly,
        'YEARLY' => RecurrenceRule::Yearly,
    );

    protected $weekday_map = array(
        'MO' => kolabformat::Monday,
        'TU' => kolabformat::Tuesday,
        'WE' => kolabformat::Wednesday,
        'TH' => kolabformat::Thursday,
        'FR' => kolabformat::Friday,
        'SA' => kolabformat::Saturday,
        'SU' => kolabformat::Sunday,
    );

    protected $alarm_type_map = array(
        'DISPLAY' => Alarm::DisplayAlarm,
        'EMAIL' => Alarm::EMailAlarm,
        'AUDIO' => Alarm::AudioAlarm,
    );

    private $status_map = array(
        'NEEDS-ACTION' => kolabformat::StatusNeedsAction,
        'IN-PROCESS'   => kolabformat::StatusInProcess,
        'COMPLETED'    => kolabformat::StatusCompleted,
        'CANCELLED'    => kolabformat::StatusCancelled,
    );

    protected $part_status_map = array(
        'UNKNOWN' => kolabformat::PartNeedsAction,
        'NEEDS-ACTION' => kolabformat::PartNeedsAction,
        'TENTATIVE' => kolabformat::PartTentative,
        'ACCEPTED' => kolabformat::PartAccepted,
        'DECLINED' => kolabformat::PartDeclined,
        'DELEGATED' => kolabformat::PartDelegated,
      );


    /**
     * Convert common xcard properties into a hash array data structure
     *
     * @param array Additional data for merge
     *
     * @return array  Object data as hash array
     */
    public function to_array($data = array())
    {
        $status_map = array_flip($this->status_map);
        $sensitivity_map = array_flip($this->sensitivity_map);

        $object = array(
            'uid'         => $this->obj->uid(),
            'created'     => self::php_datetime($this->obj->created()),
            'changed'     => self::php_datetime($this->obj->lastModified()),
            'sequence'    => intval($this->obj->sequence()),
            'title'       => $this->obj->summary(),
            'location'    => $this->obj->location(),
            'description' => $this->obj->description(),
            'status'      => $this->status_map[$this->obj->status()],
            'sensitivity' => $sensitivity_map[$this->obj->classification()],
            'priority'    => $this->obj->priority(),
            'categories'  => self::vector2array($this->obj->categories()),
            'start'       => self::php_datetime($this->obj->start()),
        );

        // read organizer and attendees
        if (($organizer = $this->obj->organizer()) && ($organizer->email() || $organizer->name())) {
            $object['organizer'] = array(
                'email' => $organizer->email(),
                'name' => $organizer->name(),
            );
        }

        $role_map = array_flip($this->role_map);
        $part_status_map = array_flip($this->part_status_map);
        $attvec = $this->obj->attendees();
        for ($i=0; $i < $attvec->size(); $i++) {
            $attendee = $attvec->get($i);
            $cr = $attendee->contact();
            $object['attendees'][] = array(
                'role' => $role_map[$attendee->role()],
                'status' => $part_status_map[$attendee->partStat()],
                'rsvp' => $attendee->rsvp(),
                'email' => $cr->email(),
                'name' => $cr->name(),
            );
        }

        // read recurrence rule
        if (($rr = $this->obj->recurrenceRule()) && $rr->isValid()) {
            $rrule_type_map = array_flip($this->rrule_type_map);
            $object['recurrence'] = array('FREQ' => $rrule_type_map[$rr->frequency()]);

            if ($intvl = $rr->interval())
                $object['recurrence']['INTERVAL'] = $intvl;

            if (($count = $rr->count()) && $count > 0) {
                $object['recurrence']['COUNT'] = $count;
            }
            else if ($until = self::php_datetime($rr->end())) {
                $until->setTime($object['start']->format('G'), $object['start']->format('i'), 0);
                $object['recurrence']['UNTIL'] = $until;
            }

            if (($byday = $rr->byday()) && $byday->size()) {
                $weekday_map = array_flip($this->weekday_map);
                $weekdays = array();
                for ($i=0; $i < $byday->size(); $i++) {
                    $daypos = $byday->get($i);
                    $prefix = $daypos->occurence();
                    $weekdays[] = ($prefix ? $prefix : '') . $weekday_map[$daypos->weekday()];
                }
                $object['recurrence']['BYDAY'] = join(',', $weekdays);
            }

            if (($bymday = $rr->bymonthday()) && $bymday->size()) {
                $object['recurrence']['BYMONTHDAY'] = join(',', self::vector2array($bymday));
            }

            if (($bymonth = $rr->bymonth()) && $bymonth->size()) {
                $object['recurrence']['BYMONTH'] = join(',', self::vector2array($bymonth));
            }

            if ($exdates = $this->obj->exceptionDates()) {
                for ($i=0; $i < $exdates->size(); $i++) {
                    if ($exdate = self::php_datetime($exdates->get($i)))
                        $object['recurrence']['EXDATE'][] = $exdate;
                }
            }
        }

        // read alarm
        $valarms = $this->obj->alarms();
        $alarm_types = array_flip($this->alarm_type_map);
        for ($i=0; $i < $valarms->size(); $i++) {
            $alarm = $valarms->get($i);
            $type = $alarm_types[$alarm->type()];

            if ($type == 'DISPLAY' || $type == 'EMAIL') {  // only DISPLAY and EMAIL alarms are supported
                if ($start = self::php_datetime($alarm->start())) {
                    $object['alarms'] = '@' . $start->format('U');
                }
                else if ($offset = $alarm->relativeStart()) {
                    $value = $alarm->relativeTo() == kolabformat::End ? '+' : '-';
                    if      ($w = $offset->weeks())     $value .= $w . 'W';
                    else if ($d = $offset->days())      $value .= $d . 'D';
                    else if ($h = $offset->hours())     $value .= $h . 'H';
                    else if ($m = $offset->minutes())   $value .= $m . 'M';
                    else if ($s = $offset->seconds())   $value .= $s . 'S';
                    else continue;

                    $object['alarms'] = $value;
                }
                $object['alarms']  .= ':' . $type;
                break;
            }
        }

        return $object;
    }


    /**
     * Set common xcal properties to the kolabformat object
     *
     * @param array  Event data as hash array
     */
    public function set(&$object)
    {
        $is_new = !$this->obj->uid();

        // set some automatic values if missing
        if (!$this->obj->created()) {
            if (!empty($object['created']))
                $object['created'] = new DateTime('now', self::$timezone);
            $this->obj->setCreated(self::get_datetime($object['created']));
        }

        if (!empty($object['uid']))
            $this->obj->setUid($object['uid']);

        $object['changed'] = new DateTime('now', self::$timezone);
        $this->obj->setLastModified(self::get_datetime($object['changed'], new DateTimeZone('UTC')));

        // increment sequence on updates
        $object['sequence'] = !$is_new ? $this->obj->sequence()+1 : 0;
        $this->obj->setSequence($object['sequence']);

        $this->obj->setSummary($object['title']);
        $this->obj->setLocation($object['location']);
        $this->obj->setDescription($object['description']);
        $this->obj->setPriority($object['priority']);
        $this->obj->setClassification($this->sensitivity_map[$object['sensitivity']]);
        $this->obj->setCategories(self::array2vector($object['categories']));

        // process event attendees
        $attendees = new vectorattendee;
        foreach ((array)$object['attendees'] as $attendee) {
            if ($attendee['role'] == 'ORGANIZER') {
                $object['organizer'] = $attendee;
            }
            else {
                $cr = new ContactReference(ContactReference::EmailReference, $attendee['email']);
                $cr->setName($attendee['name']);

                $att = new Attendee;
                $att->setContact($cr);
                $att->setPartStat($this->status_map[$attendee['status']]);
                $att->setRole($this->role_map[$attendee['role']] ? $this->role_map[$attendee['role']] : kolabformat::Required);
                $att->setRSVP((bool)$attendee['rsvp']);

                if ($att->isValid()) {
                    $attendees->push($att);
                }
                else {
                    rcube::raise_error(array(
                        'code' => 600, 'type' => 'php',
                        'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Invalid event attendee: " . json_encode($attendee),
                    ), true);
                }
            }
        }
        $this->obj->setAttendees($attendees);

        if ($object['organizer']) {
            $organizer = new ContactReference(ContactReference::EmailReference, $object['organizer']['email']);
            $organizer->setName($object['organizer']['name']);
            $this->obj->setOrganizer($organizer);
        }

        // save recurrence rule
        if ($object['recurrence']) {
            $rr = new RecurrenceRule;
            $rr->setFrequency($this->rrule_type_map[$object['recurrence']['FREQ']]);

            if ($object['recurrence']['INTERVAL'])
                $rr->setInterval(intval($object['recurrence']['INTERVAL']));

            if ($object['recurrence']['BYDAY']) {
                $byday = new vectordaypos;
                foreach (explode(',', $object['recurrence']['BYDAY']) as $day) {
                    $occurrence = 0;
                    if (preg_match('/^([\d-]+)([A-Z]+)$/', $day, $m)) {
                        $occurrence = intval($m[1]);
                        $day = $m[2];
                    }
                    if (isset($this->weekday_map[$day]))
                        $byday->push(new DayPos($occurrence, $this->weekday_map[$day]));
                }
                $rr->setByday($byday);
            }

            if ($object['recurrence']['BYMONTHDAY']) {
                $bymday = new vectori;
                foreach (explode(',', $object['recurrence']['BYMONTHDAY']) as $day)
                    $bymday->push(intval($day));
                $rr->setBymonthday($bymday);
            }

            if ($object['recurrence']['BYMONTH']) {
                $bymonth = new vectori;
                foreach (explode(',', $object['recurrence']['BYMONTH']) as $month)
                    $bymonth->push(intval($month));
                $rr->setBymonth($bymonth);
            }

            if ($object['recurrence']['COUNT'])
                $rr->setCount(intval($object['recurrence']['COUNT']));
            else if ($object['recurrence']['UNTIL'])
                $rr->setEnd(self::get_datetime($object['recurrence']['UNTIL'], null, true));

            if ($rr->isValid()) {
                $this->obj->setRecurrenceRule($rr);

                // add exception dates (only if recurrence rule is valid)
                $exdates = new vectordatetime;
                foreach ((array)$object['recurrence']['EXDATE'] as $exdate)
                    $exdates->push(self::get_datetime($exdate, null, true));
                $this->obj->setExceptionDates($exdates);
            }
            else {
                rcube::raise_error(array(
                    'code' => 600, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Invalid event recurrence rule: " . json_encode($object['recurrence']),
                ), true);
            }
        }

        // save alarm
        $valarms = new vectoralarm;
        if ($object['alarms']) {
            list($offset, $type) = explode(":", $object['alarms']);

            if ($type == 'EMAIL') {  // email alarms implicitly go to event owner
                $recipients = new vectorcontactref;
                $recipients->push(new ContactReference(ContactReference::EmailReference, $object['_owner']));
                $alarm = new Alarm($object['title'], strval($object['description']), $recipients);
            }
            else {  // default: display alarm
                $alarm = new Alarm($object['title']);
            }

            if (preg_match('/^@(\d+)/', $offset, $d)) {
                $alarm->setStart(self::get_datetime($d[1], new DateTimeZone('UTC')));
            }
            else if (preg_match('/^([-+]?)(\d+)([SMHDW])/', $offset, $d)) {
                $days = $hours = $minutes = $seconds = 0;
                switch ($d[3]) {
                    case 'W': $days  = 7*intval($d[2]); break;
                    case 'D': $days    = intval($d[2]); break;
                    case 'H': $hours   = intval($d[2]); break;
                    case 'M': $minutes = intval($d[2]); break;
                    case 'S': $seconds = intval($d[2]); break;
                }
                $alarm->setRelativeStart(new Duration($days, $hours, $minutes, $seconds, $d[1] == '-'), $d[1] == '-' ? kolabformat::Start : kolabformat::End);
            }

            $valarms->push($alarm);
        }
        $this->obj->setAlarms($valarms);
    }

    /**
     * Callback for kolab_storage_cache to get words to index for fulltext search
     *
     * @return array List of words to save in cache
     */
    public function get_words()
    {
        $data = '';
        foreach (self::$fulltext_cols as $colname) {
            list($col, $field) = explode(':', $colname);

            if ($field) {
                $a = array();
                foreach ((array)$this->data[$col] as $attr)
                    $a[] = $attr[$field];
                $val = join(' ', $a);
            }
            else {
                $val = is_array($this->data[$col]) ? join(' ', $this->data[$col]) : $this->data[$col];
            }

            if (strlen($val))
                $data .= $val . ' ';
        }

        return array_unique(rcube_utils::normalize_string($data, true));
    }

}