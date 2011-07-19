<?php
/*
 +-------------------------------------------------------------------------+
 | Calendar plugin for Roundcube                                           |
 | Version 0.3 beta                                                        |
 |                                                                         |
 | This program is free software; you can redistribute it and/or modify    |
 | it under the terms of the GNU General Public License version 2          |
 | as published by the Free Software Foundation.                           |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 |                                                                         |
 | You should have received a copy of the GNU General Public License along |
 | with this program; if not, write to the Free Software Foundation, Inc., |
 | 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.             |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Lazlo Westerhof <hello@lazlo.me>                                |
 |         Thomas Bruederli <roundcube@gmail.com>                          |
 +-------------------------------------------------------------------------+
*/

class calendar extends rcube_plugin
{
  public $task = '?(?!login|logout).*';
  public $rc;
  public $driver;
  public $home;  // declare public to be used in other classes
  public $urlbase;
  public $timezone;

  public $ical;
  public $ui;

  public $defaults = array(
    'calendar_default_view' => "agendaWeek",
    'calendar_date_format'  => "yyyy-MM-dd",
    'calendar_date_short'   => "M-d",
    'calendar_date_long'    => "MMM d yyyy",
    'calendar_date_agenda'  => "ddd MM-dd",
    'calendar_time_format'  => "HH:mm",
    'calendar_timeslots'    => 2,
    'calendar_first_day'    => 1,
    'calendar_first_hour'   => 6,
  );

  private $default_categories = array(
    'Personal' => 'c0c0c0',
    'Work'     => 'ff0000',
    'Family'   => '00ff00',
    'Holiday'  => 'ff6600',
  );

  /**
   * Plugin initialization.
   */
  function init()
  {
    $this->rc = rcmail::get_instance();

    $this->register_task('calendar', 'calendar');

    // load calendar configuration
    $this->load_config();

    // load localizations
    $this->add_texts('localization/', $this->rc->task == 'calendar' && (!$this->rc->action || $this->rc->action == 'print'));

    // set user's timezone
    if ($this->rc->config->get('timezone') === 'auto')
      $this->timezone = isset($_SESSION['timezone']) ? $_SESSION['timezone'] : date('Z');
    else
      $this->timezone = ($this->rc->config->get('timezone') + intval($this->rc->config->get('dst_active')));

    $this->gmt_offset = $this->timezone * 3600;

    require($this->home . '/lib/calendar_ui.php');
    $this->ui = new calendar_ui($this);

    // load Calendar user interface which includes jquery-ui
    if (!$this->rc->output->ajax_call && !$this->rc->output->env['framed']) {
      $this->require_plugin('jqueryui');

      $this->ui->init();

      // settings are required in every GUI step
      $this->rc->output->set_env('calendar_settings', $this->load_settings());
    }

    if ($this->rc->task == 'calendar' && $this->rc->action != 'save-pref') {
      if ($this->rc->action != 'upload') {
        $this->load_driver();

        // load iCalendar functions
        require($this->home . '/lib/calendar_ical.php');
        $this->ical = new calendar_ical($this->rc, $this->driver);
      }

      // register calendar actions
      $this->register_action('index', array($this, 'calendar_view'));
      $this->register_action('event', array($this, 'event_action'));
      $this->register_action('calendar', array($this, 'calendar_action'));
      $this->register_action('load_events', array($this, 'load_events'));
      $this->register_action('export_events', array($this, 'export_events'));
      $this->register_action('upload', array($this, 'attachment_upload'));
      $this->register_action('get-attachment', array($this, 'attachment_get'));
      $this->register_action('freebusy-status', array($this, 'freebusy_status'));
      $this->register_action('freebusy-times', array($this, 'freebusy_times'));
      $this->register_action('randomdata', array($this, 'generate_randomdata'));
      $this->register_action('print',array($this,'print_view'));

      // remove undo information...
      if ($undo = $_SESSION['calendar_event_undo']) {
        // ...after timeout
        $undo_time = $this->rc->config->get('undo_timeout', 0);
        if ($undo['ts'] < time() - $undo_time) {
          $this->rc->session->remove('calendar_event_undo');
          // @TODO: do EXPUNGE on kolab objects?
        }
      }
    }
    else if ($this->rc->task == 'settings') {
      // add hooks for Calendar settings
      $this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
      $this->add_hook('preferences_list', array($this, 'preferences_list'));
      $this->add_hook('preferences_save', array($this, 'preferences_save')); 
    }

    // add hook to display alarms
    $this->add_hook('keep_alive', array($this, 'keep_alive'));
  }

  /**
   * Helper method to load the backend driver according to local config
   */
  private function load_driver()
  {
    if (is_object($this->driver))
      return;
    
    $driver_name = $this->rc->config->get('calendar_driver', 'database');
    $driver_class = $driver_name . '_driver';

    require_once($this->home . '/drivers/calendar_driver.php');
    require_once($this->home . '/drivers/' . $driver_name . '/' . $driver_class . '.php');

    switch ($driver_name) {
      case "kolab":
        $this->require_plugin('kolab_core');
      default:
        $this->driver = new $driver_class($this);
        break;
      }
  }

  /**
   * Render the main calendar view from skin template
   */
  function calendar_view()
  {
    $this->rc->output->set_pagetitle($this->gettext('calendar'));

    // Add CSS stylesheets to the page header
    $this->ui->addCSS();

    // Add JS files to the page header
    $this->ui->addJS();

    $this->register_handler('plugin.calendar_css', array($this->ui, 'calendar_css'));
    $this->register_handler('plugin.calendar_list', array($this->ui, 'calendar_list'));
    $this->register_handler('plugin.calendar_select', array($this->ui, 'calendar_select'));
    $this->register_handler('plugin.category_select', array($this->ui, 'category_select'));
    $this->register_handler('plugin.freebusy_select', array($this->ui, 'freebusy_select'));
    $this->register_handler('plugin.priority_select', array($this->ui, 'priority_select'));
    $this->register_handler('plugin.sensitivity_select', array($this->ui, 'sensitivity_select'));
    $this->register_handler('plugin.alarm_select', array($this->ui, 'alarm_select'));
    $this->register_handler('plugin.snooze_select', array($this->ui, 'snooze_select'));
    $this->register_handler('plugin.recurrence_form', array($this->ui, 'recurrence_form'));
    $this->register_handler('plugin.attachments_form', array($this->ui, 'attachments_form'));
    $this->register_handler('plugin.attachments_list', array($this->ui, 'attachments_list'));
    $this->register_handler('plugin.attendees_list', array($this->ui, 'attendees_list'));
    $this->register_handler('plugin.attendees_form', array($this->ui, 'attendees_form'));
    $this->register_handler('plugin.edit_recurring_warning', array($this->ui, 'recurring_event_warning'));
    $this->register_handler('plugin.searchform', array($this->rc->output, 'search_form'));  // use generic method from rcube_template

    $this->rc->output->add_label('low','normal','high','delete','cancel','uploading','noemailwarning');

    $this->rc->output->send("calendar.calendar");
  }

  /**
   * Handler for preferences_sections_list hook.
   * Adds Calendar settings sections into preferences sections list.
   *
   * @param array Original parameters
   * @return array Modified parameters
   */
  function preferences_sections_list($p)
  {
    $p['list']['calendar'] = array(
      'id' => 'calendar', 'section' => $this->gettext('calendar'),
    );

    return $p;
  }

  /**
   * Handler for preferences_list hook.
   * Adds options blocks into Calendar settings sections in Preferences.
   *
   * @param array Original parameters
   * @return array Modified parameters
   */
  function preferences_list($p)
  {
    if ($p['section'] == 'calendar') {
      $this->load_driver();

      $p['blocks']['view']['name'] = $this->gettext('mainoptions');

      $field_id = 'rcmfd_default_view';
      $select = new html_select(array('name' => '_default_view', 'id' => $field_id));
      $select->add($this->gettext('day'), "agendaDay");
      $select->add($this->gettext('week'), "agendaWeek");
      $select->add($this->gettext('month'), "month");
      $select->add($this->gettext('agenda'), "table");
      $p['blocks']['view']['options']['default_view'] = array(
        'title' => html::label($field_id, Q($this->gettext('default_view'))),
        'content' => $select->show($this->rc->config->get('calendar_default_view', "agendaWeek")),
      );
/*
      $field_id = 'rcmfd_time_format';
      $choices = array('HH:mm', 'H:mm', 'h:mmt');
      $select = new html_select(array('name' => '_time_format', 'id' => $field_id));
      $select->add($choices);
      $p['blocks']['view']['options']['time_format'] = array(
        'title' => html::label($field_id, Q($this->gettext('time_format'))),
        'content' => $select->show($this->rc->config->get('calendar_time_format', "HH:mm")),
      );
*/
      $field_id = 'rcmfd_timeslot';
      $choices = array('1', '2', '3', '4', '6');
      $select = new html_select(array('name' => '_timeslots', 'id' => $field_id));
      $select->add($choices);
      $p['blocks']['view']['options']['timeslots'] = array(
        'title' => html::label($field_id, Q($this->gettext('timeslots'))),
        'content' => $select->show($this->rc->config->get('calendar_timeslots', 2)),
      );
      
      $field_id = 'rcmfd_firstday';
      $select = new html_select(array('name' => '_first_day', 'id' => $field_id));
      $select->add(rcube_label('sunday'), '0');
      $select->add(rcube_label('monday'), '1');
      $select->add(rcube_label('tuesday'), '2');
      $select->add(rcube_label('wednesday'), '3');
      $select->add(rcube_label('thursday'), '4');
      $select->add(rcube_label('friday'), '5');
      $select->add(rcube_label('saturday'), '6');
      $p['blocks']['view']['options']['first_day'] = array(
        'title' => html::label($field_id, Q($this->gettext('first_day'))),
        'content' => $select->show($this->rc->config->get('calendar_first_day', 1)),
      );
      
      $field_id = 'rcmfd_alarm';
      $select_type = new html_select(array('name' => '_alarm_type', 'id' => $field_id));
      $select_type->add($this->gettext('none'), '');
      foreach ($this->driver->alarm_types as $type)
        $select_type->add($this->gettext(strtolower("alarm{$type}option")), $type);
      
      $input_value = new html_inputfield(array('name' => '_alarm_value', 'id' => $field_id . 'value', 'size' => 3));
      $select_offset = new html_select(array('name' => '_alarm_offset', 'id' => $field_id . 'offset'));
      foreach (array('-M','-H','-D','+M','+H','+D') as $trigger)
        $select_offset->add($this->gettext('trigger' . $trigger), $trigger);
      
      $p['blocks']['view']['options']['alarmtype'] = array(
        'title' => html::label($field_id, Q($this->gettext('defaultalarmtype'))),
        'content' => $select_type->show($this->rc->config->get('calendar_default_alarm_type', '')),
      );
      $preset = self::parse_alaram_value($this->rc->config->get('calendar_default_alarm_offset', '-15M'));
      $p['blocks']['view']['options']['alarmoffset'] = array(
        'title' => html::label($field_id . 'value', Q($this->gettext('defaultalarmoffset'))),
        'content' => $input_value->show($preset[0]) . ' ' . $select_offset->show($preset[1]),
      );
      
      // default calendar selection
      $field_id = 'rcmfd_default_calendar';
      $select_cal = new html_select(array('name' => '_default_calendar', 'id' => $field_id));
      foreach ((array)$this->driver->list_calendars() as $id => $prop) {
        if (!$prop['readononly'])
          $select_cal->add($prop['name'], strval($id));
      }
      $p['blocks']['view']['options']['defaultcalendar'] = array(
        'title' => html::label($field_id . 'value', Q($this->gettext('defaultcalendar'))),
        'content' => $select_cal->show($this->rc->config->get('calendar_default_calendar', '')),
      );
      
      // category definitions
      if (!$this->driver->categoriesimmutable) {
        $p['blocks']['categories']['name'] = $this->gettext('categories');

        $categories = (array) $this->rc->config->get('calendar_categories', $this->default_categories);
        $categories_list = '';
        foreach ($categories as $name => $color) {
          $key = md5($name);
          $field_class = 'rcmfd_category_' . str_replace(' ', '_', $name);
          $category_remove = new html_inputfield(array('type' => 'button', 'value' => 'X', 'class' => 'button', 'onclick' => '$(this).parent().remove()', 'title' => $this->gettext('remove_category')));
          $category_name  = new html_inputfield(array('name' => "_categories[$key]", 'class' => $field_class, 'size' => 30));
          $category_color = new html_inputfield(array('name' => "_colors[$key]", 'class' => "$field_class colors", 'size' => 6));
          $categories_list .= html::div(null, $category_name->show($name) . '&nbsp;' . $category_color->show($color) . '&nbsp;' . $category_remove->show());
        }

        $p['blocks']['categories']['options']['category_' . $name] = array(
          'content' => html::div(array('id' => 'calendarcategories'), $categories_list),
        );

        $field_id = 'rcmfd_new_category';
        $new_category = new html_inputfield(array('name' => '_new_category', 'id' => $field_id, 'size' => 30));
        $add_category = new html_inputfield(array('type' => 'button', 'class' => 'button', 'value' => $this->gettext('add_category'),  'onclick' => "rcube_calendar_add_category()"));
        $p['blocks']['categories']['options']['categories'] = array(
          'content' => $new_category->show('') . '&nbsp;' . $add_category->show(),
        );
        
        $this->rc->output->add_script('function rcube_calendar_add_category(){
          var name = $("#rcmfd_new_category").val();
          if (name.length) {
            var input = $("<input>").attr("type", "text").attr("name", "_categories[]").attr("size", 30).val(name);
            var color = $("<input>").attr("type", "text").attr("name", "_colors[]").attr("size", 6).addClass("colors").val("000000");
            var button = $("<input>").attr("type", "button").attr("value", "X").addClass("button").click(function(){ $(this).parent().remove() });
            $("<div>").append(input).append("&nbsp;").append(color).append("&nbsp;").append(button).appendTo("#calendarcategories");
            color.miniColors();
          }
        }');

        // include color picker
        $this->include_script('lib/js/jquery.miniColors.min.js');
        $this->include_stylesheet('skins/' .$this->rc->config->get('skin') . '/jquery.miniColors.css');
        $this->rc->output->add_script('$("input.colors").miniColors()', 'docready');
      }
    }

    return $p;
  }

  /**
   * Handler for preferences_save hook.
   * Executed on Calendar settings form submit.
   *
   * @param array Original parameters
   * @return array Modified parameters
   */
  function preferences_save($p)
  {
    if ($p['section'] == 'calendar') {
      $this->load_driver();

      // compose default alarm preset value
      $alarm_offset = get_input_value('_alarm_offset', RCUBE_INPUT_POST);
      $default_alam = $alarm_offset[0] . intval(get_input_value('_alarm_value', RCUBE_INPUT_POST)) . $alarm_offset[1];

      $p['prefs'] = array(
        'calendar_default_view' => get_input_value('_default_view', RCUBE_INPUT_POST),
        'calendar_time_format'  => get_input_value('_time_format', RCUBE_INPUT_POST),
        'calendar_timeslots'    => get_input_value('_timeslots', RCUBE_INPUT_POST),
        'calendar_first_day'    => get_input_value('_first_day', RCUBE_INPUT_POST),
        'calendar_default_alarm_type'   => get_input_value('_alarm_type', RCUBE_INPUT_POST),
        'calendar_default_alarm_offset' => $default_alam,
        'calendar_default_calendar'     => get_input_value('_default_calendar', RCUBE_INPUT_POST),
      );
      
      // categories
      if (!$this->driver->categoriesimmutable) {
        $old_categories = $new_categories = array();
        foreach ($this->driver->list_categories() as $name => $color) {
          $old_categories[md5($name)] = $name;
        }
        $categories = get_input_value('_categories', RCUBE_INPUT_POST);
        $colors = get_input_value('_colors', RCUBE_INPUT_POST);
        foreach ($categories as $key => $name) {
          $color = preg_replace('/^#/', '', strval($colors[$key]));
        
          // rename categories in existing events -> driver's job
          if ($oldname = $old_categories[$key]) {
            $this->driver->replace_category($oldname, $name, $color);
            unset($old_categories[$key]);
          }
          else
            $this->driver->add_category($name, $color);
        
          $new_categories[$name] = $color;
        }

        // these old categories have been removed, alter events accordingly -> driver's job
        foreach ((array)$old_categories[$key] as $key => $name) {
          $this->driver->remove_category($name);
        }
        
        $p['prefs']['calendar_categories'] = $new_categories;
      }
    }

    return $p;
  }

  /**
   * Dispatcher for calendar actions initiated by the client
   */
  function calendar_action()
  {
    $action = get_input_value('action', RCUBE_INPUT_GPC);
    $cal = get_input_value('c', RCUBE_INPUT_POST);
    $success = $reload = false;
    
    switch ($action) {
      case "form-new":
      case "form-edit":
        echo $this->ui->calendar_editform($action, $cal);
        exit;
      case "new":
        $success = $this->driver->create_calendar($cal);
        $reload = true;
        break;
      case "edit":
        $success = $this->driver->edit_calendar($cal);
        $reload = true;
        break;
      case "remove":
        if ($success = $this->driver->remove_calendar($cal))
          $this->rc->output->command('plugin.destroy_source', array('id' => $cal['id']));
        break;
    }
    
    if ($success)
      $this->rc->output->show_message('successfullysaved', 'confirmation');
    else
      $this->rc->output->show_message('calendar.errorsaving', 'error');

    // TODO: keep view and date selection
    if ($success && $reload)
      $this->rc->output->redirect('');
  }
  
  
  /**
   * Dispatcher for event actions initiated by the client
   */
  function event_action()
  {
    $action = get_input_value('action', RCUBE_INPUT_GPC);
    $event  = get_input_value('e', RCUBE_INPUT_POST);
    $success = $reload = $got_msg = false;

    switch ($action) {
      case "new":
        // create UID for new event
        $event['uid'] = $this->generate_uid();
        
        // set current user as organizer
        if (FALSE && !$event['attendees']) {
          $identity = $this->rc->user->get_identity();
          $event['attendees'][] = array('role' => 'ORGANIZER', 'name' => $identity['name'], 'email' => $identity['email']);
        }
        
        $this->prepare_event($event);
        if ($success = $this->driver->new_event($event))
            $this->cleanup_event($event);
        $reload = true;
        break;
      
      case "edit":
        $this->prepare_event($event);
        if ($success = $this->driver->edit_event($event))
            $this->cleanup_event($event);
        $reload = true;
        break;
      
      case "resize":
        $success = $this->driver->resize_event($event);
        $reload = true;
        break;
      
      case "move":
        $success = $this->driver->move_event($event);
        $reload = true;
        break;
      
      case "remove":
        // remove previous deletes
        $undo_time = $this->driver->undelete ? $this->rc->config->get('undo_timeout', 0) : 0;
        $this->rc->session->remove('calendar_event_undo');

        $success = $this->driver->remove_event($event, $undo_time < 1);
        $reload  = true;

        if ($undo_time > 0 && $success) {
          $_SESSION['calendar_event_undo'] = array('ts' => time(), 'data' => $event);
          // display message with Undo link.
          $msg = html::span(null, $this->gettext('successremoval'))
            . ' ' . html::a(array('onclick' => sprintf("%s.http_request('event', 'action=undo', %s.display_message('', 'loading'))",
              JS_OBJECT_NAME, JS_OBJECT_NAME)), rcube_label('undo'));
          $this->rc->output->show_message($msg, 'confirmation', null, true, $undo_time);
        }
        else if ($success) {
          $this->rc->output->show_message('calendar.successremoval', 'confirmation');
          $got_msg = true;
        }

        break;

      case "undo":
        // Restore deleted event
        $event  = $_SESSION['calendar_event_undo']['data'];
        $reload = true;

        if ($event)
          $success = $this->driver->restore_event($event);

        if ($success) {
          $this->rc->session->remove('calendar_event_undo');
          $this->rc->output->show_message('calendar.successrestore', 'confirmation');
          $got_msg = true;
        }

        break;

      case "dismiss":
        foreach (explode(',', $event['id']) as $id)
          $success |= $this->driver->dismiss_alarm($id, $event['snooze']);
        break;
    }

    // show confirmation/error message
    if (!$got_msg) {
      if ($success)
        $this->rc->output->show_message('successfullysaved', 'confirmation');
      else
        $this->rc->output->show_message('calendar.errorsaving', 'error');
    }

    // unlock client
    $this->rc->output->command('plugin.unlock_saving');

    // FIXME: update a single event object on the client instead of reloading the entire source
    if ($success && $reload)
      $this->rc->output->command('plugin.reload_calendar', array('source' => $event['calendar']));
  }

  /**
   * Handler for load-requests from fullcalendar
   * This will return pure JSON formatted output
   */
  function load_events()
  {
    $events = $this->driver->load_events(
      get_input_value('start', RCUBE_INPUT_GET),
      get_input_value('end', RCUBE_INPUT_GET),
      ($query = get_input_value('q', RCUBE_INPUT_GET)),
      get_input_value('source', RCUBE_INPUT_GET)
    );
    echo $this->encode($events, !empty($query));
    exit;
  }
  
  /**
   * Handler for keep-alive requests
   * This will check for pending notifications and pass them to the client
   */
  function keep_alive($attr)
  {
    $this->load_driver();
    $alarms = $this->driver->pending_alarms(time());
    if ($alarms) {
      // make sure texts and env vars are available on client
      if ($this->rc->task != 'calendar') {
        $this->add_texts('localization/', true);
        $this->rc->output->set_env('snooze_select', $this->ui->snooze_select());
      }
      $this->rc->output->command('plugin.display_alarms', $this->_alarms_output($alarms));
    }
  }
  
  /**
   * Construct the ics file for exporting events to iCalendar format;
   */
  function export_events()
  {
    $start = get_input_value('start', RCUBE_INPUT_GET);
    $end = get_input_value('end', RCUBE_INPUT_GET);
    if (!$start) $start = mktime(0, 0, 0, 1, date('n'), date('Y')-1);
    if (!$end) $end = mktime(0, 0, 0, 31, 12, date('Y')+10);
    $events = $this->driver->load_events($start, $end, null, get_input_value('source', RCUBE_INPUT_GET), 0);
   
    header("Content-Type: text/calendar");
    header("Content-Disposition: inline; filename=calendar.ics");
    
    echo $this->ical->export($events);
    exit;
  }

  /**
   *
   */
  function load_settings()
  {
    $settings = array();
    
    // configuration
    $settings['default_calendar'] = $this->rc->config->get('calendar_default_calendar');
    $settings['default_view'] = (string)$this->rc->config->get('calendar_default_view', $this->defaults['calendar_default_view']);
    $settings['date_format'] = (string)$this->rc->config->get('calendar_date_format', $this->defaults['calendar_date_format']);
    $settings['date_short'] = (string)$this->rc->config->get('calendar_date_short', $this->defaults['calendar_date_short']);
    $settings['date_long'] = (string)$this->rc->config->get('calendar_date_long', $this->defaults['calendar_date_long']);
    $settings['date_agenda'] = (string)$this->rc->config->get('calendar_date_agenda', $this->defaults['calendar_date_agenda']);
    $settings['time_format'] = (string)$this->rc->config->get('calendar_time_format', $this->defaults['calendar_time_format']);
    $settings['timeslots'] = (int)$this->rc->config->get('calendar_timeslots', $this->defaults['calendar_timeslots']);
    $settings['first_day'] = (int)$this->rc->config->get('calendar_first_day', $this->defaults['calendar_first_day']);
    $settings['first_hour'] = (int)$this->rc->config->get('calendar_first_hour', $this->defaults['calendar_first_hour']);
    $settings['timezone'] = $this->timezone;

    // localization
    $settings['days'] = array(
      rcube_label('sunday'),   rcube_label('monday'),
      rcube_label('tuesday'),  rcube_label('wednesday'),
      rcube_label('thursday'), rcube_label('friday'),
      rcube_label('saturday')
    );
    $settings['days_short'] = array(
      rcube_label('sun'), rcube_label('mon'),
      rcube_label('tue'), rcube_label('wed'),
      rcube_label('thu'), rcube_label('fri'),
      rcube_label('sat')
    );
    $settings['months'] = array(
      $this->rc->gettext('longjan'), $this->rc->gettext('longfeb'),
      $this->rc->gettext('longmar'), $this->rc->gettext('longapr'),
      $this->rc->gettext('longmay'), $this->rc->gettext('longjun'),
      $this->rc->gettext('longjul'), $this->rc->gettext('longaug'),
      $this->rc->gettext('longsep'), $this->rc->gettext('longoct'),
      $this->rc->gettext('longnov'), $this->rc->gettext('longdec')
    );
    $settings['months_short'] = array(
      $this->rc->gettext('jan'), $this->rc->gettext('feb'),
      $this->rc->gettext('mar'), $this->rc->gettext('apr'),
      $this->rc->gettext('may'), $this->rc->gettext('jun'),
      $this->rc->gettext('jul'), $this->rc->gettext('aug'),
      $this->rc->gettext('sep'), $this->rc->gettext('oct'),
      $this->rc->gettext('nov'), $this->rc->gettext('dec')
    );
    $settings['today'] = rcube_label('today');

    // user prefs
    $settings['hidden_calendars'] = array_filter(explode(',', $this->rc->config->get('hidden_calendars', '')));
    
    // get user identity to create default attendee
    $identity = $this->rc->user->get_identity();
    $settings['event_owner'] = array('name' => $identity['name'], 'email' => $identity['email']);

    return $settings;
  }

  /**
   * Convert the given date string into a GMT-based time stamp
   */
  function fromGMT($datetime)
  {
    $ts = is_numeric($datetime) ? $datetime : strtotime($datetime);
    return $ts + $this->gmt_offset;
  }

  /**
   * Encode events as JSON
   *
   * @param  array  Events as array
   * @param  boolean Add CSS class names according to calendar and categories
   * @return string JSON encoded events
   */
  function encode($events, $addcss = false)
  {
    $json = array();
    foreach ($events as $event) {
      // compose a human readable strings for alarms_text and recurrence_text
      if ($event['alarms'])
        $event['alarms_text'] = $this->_alarms_text($event['alarms']);
      if ($event['recurrence'])
        $event['recurrence_text'] = $this->_recurrence_text($event['recurrence']);

      $json[] = array(
        'start' => gmdate('c', $this->fromGMT($event['start'])), // client treats date strings as they were in users's timezone
        'end'   => gmdate('c', $this->fromGMT($event['end'])),   // so shift timestamps to users's timezone and render a date string
        'description' => strval($event['description']),
        'location'    => strval($event['location']),
        'className'   => ($addcss ? 'fc-event-cal-'.asciiwords($event['calendar'], true).' ' : '') . 'cat-' . asciiwords($event['categories'], true),
        'allDay'      => ($event['allday'] == 1),
      ) + $event;
    }
    return json_encode($json);
  }


  /**
   * Generate reduced and streamlined output for pending alarms
   */
  private function _alarms_output($alarms)
  {
    $out = array();
    foreach ($alarms as $alarm) {
      $out[] = array(
        'id'       => $alarm['id'],
        'start'    => gmdate('c', $this->fromGMT($alarm['start'])),
        'end'      => gmdate('c', $this->fromGMT($alarm['end'])),
        'allDay'   => ($event['allday'] == 1)?true:false,
        'title'    => $alarm['title'],
        'location' => $alarm['location'],
        'calendar' => $alarm['calendar'],
      );
    }
    
    return $out;
  }

  /**
   * Render localized text for alarm settings
   */
  private function _alarms_text($alarm)
  {
    list($trigger, $action) = explode(':', $alarm);
    
    $text = '';
    switch ($action) {
      case 'EMAIL':
        $text = $this->gettext('alarmemail');
        break;
      case 'DISPLAY':
        $text = $this->gettext('alarmdisplay');
        break;
    }
    
    if (preg_match('/@(\d+)/', $trigger, $m)) {
      $text .= ' ' . $this->gettext(array('name' => 'alarmat', 'vars' => array('datetime' => format_date($m[1]))));
    }
    else if ($val = self::parse_alaram_value($trigger)) {
      $text .= ' ' . intval($val[0]) . ' ' . $this->gettext('trigger' . $val[1]);
    }
    else
      return false;
    
    return $text;
  }

  /**
   * Render localized text describing the recurrence rule of an event
   */
  private function _recurrence_text($rrule)
  {
    // TODO: finish this
    $freq = sprintf('%s %d ', $this->gettext('every'), $rrule['INTERVAL']);
    $details = '';
    switch ($rrule['FREQ']) {
      case 'DAILY':
        $freq .= $this->gettext('days');
        break;
      case 'WEEKLY':
        $freq .= $this->gettext('weeks');
        break;
      case 'MONTHLY':
        $freq .= $this->gettext('months');
        break;
      case 'YEARY':
        $freq .= $this->gettext('years');
        break;
    }
    
    if ($rrule['INTERVAL'] == 1)
      $freq = $this->gettext(strtolower($rrule['FREQ']));
      
    if ($rrule['COUNT'])
      $until =  $this->gettext(array('name' => 'forntimes', 'vars' => array('nr' => $rrule['COUNT'])));
    else if ($rrule['UNTIL'])
      $until = $this->gettext('recurrencend') . ' ' . format_date($rrule['UNTIL'], self::to_php_date_format($this->rc->config->get('calendar_date_format', $this->defaults['calendar_date_format'])));
    else
      $until = $this->gettext('forever');
    
    return rtrim($freq . $details . ', ' . $until);
  }

  /**
   * Generate a unique identifier for an event
   */
  public function generate_uid()
  {
    return strtoupper(md5(time() . uniqid(rand())) . '-' . substr(md5($this->rc->user->get_username()), 0, 16));
  }

  /**
   * Helper function to convert alarm trigger strings
   * into two-field values (e.g. "-45M" => 45, "-M")
   */
  public static function parse_alaram_value($val)
  {
    if ($val[0] == '@')
      return array(substr($val, 1));
    else if (preg_match('/([+-])(\d+)([HMD])/', $val, $m))
      return array($m[2], $m[1].$m[3]);
    
    return false;
  }
  
  /**
   * Convert the internal structured data into a vcalendar rrule 2.0 string
   */
  public static function to_rrule($recurrence)
  {
    if (is_string($recurrence))
      return $recurrence;
    
    $rrule = '';
    foreach ((array)$recurrence as $k => $val) {
      $k = strtoupper($k);
      switch ($k) {
        case 'UNTIL':
          $val = gmdate('Ymd\THis', $val);
          break;
        case 'EXDATE':
          foreach ((array)$val as $i => $ex)
            $val[$i] = gmdate('Ymd\THis', $ex);
          $val = join(',', $val);
          break;
      }
      $rrule .= $k . '=' . $val . ';';
    }
    
    return $rrule;
  }
  
  /**
   * Convert from fullcalendar date format to PHP date() format string
   */
  private static function to_php_date_format($from)
  {
    // "dd.MM.yyyy HH:mm:ss" => "d.m.Y H:i:s"
    return strtr($from, array(
      'yyyy' => 'Y',
      'yy'   => 'y',
      'MMMM' => 'F',
      'MMM'  => 'M',
      'MM'   => 'm',
      'M'    => 'n',
      'dddd' => 'l',
      'ddd'  => 'D',
      'dd'   => 'd',
      'HH'   => 'H',
      'hh'   => 'h',
      'mm'   => 'i',
      'ss'   => 's',
      'TT'   => 'A',
      'tt'   => 'a',
      'T'    => 'A',
      't'    => 'a',
      'u'    => 'c',
    ));
  }
  
  /**
   * TEMPORARY: generate random event data for testing
   * Create events by opening http://<roundcubeurl>/?_task=calendar&_action=randomdata&_num=500
   */
  public function generate_randomdata()
  {
    $cats = array_keys($this->driver->list_categories());
    $cals = $this->driver->list_calendars();
    $num = $_REQUEST['_num'] ? intval($_REQUEST['_num']) : 100;
    
    while ($count++ < $num) {
      $start = round((time() + rand(-2600, 2600) * 1000) / 300) * 300;
      $duration = round(rand(30, 360) / 30) * 30 * 60;
      $allday = rand(0,20) > 18;
      $alarm = rand(-30,12) * 5;
      $fb = rand(0,2);
      
      if (date('G', $start) > 23)
        $start -= 3600;
      
      if ($allday) {
        $start = strtotime(date('Y-m-d 00:00:00', $start));
        $duration = 86399;
      }
      
      $title = '';
      $len = rand(2, 12);
      $words = explode(" ", "The Hough transform is named after Paul Hough who patented the method in 1962. It is a technique which can be used to isolate features of a particular shape within an image. Because it requires that the desired features be specified in some parametric form, the classical Hough transform is most commonly used for the de- tection of regular curves such as lines, circles, ellipses, etc. A generalized Hough transform can be employed in applications where a simple analytic description of a feature(s) is not possible. Due to the computational complexity of the generalized Hough algorithm, we restrict the main focus of this discussion to the classical Hough transform. Despite its domain restrictions, the classical Hough transform (hereafter referred to without the classical prefix ) retains many applications, as most manufac- tured parts (and many anatomical parts investigated in medical imagery) contain feature boundaries which can be described by regular curves. The main advantage of the Hough transform technique is that it is tolerant of gaps in feature boundary descriptions and is relatively unaffected by image noise.");
      $chars = "!# abcdefghijklmnopqrstuvwxyz ABCDEFGHIJKLMNOPQRSTUVWXYZ 1234567890";
      for ($i = 0; $i < $len; $i++)
        $title .= $words[rand(0,count($words)-1)] . " ";
      
      $this->driver->new_event(array(
        'uid' => $this->generate_uid(),
        'start' => $start,
        'end' => $start + $duration,
        'allday' => $allday,
        'title' => rtrim($title),
        'free_busy' => $fb == 2 ? 'outofoffice' : ($fb ? 'busy' : 'free'),
        'categories' => $cats[array_rand($cats)],
        'calendar' => array_rand($cals),
        'alarms' => $alarm > 0 ? "-{$alarm}M:DISPLAY" : '',
        'priority' => 1,
      ));
    }
    
    $this->rc->output->redirect('');
  }

  /**
   * Handler for attachments upload
   */
  public function attachment_upload()
  {
    // Upload progress update
    if (!empty($_GET['_progress'])) {
      rcube_upload_progress();
    }

    $event    = get_input_value('_id', RCUBE_INPUT_GPC);
    $calendar = get_input_value('calendar', RCUBE_INPUT_GPC);
    $uploadid = get_input_value('_uploadid', RCUBE_INPUT_GPC);

    $eventid = $calendar.':'.$event;

    if (!is_array($_SESSION['event_session']) || $_SESSION['event_session']['id'] != $eventid) {
      $_SESSION['event_session'] = array();
      $_SESSION['event_session']['id'] = $eventid;
      $_SESSION['event_session']['attachments'] = array();
    }

    // clear all stored output properties (like scripts and env vars)
    $this->rc->output->reset();

    if (is_array($_FILES['_attachments']['tmp_name'])) {
      foreach ($_FILES['_attachments']['tmp_name'] as $i => $filepath) {
        // Process uploaded attachment if there is no error
        $err = $_FILES['_attachments']['error'][$i];

        if (!$err) {
          $attachment = array(
            'path' => $filepath,
            'size' => $_FILES['_attachments']['size'][$i],
            'name' => $_FILES['_attachments']['name'][$i],
            'mimetype' => rc_mime_content_type($filepath, $_FILES['_attachments']['name'][$i], $_FILES['_attachments']['type'][$i]),
            'group' => $eventid,
          );

          $attachment = $this->rc->plugins->exec_hook('attachment_upload', $attachment);
        }

        if (!$err && $attachment['status'] && !$attachment['abort']) {
          $id = $attachment['id'];

          // store new attachment in session
          unset($attachment['status'], $attachment['abort']);
          $_SESSION['event_session']['attachments'][$id] = $attachment;

          if (($icon = $_SESSION['calendar_deleteicon']) && is_file($icon)) {
            $button = html::img(array(
              'src' => $icon,
              'alt' => rcube_label('delete')
            ));
          }
          else {
            $button = Q(rcube_label('delete'));
          }

          $content = html::a(array(
            'href' => "#delete",
            'onclick' => sprintf("return %s.remove_from_attachment_list('rcmfile%s')", JS_OBJECT_NAME, $id),
            'title' => rcube_label('delete'),
          ), $button);

          $content .= Q($attachment['name']);

          $this->rc->output->command('add2attachment_list', "rcmfile$id", array(
            'html' => $content,
            'name' => $attachment['name'],
            'mimetype' => $attachment['mimetype'],
            'complete' => true), $uploadid);
        }
        else {  // upload failed
          if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) {
            $msg = rcube_label(array('name' => 'filesizeerror', 'vars' => array(
                'size' => show_bytes(parse_bytes(ini_get('upload_max_filesize'))))));
          }
          else if ($attachment['error']) {
            $msg = $attachment['error'];
          }
          else {
            $msg = rcube_label('fileuploaderror');
          }

          $this->rc->output->command('display_message', $msg, 'error');
          $this->rc->output->command('remove_from_attachment_list', $uploadid);
        }
      }
    }
    else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
      // if filesize exceeds post_max_size then $_FILES array is empty,
      // show filesizeerror instead of fileuploaderror
      if ($maxsize = ini_get('post_max_size'))
        $msg = rcube_label(array('name' => 'filesizeerror', 'vars' => array(
            'size' => show_bytes(parse_bytes($maxsize)))));
      else
        $msg = rcube_label('fileuploaderror');

      $this->rc->output->command('display_message', $msg, 'error');
      $this->rc->output->command('remove_from_attachment_list', $uploadid);
    }

    $this->rc->output->send('iframe');
  }

  /**
   * Handler for attachments download/displaying
   */
  public function attachment_get()
  {
    $event    = get_input_value('_event', RCUBE_INPUT_GPC);
    $calendar = get_input_value('_cal', RCUBE_INPUT_GPC);
    $id       = get_input_value('_id', RCUBE_INPUT_GPC);

    $event = array('id' => $event, 'calendar' => $calendar);

    // show loading page
    if (!empty($_GET['_preload'])) {
      $url = str_replace('&_preload=1', '', $_SERVER['REQUEST_URI']);
      $message = rcube_label('loadingdata');

      header('Content-Type: text/html; charset=' . RCMAIL_CHARSET);
      print "<html>\n<head>\n"
        . '<meta http-equiv="refresh" content="0; url='.Q($url).'">' . "\n"
        . '<meta http-equiv="content-type" content="text/html; charset='.RCMAIL_CHARSET.'">' . "\n"
        . "</head>\n<body>\n$message\n</body>\n</html>";
      exit;
    }

    ob_end_clean();
    send_nocacheing_headers();

    if (isset($_SESSION['calendar_attachment']))
      $attachment = $_SESSION['calendar_attachment'];
    else
      $attachment = $_SESSION['calendar_attachment'] = $this->driver->get_attachment($id, $event);

    // show part page
    if (!empty($_GET['_frame'])) {
      $this->attachment = $attachment;
      $this->register_handler('plugin.attachmentframe', array($this, 'attachment_frame'));
      $this->register_handler('plugin.attachmentcontrols', array($this->ui, 'attachment_controls'));
      $this->rc->output->send('calendar.attachment');
      exit;
    }

    $this->rc->session->remove('calendar_attachment');

    if ($attachment) {
      $mimetype = strtolower($attachment['mimetype']);
      list($ctype_primary, $ctype_secondary) = explode('/', $mimetype);

      $browser = $this->rc->output->browser;

      // send download headers
      if ($_GET['_download']) {
        header("Content-Type: application/octet-stream");
        if ($browser->ie)
          header("Content-Type: application/force-download");
      }
      else if ($ctype_primary == 'text') {
        header("Content-Type: text/$ctype_secondary");
      }
      else {
//        $mimetype = rcmail_fix_mimetype($mimetype);
        header("Content-Type: $mimetype");
        header("Content-Transfer-Encoding: binary");
      }

      $body = $this->driver->get_attachment_body($id, $event);

      // display page, @TODO: support text/plain (and maybe some other text formats)
      if ($mimetype == 'text/html' && empty($_GET['_download'])) {
        $OUTPUT = new rcube_html_page();
        // @TODO: use washtml on $body
        $OUTPUT->write($body);
      }
      else {
        // don't kill the connection if download takes more than 30 sec.
        @set_time_limit(0);

        $filename = $attachment['name'];
        $filename = preg_replace('[\r\n]', '', $filename);

        if ($browser->ie && $browser->ver < 7)
          $filename = rawurlencode(abbreviate_string($filename, 55));
        else if ($browser->ie)
          $filename = rawurlencode($filename);
        else
          $filename = addcslashes($filename, '"');

        $disposition = !empty($_GET['_download']) ? 'attachment' : 'inline';
        header("Content-Disposition: $disposition; filename=\"$filename\"");

        echo $body;
      }

      exit;
    }

    // if we arrive here, the requested part was not found
    header('HTTP/1.1 404 Not Found');
    exit;
  }

  /**
   * Template object for attachment display frame
   */
  public function attachment_frame($attrib)
  {
    $attachment = $_SESSION['calendar_attachment'];

    $mimetype = strtolower($attachment['mimetype']);
    list($ctype_primary, $ctype_secondary) = explode('/', $mimetype);

    $attrib['src'] = './?' . str_replace('_frame=', ($ctype_primary == 'text' ? '_show=' : '_preload='), $_SERVER['QUERY_STRING']);

    return html::iframe($attrib);
  }

  /**
   * Prepares new/edited event properties before save
   */
  private function prepare_event(&$event)
  {
    $eventid = $event['calendar'].':'.$event['id'];

    $attachments = array();
    if (is_array($_SESSION['event_session']) && $_SESSION['event_session']['id'] == $eventid) {
      if (!empty($_SESSION['event_session']['attachments'])) {
        foreach ($_SESSION['event_session']['attachments'] as $id => $attachment) {
          if (is_array($event['attachments']) && in_array($id, $event['attachments'])) {
            $attachments[$id] = $this->rc->plugins->exec_hook('attachment_get', $attachment);
          }
        }
      }
    }

    $event['attachments'] = $attachments;
    
    // check for organizer in attendees
    if ($event['attendees']) {
      $identity = $this->rc->user->get_identity();
      $organizer = $owner = false;
      foreach ($event['attendees'] as $i => $attendee) {
        if ($attendee['role'] == 'ORGANIZER')
          $organizer = true;
        if ($attendee['email'] == $identity['email'])
          $owner = $i;
      }
      
      // set owner as organizer if yet missing
      if (!$organizer && $owner !== false) {
        $event['attendees'][$i]['role'] = 'ORGANIZER';
      }
      else if (!$organizer && $identity['email']) {
        $event['attendees'][] = array('role' => 'ORGANIZER', 'name' => $identity['name'], 'email' => $identity['email'], 'status' => 'ACCEPTED');
      }
    }
  }

  /**
   * Releases some resources after successful event save
   */
  private function cleanup_event(&$event)
  {
    // remove temp. attachment files
    if (!empty($_SESSION['event_session']) && ($eventid = $_SESSION['event_session']['id'])) {
      $this->rc->plugins->exec_hook('attachments_cleanup', array('group' => $eventid));
      unset($_SESSION['event_session']);
    }
  }
  
  /**
   * Echo simple free/busy status text for the given user and time range
   */
  public function freebusy_status()
  {
    $email = get_input_value('email', RCUBE_INPUT_GPC);
    $start = get_input_value('start', RCUBE_INPUT_GET);
    $end = get_input_value('end', RCUBE_INPUT_GET);
    
    if (!$start) $start = time();
    if (!$end) $end = $start + 3600;
    
    $status = 'UNKNOWN';
    
    // if the backend has free-busy information
    $fblist = $this->driver->get_freebusy_list($email, $start, $end);
    if (is_array($fblist)) {
      $status = 'FREE';
      
      foreach ($fblist as $slot) {
        list($from, $to) = $slot;
        if ($from <= $end && $to > $start) {
          $status = 'BUSY';
          break;
        }
      }
    }
    
    // let this information be cached for 15min
    send_future_expire_header(900);
    
    echo $status;
    exit;
  }
  
  /**
   * Return a list of free/busy time slots within the given period
   * Echo data in JSON encoding
   */
  public function freebusy_times()
  {
    $email = get_input_value('email', RCUBE_INPUT_GPC);
    $start = get_input_value('start', RCUBE_INPUT_GET);
    $end = get_input_value('end', RCUBE_INPUT_GET);
    
    if (!$start) $start = time();
    if (!$end)   $end = $start + 86400 * 30;
    
    $fblist = $this->driver->get_freebusy_list($email, $start, $end);
    $result = array();
    
    // TODO: build a list from $start till $end with blocks representing the fb-status
    
    echo json_encode($result);
    exit;
  }
  
  /**
   * Handler for printing calendars
   */
  public function print_view()
  {
    $title = $this->gettext('print');
    
    $view = get_input_value('view', RCUBE_INPUT_GPC);
    if (!in_array($view, array('agendaWeek', 'agendaDay', 'month', 'table')))
      $view = 'agendaDay';
    
    $this->rc->output->set_env('view',$view);
    
    if ($date = get_input_value('date', RCUBE_INPUT_GPC))
      $this->rc->output->set_env('date', $date);
    
    if ($search = get_input_value('search', RCUBE_INPUT_GPC)) {
      $this->rc->output->set_env('search', $search);
      $title .= ' "' . $search . '"';
    }
    
    // Add CSS stylesheets to the page header
    $skin = $this->rc->config->get('skin');
    $this->include_stylesheet('skins/' . $skin . '/fullcalendar.css');
    $this->include_stylesheet('skins/' . $skin . '/print.css');
    
    // Add JS files to the page header
    $this->include_script('print.js');
    
    $this->register_handler('plugin.calendar_css', array($this->ui, 'calendar_css'));
    $this->register_handler('plugin.calendar_list', array($this->ui, 'calendar_list'));
    
    $this->rc->output->set_pagetitle($title);
    $this->rc->output->send("calendar.print");
  }

}
