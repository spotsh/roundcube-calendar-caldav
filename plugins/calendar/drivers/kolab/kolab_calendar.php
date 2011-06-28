<?php
/*
 +-------------------------------------------------------------------------+
 | Kolab calendar storage class                                            |
 |                                                                         |
 | This program is free software; you can redistribute it and/or modify    |
 | it under the terms of the GNU General Public License version 2          |
 | as published by the Free Software Foundation.                           |
 |                                                                         |
 | PURPOSE:                                                                |
 |   Storage object for a single calendar folder on Kolab                  |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                          |
 | Author: Aleksander Machniak <machniak@kolabsys.com>                     |
 +-------------------------------------------------------------------------+
*/

class kolab_calendar
{
  public $id;
  public $ready = false;
  public $readonly = true;

  private $cal;
  private $storage;
  private $events;
  private $id2uid;
  private $imap_folder = 'INBOX/Calendar';
  private $namespace;
  private $sensitivity_map = array('public', 'private', 'confidential');
  private $priority_map = array('low', 'normal', 'high');


  private $fieldmap = array(
  // kolab       => roundcube
  	'summary' => 'title',
  	'location'=>'location',
  	'body'=>'description',
  	'categories'=>'categories',
  	'start-date'=>'start',
  	'end-date'=>'end',
  	'sensitivity'=>'sensitivity',
  	'show-time-as' => 'free_busy',
  	'alarm','alarms'
    );

  /**
   * Default constructor
   */
  public function __construct($imap_folder, $calendar)
  {
    $this->cal = $calendar;

    if (strlen($imap_folder))
      $this->imap_folder = $imap_folder;

    // ID is derrived from folder name
    $this->id = rcube_kolab::folder_id($this->imap_folder);

    // fetch objects from the given IMAP folder
    $this->storage = rcube_kolab::get_storage($this->imap_folder);

    $this->ready = !PEAR::isError($this->storage);

    // Set readonly and editable flags according to folder permissions
    if ($this->ready) {
      if ($this->get_owner() == $_SESSION['username']) {
        $this->readonly = false;
      }
      else {
        $acl = $this->storage->_folder->getACL();
        $acl = $acl[$_SESSION['username']];
        if (strpos($acl, 'i') !== false)
          $this->readonly = false;
      }
    }
  }


  /**
   * Getter for a nice and human readable name for this calendar
   * See http://wiki.kolab.org/UI-Concepts/Folder-Listing for reference
   *
   * @return string Name of this calendar
   */
  public function get_name()
  {
    $folder = rcube_kolab::object_name($this->imap_folder, $this->namespace);
    return $folder;
  }


  /**
   * Getter for the IMAP folder name
   *
   * @return string Name of the IMAP folder
   */
  public function get_realname()
  {
    return $this->imap_folder;
  }


  /**
   * Getter for the IMAP folder owner
   *
   * @return string Name of the folder owner
   */
  public function get_owner()
  {
    return $this->storage->_folder->getOwner();
  }


  /**
   * Getter for the name of the namespace to which the IMAP folder belongs
   *
   * @return string Name of the namespace (personal, other, shared)
   */
  public function get_namespace()
  {
    if ($this->namespace === null) {
      $this->namespace = rcube_kolab::folder_namespace($this->imap_folder);
    }
    return $this->namespace;
  }


  /**
   * Getter for the top-end calendar folder name (not the entire path)
   *
   * @return string Name of this calendar
   */
  public function get_foldername()
  {
    $parts = explode('/', $this->imap_folder);
    return rcube_charset_convert(end($parts), 'UTF7-IMAP');
  }

  /**
   * Return color to display this calendar
   */
  public function get_color()
  {
    // Store temporarily calendar color in user prefs (will be changed)
    $prefs = $this->cal->rc->config->get('kolab_calendars', array());

    if (!empty($prefs[$this->id]) && !empty($prefs[$this->id]['color']))
      return $prefs[$this->id]['color'];

    return 'cc0000';
  }


  /**
   * Getter for a single event object
   */
  public function get_event($id)
  {
    $this->_fetch_events();
    return $this->events[$id];
  }


  /**
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  string  Search query (optional)
   * @return array A list of event records
   */
  public function list_events($start, $end, $search = null)
  {
    // use Horde classes to compute recurring instances
    require_once 'Horde/Date/Recurrence.php';
    
    $this->_fetch_events();
    
    $events = array();
    foreach ($this->events as $id => $event) {
      // TODO: filter events by search query
     
      if (!empty($search)) {
        
      }
      
      // list events in requested time window
      if ($event['start'] <= $end && $event['end'] >= $start) {
        $events[] = $event;
      }
      
      // resolve recurring events (maybe move to _fetch_events() for general use?)
      if ($event['recurrence']) {
      	
        $recurrence = new Horde_Date_Recurrence($event['start']);
        $recurrence->fromRRule20(calendar::to_rrule($event['recurrence']));
        
        foreach ((array)$event['recurrence']['EXDATE'] as $exdate)
          $recurrence->addException(date('Y', $exdate), date('n', $exdate), date('j', $exdate));
        
        $duration = $event['end'] - $event['start'];
        $next = new Horde_Date($event['start']);
        while ($next = $recurrence->nextActiveRecurrence(array('year' => $next->year, 'month' => $next->month, 'mday' => $next->mday + 1, 'hour' => $next->hour, 'min' => $next->min, 'sec' => $next->sec))) {
          $rec_start = $next->timestamp();
          $rec_end = $rec_start + $duration;
          
          // add to output if in range
          if ($rec_start <= $end && $rec_end >= $start) {
            $rec_event = $event;
            $rec_event['recurrence_id'] = $event['id'];
            $rec_event['start'] = $rec_start;
            $rec_event['end'] = $rec_end;
            $events[] = $rec_event;
          }
          else if ($rec_start > $end)  // stop loop if out of range
            break;
        }
      }
    }
    
    return $events;
  }


  /**
   * Create a new event record
   *
   * @see Driver:new_event()
   * 
   * @return mixed The created record ID on success, False on error
   */
  public function insert_event($event)
  {
  	if (!is_array($event))
            return false;

	//generate new event from RC input
	$object = $this->_from_rcube_event($event);

	//generate new UID
	$object['uid'] = $this->storage->generateUID();

	$saved = $this->storage->save($object);

    return $saved;
  }

  /**
   * Update a specific event record
   *
   * @see Driver:new_event()
   * @return boolean True on success, False on error
   */

  public function update_event($event)
  {
    $updated = false;
    $old = $this->storage->getObject($event['id']);
    $object = array_merge($old, $this->_from_rcube_event($event));
    $saved = $this->storage->save($object, $event['id']);
    
    if (PEAR::isError($saved)) {
      raise_error(array(
        'code' => 600, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Error saving contact object to Kolab server:" . $saved->getMessage()),
      true, false);
    }
    else {
      $updated = true;
    }
      
    // delete alarm settings in local database
    if ($updated && ($old['alarm'] != $object['alarm'] || $old['start-date'] != $object['start-date'])) {
      $query = $this->cal->rc->db->query(
        "DELETE FROM kolab_alarms
         WHERE event_id=?",
        $event['id']
      );
    }
    
    return $updated;
  }

	/**
	   * Delete an event record
	   *
	   * @see Driver:remove_event()
	   * @return boolean True on success, False on error
	   */

  public function delete_event($event)
  {
			
	 $deleted = false;
	 $deleteme = $this->storage->delete($event['id']);
            if (PEAR::isError($deleteme)) {
                raise_error(array(
                  'code' => 600, 'type' => 'php',
                  'file' => __FILE__, 'line' => __LINE__,
                  'message' => "Error saving contact object to Kolab server:" . $deleteme->getMessage()),
                true, false);
            }
            else {
               $deleted = true;
            }
	 
    return $deleted;
  }


  /**
   * Simply fetch all records and store them in private member vars
   * We thereby rely on cahcing done by the Horde classes
   */
  private function _fetch_events()
  {
    if (!isset($this->events)) {
      $this->events = array();
      foreach ((array)$this->storage->getObjects() as $record) {
        $event = $this->_to_rcube_event($record);
        $this->events[$event['id']] = $event;
      }
    }
  }

  /**
   * Convert from Kolab_Format to internal representation
   */
  private function _to_rcube_event($rec)
  {
    $start_time = date('H:i:s', $rec['start-date']);
    $allday = $start_time == '00:00:00' && $start_time == date('H:i:s', $rec['end-date']);
    if ($allday) {  // in Roundcube all-day events only go until 23:59:59 of the last day
      $rec['end-date']--;
      $rec['end-date'] -= $this->cal->timezone * 3600 - date('Z');   // shift 00 times from server's timezone to user's timezone
      $rec['start-date'] -= $this->cal->timezone * 3600 - date('Z');  // because generated with mktime() in Horde_Kolab_Format_Date::decodeDate()
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
      if ($rec['month']) {
        $monthmap = array('january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12);
        $rrule['BYMONTH'] = strtolower($monthmap[$recurrence['month']]);
      }
      
      if ($recurrence['exclusion']) {
        foreach ((array)$recurrence['exclusion'] as $excl)
          $rrule['EXDATE'][] = strtotime($excl);
      }
    }
    
    $sensitivity_map = array_flip($this->sensitivity_map);
    $priority_map = array_flip($this->priority_map);
    
    return array(
      'id' => $rec['uid'],
      'uid' => $rec['uid'],
      'title' => $rec['summary'],
      'location' => $rec['location'],
      'description' => $rec['body'],
      'start' => $rec['start-date'],
      'end' => $rec['end-date'],
      'allday' => $allday,
      'recurrence' => $rrule,
      '_alarm' => $rec['alarm'],
      'alarms' => $alarm_value . $alarm_unit,
      'categories' => $rec['categories'],
      'free_busy' => $rec['show-time-as'],
      'priority' => isset($priority_map[$rec['priority']]) ? $priority_map[$rec['priority']] : 1,
      'sensitivity' => $sensitivity_map[$rec['sensitivity']],
      'calendar' => $this->id,
    );
  }

   /**
   * Convert the given event record into a data structure that can be passed to Kolab_Storage backend for saving
   * (opposite of self::_to_rcube_event())
   */
  private function _from_rcube_event($event)
  {
    $priority_map = $this->priority_map;
    $daymap = array('MO'=>'monday','TU'=>'tuesday','WE'=>'wednesday','TH'=>'thursday','FR'=>'friday','SA'=>'saturday','SU'=>'sunday');
	
		
	$object = array(
		// kolab       => roundcube
	  	'summary' => $event['title'],
	  	'location'=> $event['location'],
	  	'body'=> $event['description'],
	  	'categories'=> $event['categories'],
	  	'start-date'=>$event['start'],
	  	'end-date'=>$event['end'],
	  	'sensitivity'=>$this->sensitivity_map[$event['sensitivity']],
	  	'show-time-as' => $event['free_busy'],
	  	'priority' => isset($priority_map[$event['priority']]) ? $priority_map[$event['priority']] : 1
  	  	 
	);
	
	//handle alarms
	if ($event['alarms']) 	{

		//get the value
     	$alarmbase = explode(":",$event['alarms']);
		
		//get number only
		$avalue = preg_replace('/[^0-9]/', '', $alarmbase[0]); 
	
		  if(preg_match("/H/",$alarmbase[0]))
		  {
		  	$object['alarm'] = $avalue*60;
			
		  }else if (preg_match("/D/",$alarmbase[0]))
		  	{
		  		$object['alarm'] = $avalue*24*60;
				
		  	}else
				{
					//minutes
					$object['alarm'] = $avalue;
				}
	    }
	
	//recurr object/array
	if (count($event['recurrence'])>1){
		
		$ra = $event['recurrence'];
		
		//Frequency abd interval
		$object['recurrence']['cycle'] = strtolower($ra['FREQ']);
		$object['recurrence']['interval'] = intval($ra['INTERVAL']);
		
		//Range Type
		if($ra['UNTIL']){
		  $object['recurrence']['range-type']='date';
		  $object['recurrence']['range']=$ra['UNTIL'];
		}
		if($ra['COUNT']){
		  $object['recurrence']['range-type']='number';
		  $object['recurrence']['range']=$ra['COUNT'];
		}
		//weekly 
		
		if ($ra['FREQ']=='WEEKLY'){
			
			$weekdays = split(",",$ra['BYDAY']);
			foreach ($weekdays as $days){
			 $weekly[]=$daymap[$days]; 	
			}
			
			$object['recurrence']['day']=$weekly;
			}
		
		//monthly (temporary hack to follow current Horde logic)
		if ($ra['FREQ']=='MONTHLY'){
			
			if($ra['BYDAY']=='NaN'){
					      
			   	  		   
			   $object['recurrence']['daynumber']=1;
			   $object['recurrence']['day']=array(date('L',$event['start']));
			   $object['recurrence']['cycle']='monthly';
			   $object['recurrence']['type']='weekday';
			  
			  
		      }
				else {
					$object['recurrence']['daynumber']=date('j',$event['start']);
					$object['recurrence']['cycle']='monthly';
					$object['recurrence']['type']="daynumber";
				}
			
		}
	
	//year
	
		
	
	
	  //exclusion
	  $object['recurrence']['type']=array(split(',',$ra['UNTIL']));
	    
	 }	

	// whole day event
	if ($event['allday']) {
		$object['end-date'] += 60;  // end is at 23:59 => jump to the next day
		$object['end-date'] += $this->cal->timezone * 3600 - date('Z');   // shift 00 times from user's timezone to server's timezone 
		$object['start-date'] += $this->cal->timezone * 3600 - date('Z');  // because Horde_Kolab_Format_Date::encodeDate() uses strftime()
		$object['_is_all_day'] = 1;
	}

		
	return $object;
  }

 

}
