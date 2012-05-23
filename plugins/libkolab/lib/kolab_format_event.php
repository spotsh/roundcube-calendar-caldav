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

    protected $read_func = 'kolabformat::readEvent';
    protected $write_func = 'kolabformat::writeEvent';

    public static $fulltext_cols = array('title', 'description', 'location', 'attendees:name', 'attendees:email');

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

    private $rrule_type_map = array(
        'MINUTELY' => RecurrenceRule::Minutely,
        'HOURLY' => RecurrenceRule::Hourly,
        'DAILY' => RecurrenceRule::Daily,
        'WEEKLY' => RecurrenceRule::Weekly,
        'MONTHLY' => RecurrenceRule::Monthly,
        'YEARLY' => RecurrenceRule::Yearly,
    );

    private $weekday_map = array(
        'MO' => kolabformat::Monday,
        'TU' => kolabformat::Tuesday,
        'WE' => kolabformat::Wednesday,
        'TH' => kolabformat::Thursday,
        'FR' => kolabformat::Friday,
        'SA' => kolabformat::Saturday,
        'SU' => kolabformat::Sunday,
    );

    private $alarm_type_map = array(
        'DISPLAY' => Alarm::DisplayAlarm,
        'EMAIL' => Alarm::EMailAlarm,
        'AUDIO' => Alarm::AudioAlarm,
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
    function __construct($xmldata = null)
    {
        $this->obj = new Event;
        $this->xmldata = $xmldata;
    }

    /**
     * Set contact properties to the kolabformat object
     *
     * @param array  Contact data as hash array
     */
    public function set(&$object)
    {
        $this->init();

        // set some automatic values if missing
        if (!$this->obj->created()) {
            if (!empty($object['created']))
                $object['created'] = new DateTime('now', self::$timezone);
            $this->obj->setCreated(self::get_datetime($object['created']));
        }

        if (!empty($object['uid']))
            $this->obj->setUid($object['uid']);

        // increment sequence
        $this->obj->setSequence($this->obj->sequence()+1);

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

                if ($att->isValid()) {
                    $attendees->push($att);
                }
                else {
                    raise_error(array(
                        'code' => 600, 'type' => 'php',
                        'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Invalid event attendee: " . json_encode($attendee),
                    ), true);
                }
            }
        }
        $this->obj->setOrganizer($organizer);
        $this->obj->setAttendees($attendees);

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
                raise_error(array(
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

        // save attachments
        $vattach = new vectorattachment;
        foreach ((array)$object['_attachments'] as $name => $attr) {
            if (empty($attr))
                continue;
            $attach = new Attachment;
            $attach->setLabel($name);
            $attach->setUri('cid:' . $name, $attr['mimetype']);
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
     * Convert the Contact object into a hash array data structure
     *
     * @return array  Contact data as hash array
     */
    public function to_array()
    {
        // return cached result
        if (!empty($this->data))
            return $this->data;

        $this->init();

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
                $object['recurrence']['UNTIL'] = $until->format('U');
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

            if ($exceptions = $this->obj->exceptionDates()) {
                for ($i=0; $i < $exceptions->size(); $i++) {
                    if ($exdate = self::php_datetime($exceptions->get($i)))
                        $object['recurrence']['EXDATE'][] = $exdate->format('U');
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

        // handle attachments
        $vattach = $this->obj->attachments();
        for ($i=0; $i < $vattach->size(); $i++) {
            $attach = $vattach->get($i);

            // skip cid: attachments which are mime message parts handled by kolab_storage_folder
            if (substr($attach->uri(), 0, 4) != 'cid') {
                $name = $attach->label();
                $data = $attach->data();
                $object['_attachments'][$name] = array(
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
