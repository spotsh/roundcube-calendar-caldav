<?php

/**
 * Recurrence computation class for the Calendar plugin
 *
 * Uitility class to compute instances of recurring events.
 *
 * @version 0.7-beta
 * @author Thomas Bruederli <roundcube@gmail.com>
 * @package calendar
 *
 * Copyright (C) 2011, Kolab Systems AG <contact@kolabsys.com>
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
class calendar_recurrence
{
  private $cal;
  private $event;
  private $engine;
  private $tz_offset = 0;
  private $dst_start = false;
  private $hour = 0;

  /**
   * Default constructor
   *
   * @param object calendar The calendar plugin instance
   * @param array The event object to operate on
   */
  function __construct($cal, $event)
  {
    $this->cal = $cal;

    // use Horde classes to compute recurring instances
    // TODO: replace with something that has less than 6'000 lines of code
    require_once($this->cal->home . '/lib/Horde_Date_Recurrence.php');

    $this->event = $event;
    $this->engine = new Horde_Date_Recurrence($event['start']);
    $this->engine->fromRRule20(calendar::to_rrule($event['recurrence']));

    if (is_array($event['recurrence']['EXDATE'])) {
      foreach ($event['recurrence']['EXDATE'] as $exdate)
        $this->engine->addException(date('Y', $exdate), date('n', $exdate), date('j', $exdate));
    }

    $this->tz_offset = $event['allday'] ? $this->cal->gmt_offset - date('Z') : 0;
    $this->next = new Horde_Date($event['start'] + $this->tz_offset);  # shift all-day times to server timezone because computation operates in local TZ
    $this->dst_start = $this->next->format('I');
    $this->hour = $this->next->hour;
  }

  /**
   * Get timestamp of the next occurence of this event
   *
   * @return mixed Unix timestamp or False if recurrence ended
   */
  public function next_start()
  {
    $time = false;
    if ($this->next && ($next = $this->engine->nextActiveRecurrence(array('year' => $this->next->year, 'month' => $this->next->month, 'mday' => $this->next->mday + 1, 'hour' => $this->next->hour, 'min' => $this->next->min, 'sec' => $this->next->sec)))) {
      if ($this->event['allday']) {
        $next->hour = $this->hour;  # fix time for all-day events
        $next->min = 0;
      }
      # $dst_diff = ($this->dst_start - $next->format('I')) * 3600;  # consider difference in daylight saving between base event and recurring instance
      $time = $next->timestamp() - $this->tz_offset;
      $this->next = $next;
    }

    return $time;
  }

}
