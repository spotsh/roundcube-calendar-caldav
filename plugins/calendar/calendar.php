<?php

/**
 * Calendar plugin for Roundcube webmail
 *
 * @version 0.7-beta
 * @author Lazlo Westerhof <hello@lazlo.me>
 * @author Thomas Bruederli <roundcube@gmail.com>
 *
 * Copyright (C) 2010, Lazlo Westerhof - Netherlands
 * Copyright (C) 2011, Kolab Systems AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */


class calendar extends rcube_plugin
{
  const FREEBUSY_UNKNOWN = 0;
  const FREEBUSY_FREE = 1;
  const FREEBUSY_BUSY = 2;
  const FREEBUSY_TENTATIVE = 3;
  const FREEBUSY_OOF = 4;
  
  public $task = '?(?!logout).*';
  public $rc;
  public $driver;
  public $home;  // declare public to be used in other classes
  public $urlbase;
  public $timezone;
  public $gmt_offset;

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
    'calendar_work_start'   => 6,
    'calendar_work_end'     => 18,
    'calendar_agenda_range' => 60,
    'calendar_agenda_sections' => 'smart',
    'calendar_event_coloring'  => 0,
    'calendar_date_format_sets' => array(
      'yyyy-MM-dd' => array('MMM d yyyy',   'M-d',  'ddd MM-dd'),
      'dd-MM-yyyy' => array('d MMM yyyy',   'd-M',  'ddd dd-MM'),
      'yyyy/MM/dd' => array('MMM d yyyy',   'M/d',  'ddd MM/dd'),
      'MM/dd/yyyy' => array('MMM d yyyy',   'M/d',  'ddd MM/dd'),
      'dd/MM/yyyy' => array('d MMM yyyy',   'd/M',  'ddd dd/MM'),
      'dd.MM.yyyy' => array('dd. MMM yyyy', 'd.M',  'ddd dd.MM.'),
      'd.M.yyyy'   => array('d. MMM yyyy',  'd.M',  'ddd d.MM.'),
    ),
  );

  private $default_categories = array(
    'Personal' => 'c0c0c0',
    'Work'     => 'ff0000',
    'Family'   => '00ff00',
    'Holiday'  => 'ff6600',
  );
  
  private $ics_parts = array();


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
    $this->timezone = $this->rc->config->get('timezone');
    $this->dst_active = $this->rc->config->get('dst_active');
    $this->gmt_offset = ($this->timezone + $this->dst_active) * 3600;
    $this->user_timezone = new DateTimeZone($this->timezone ? timezone_name_from_abbr("", $this->gmt_offset, $this->dst_active) : 'GMT');

    require($this->home . '/lib/calendar_ui.php');
    $this->ui = new calendar_ui($this);

    // load Calendar user interface which includes jquery-ui
    if (!$this->rc->output->ajax_call && !$this->rc->output->env['framed']) {
      $this->require_plugin('jqueryui');

      $this->ui->init();

      // settings are required in (almost) every GUI step
      if ($this->rc->action != 'attend')
        $this->rc->output->set_env('calendar_settings', $this->load_settings());
    }

    // catch iTIP confirmation requests that don're require a valid session
    if ($this->rc->action == 'attend' && !empty($_REQUEST['_t'])) {
      $this->add_hook('startup', array($this, 'itip_attend_response'));
    }
    else if ($this->rc->task == 'calendar' && $this->rc->action != 'save-pref') {
      if ($this->rc->action != 'upload') {
        $this->load_driver();
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
      $this->register_action('print', array($this,'print_view'));
      $this->register_action('mailimportevent', array($this, 'mail_import_event'));
      $this->register_action('mailtoevent', array($this, 'mail_message2event'));
      $this->register_action('inlineui', array($this, 'get_inline_ui'));
      $this->register_action('check-recent', array($this, 'check_recent'));

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
    else if ($this->rc->task == 'mail') {
      // hooks to catch event invitations on incoming mails
      if ($this->rc->action == 'show' || $this->rc->action == 'preview') {
        $this->add_hook('message_load', array($this, 'mail_message_load'));
        $this->add_hook('template_object_messagebody', array($this, 'mail_messagebody_html'));
      }
      
      // add 'Create event' item to message menu
      if ($this->api->output->type == 'html') {
        $this->api->add_content(html::tag('li', null, 
          $this->api->output->button(array(
            'command'  => 'calendar-create-from-mail',
            'label'    => 'calendar.createfrommail',
            'type'     => 'link',
            'classact' => 'calendarlink active',
            'class'    => 'calendarlink',
          ))),
          'messagemenu');
      }
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
   * Load iTIP functions
   */
  private function load_itip()
  {
    if (!$this->itip) {
      require_once($this->home . '/lib/calendar_itip.php');
      $this->itip = new calendar_itip($this);
    }
    
    return $this->itip;
  }

  /**
   * Load iCalendar functions
   */
  public function get_ical()
  {
    if (!$this->ical) {
      require_once($this->home . '/lib/calendar_ical.php');
      $this->ical = new calendar_ical($this);
    }
    
    return $this->ical;
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

    $this->ui->init_templates();
    $this->rc->output->add_label('lowest','low','normal','high','highest','delete','cancel','uploading','noemailwarning');

    // initialize attendees autocompletion
    rcube_autocomplete_init();

    $this->rc->output->set_env('calendar_driver', $this->rc->config->get('calendar_driver'), false);

    $view = get_input_value('view', RCUBE_INPUT_GPC);
    if (in_array($view, array('agendaWeek', 'agendaDay', 'month', 'table')))
      $this->rc->output->set_env('view', $view);
    
    if ($date = get_input_value('date', RCUBE_INPUT_GPC))
      $this->rc->output->set_env('date', $date);

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
        'content' => $select->show($this->rc->config->get('calendar_default_view', $this->defaults['calendar_default_view'])),
      );

      $field_id = 'rcmfd_timeslot';
      $choices = array('1', '2', '3', '4', '6');
      $select = new html_select(array('name' => '_timeslots', 'id' => $field_id));
      $select->add($choices);
      $p['blocks']['view']['options']['timeslots'] = array(
        'title' => html::label($field_id, Q($this->gettext('timeslots'))),
        'content' => $select->show($this->rc->config->get('calendar_timeslots', $this->defaults['calendar_timeslots'])),
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
        'content' => $select->show($this->rc->config->get('calendar_first_day', $this->defaults['calendar_first_day'])),
      );
      
      $time_format = $this->rc->config->get('time_format', self::to_php_date_format($this->rc->config->get('calendar_time_format', $this->defaults['calendar_time_format'])));
      $select_hours = new html_select();
      for ($h = 0; $h < 24; $h++)
        $select_hours->add(date($time_format, mktime($h, 0, 0)), $h);

      $field_id = 'rcmfd_firsthour';
      $p['blocks']['view']['options']['first_hour'] = array(
        'title' => html::label($field_id, Q($this->gettext('first_hour'))),
        'content' => $select_hours->show($this->rc->config->get('calendar_first_hour', $this->defaults['calendar_first_hour']), array('name' => '_first_hour', 'id' => $field_id)),
      );
      
      $field_id = 'rcmfd_workstart';
      $p['blocks']['view']['options']['workinghours'] = array(
        'title' => html::label($field_id, Q($this->gettext('workinghours'))),
        'content' => $select_hours->show($this->rc->config->get('calendar_work_start', $this->defaults['calendar_work_start']), array('name' => '_work_start', 'id' => $field_id)) .
        ' &mdash; ' . $select_hours->show($this->rc->config->get('calendar_work_end', $this->defaults['calendar_work_end']), array('name' => '_work_end', 'id' => $field_id)),
      );

      $field_id = 'rcmfd_coloing';
      $select_colors = new html_select(array('name' => '_event_coloring', 'id' => $field_id));
      $select_colors->add($this->gettext('coloringmode0'), 0);
      $select_colors->add($this->gettext('coloringmode1'), 1);
      $select_colors->add($this->gettext('coloringmode2'), 2);
      $select_colors->add($this->gettext('coloringmode3'), 3);

      $p['blocks']['view']['options']['eventcolors'] = array(
        'title' => html::label($field_id . 'value', Q($this->gettext('eventcoloring'))),
        'content' => $select_colors->show($this->rc->config->get('calendar_event_coloring', $this->defaults['calendar_event_coloring'])),
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
        if (!$prop['readonly'])
          $select_cal->add($prop['name'], strval($id));
      }
      $p['blocks']['view']['options']['defaultcalendar'] = array(
        'title' => html::label($field_id . 'value', Q($this->gettext('defaultcalendar'))),
        'content' => $select_cal->show($this->rc->config->get('calendar_default_calendar', '')),
      );
      
      
      // category definitions
      if (!$this->driver->nocategories) {
        $p['blocks']['categories']['name'] = $this->gettext('categories');

        $categories = (array) $this->driver->list_categories();
        $categories_list = '';
        foreach ($categories as $name => $color) {
          $key = md5($name);
          $field_class = 'rcmfd_category_' . str_replace(' ', '_', $name);
          $category_remove = new html_inputfield(array('type' => 'button', 'value' => 'X', 'class' => 'button', 'onclick' => '$(this).parent().remove()', 'title' => $this->gettext('remove_category')));
          $category_name  = new html_inputfield(array('name' => "_categories[$key]", 'class' => $field_class, 'size' => 30, 'disabled' => $this->driver->categoriesimmutable));
          $category_color = new html_inputfield(array('name' => "_colors[$key]", 'class' => "$field_class colors", 'size' => 6));
          $hidden = $this->driver->categoriesimmutable ? html::tag('input', array('type' => 'hidden', 'name' => "_categories[$key]", 'value' => $name)) : '';
          $categories_list .= html::div(null, $hidden . $category_name->show($name) . '&nbsp;' . $category_color->show($color) . '&nbsp;' . $category_remove->show());
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
        'calendar_timeslots'    => get_input_value('_timeslots', RCUBE_INPUT_POST),
        'calendar_first_day'    => get_input_value('_first_day', RCUBE_INPUT_POST),
        'calendar_first_hour'   => intval(get_input_value('_first_hour', RCUBE_INPUT_POST)),
        'calendar_work_start'   => intval(get_input_value('_work_start', RCUBE_INPUT_POST)),
        'calendar_work_end'     => intval(get_input_value('_work_end', RCUBE_INPUT_POST)),
        'calendar_event_coloring'       => intval(get_input_value('_event_coloring', RCUBE_INPUT_POST)),
        'calendar_default_alarm_type'   => get_input_value('_alarm_type', RCUBE_INPUT_POST),
        'calendar_default_alarm_offset' => $default_alam,
        'calendar_default_calendar'     => get_input_value('_default_calendar', RCUBE_INPUT_POST),
        'calendar_date_format' => null,  // clear previously saved values
        'calendar_time_format' => null,
      );

      // categories
      if (!$this->driver->nocategories) {
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
    $cal = get_input_value('c', RCUBE_INPUT_GPC);
    $success = $reload = false;

    if (isset($cal['showalarms']))
      $cal['showalarms'] = intval($cal['showalarms']);

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
      case "subscribe":
        if (!$this->driver->subscribe_calendar($cal))
          $this->rc->output->show_message($this->gettext('errorsaving'), 'error');
        return;
    }
    
    if ($success)
      $this->rc->output->show_message('successfullysaved', 'confirmation');
    else {
      $error_msg = $this->gettext('errorsaving') . ($this->driver->last_error ? ': ' . $this->driver->last_error :'');
      $this->rc->output->show_message($error_msg, 'error');
    }

    $this->rc->output->command('plugin.unlock_saving');

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
    $event  = get_input_value('e', RCUBE_INPUT_POST, true);
    $success = $reload = $got_msg = false;
    
    // don't notify if modifying a recurring instance (really?)
    if ($event['_savemode'] && $event['_savemode'] != 'all' && $event['_notify'])
      unset($event['_notify']);
    
    // read old event data in order to find changes
    if (($event['_notify'] || $event['decline']) && $action != 'new')
      $old = $this->driver->get_event($event);

    switch ($action) {
      case "new":
        // create UID for new event
        $event['uid'] = $this->generate_uid();
        $this->prepare_event($event, $action);
        if ($success = $this->driver->new_event($event)) {
          $event['id'] = $event['uid'];
          $this->cleanup_event($event);
        }
        $reload = $success && $event['recurrence'] ? 2 : 1;
        break;
        
      case "edit":
        $this->prepare_event($event, $action);
        if ($success = $this->driver->edit_event($event))
            $this->cleanup_event($event);
        $reload =  $success && ($event['recurrence'] || $event['_savemode'] || $event['_fromcalendar']) ? 2 : 1;
        break;
      
      case "resize":
        $this->prepare_event($event, $action);
        $success = $this->driver->resize_event($event);
        $reload = $event['_savemode'] ? 2 : 1;
        break;
      
      case "move":
        $this->prepare_event($event, $action);
        $success = $this->driver->move_event($event);
        $reload =  $success && $event['_savemode'] ? 2 : 1;
        break;
      
      case "remove":
        // remove previous deletes
        $undo_time = $this->driver->undelete ? $this->rc->config->get('undo_timeout', 0) : 0;
        $this->rc->session->remove('calendar_event_undo');
        
        // search for event if only UID is given
        if (!isset($event['calendar']) && $event['uid']) {
          if (!($event = $this->driver->get_event($event, true))) {
            break;
          }
          $undo_time = 0;
        }

        $success = $this->driver->remove_event($event, $undo_time < 1);
        $reload = (!$success || $event['_savemode']) ? 2 : 1;

        if ($undo_time > 0 && $success) {
          $_SESSION['calendar_event_undo'] = array('ts' => time(), 'data' => $event);
          // display message with Undo link.
          $msg = html::span(null, $this->gettext('successremoval'))
            . ' ' . html::a(array('onclick' => sprintf("%s.http_request('event', 'action=undo', %s.display_message('', 'loading'))",
              JS_OBJECT_NAME, JS_OBJECT_NAME)), rcube_label('undo'));
          $this->rc->output->show_message($msg, 'confirmation', null, true, $undo_time);
          $got_msg = true;
        }
        else if ($success) {
          $this->rc->output->show_message('calendar.successremoval', 'confirmation');
          $got_msg = true;
        }

        // send iTIP reply that participant has declined the event
        if ($success && $event['decline']) {
          $emails = $this->get_user_emails();
          foreach ($old['attendees'] as $i => $attendee) {
            if ($attendee['role'] == 'ORGANIZER')
              $organizer = $attendee;
            else if ($attendee['email'] && in_array($attendee['email'], $emails)) {
              $old['attendees'][$i]['status'] = 'DECLINED';
            }
          }
          
          $itip = $this->load_itip();
          if ($organizer && $itip->send_itip_message($old, 'REPLY', $organizer, 'itipsubjectdeclined', 'itipmailbodydeclined'))
            $this->rc->output->command('display_message', $this->gettext(array('name' => 'sentresponseto', 'vars' => array('mailto' => $organizer['name'] ? $organizer['name'] : $organizer['email']))), 'confirmation');
          else
            $this->rc->output->command('display_message', $this->gettext('itipresponseerror'), 'error');
        }
        break;

      case "undo":
        // Restore deleted event
        $event  = $_SESSION['calendar_event_undo']['data'];

        if ($event)
          $success = $this->driver->restore_event($event);

        if ($success) {
          $this->rc->session->remove('calendar_event_undo');
          $this->rc->output->show_message('calendar.successrestore', 'confirmation');
          $got_msg = true;
          $reload = 2;
        }

        break;

      case "rsvp-status":
        $action = 'rsvp';
        $status = $event['fallback'];
        $html = html::div('rsvp-status', $status != 'CANCELLED' ? $this->gettext('acceptinvitation') : '');
        $this->load_driver();
        if ($existing = $this->driver->get_event($event, true)) {
          $emails = $this->get_user_emails();
          foreach ($existing['attendees'] as $i => $attendee) {
            if ($attendee['email'] && in_array($attendee['email'], $emails)) {
              $status = $attendee['status'];
              break;
            }
          }
        }
        
        if ($status == 'unknown') {
          $html = html::div('rsvp-status', $this->gettext('notanattendee'));
          $action = 'import';
        }
        else if (in_array($status, array('ACCEPTED','TENTATIVE','DECLINED'))) {
          $html = html::div('rsvp-status ' . strtolower($status), $this->gettext('youhave'.strtolower($status)));
          if ($existing['changed'] && $event['changed'] < $existing['changed']) {
            $action = '';
          }
        }
        
        $this->rc->output->command('plugin.update_event_rsvp_status', array(
          'uid' => $event['uid'],
          'id' => asciiwords($event['uid'], true),
          'status' => $status,
          'action' => $action,
          'html' => $html,
        ));
        return;

      case "rsvp":
        $ev = $this->driver->get_event($event);
        $ev['attendees'] = $event['attendees'];
        $event = $ev;

        if ($success = $this->driver->edit_event($event)) {
          $status = get_input_value('status', RCUBE_INPUT_GPC);
          $organizer = null;
          foreach ($event['attendees'] as $i => $attendee) {
            if ($attendee['role'] == 'ORGANIZER') {
              $organizer = $attendee;
              break;
            }
          }
          $itip = $this->load_itip();
          if ($organizer && $itip->send_itip_message($event, 'REPLY', $organizer, 'itipsubject' . $status, 'itipmailbody' . $status))
            $this->rc->output->command('display_message', $this->gettext(array('name' => 'sentresponseto', 'vars' => array('mailto' => $organizer['name'] ? $organizer['name'] : $organizer['email']))), 'confirmation');
          else
            $this->rc->output->command('display_message', $this->gettext('itipresponseerror'), 'error');
        }
        break;

      case "dismiss":
        foreach (explode(',', $event['id']) as $id)
          $success |= $this->driver->dismiss_alarm($id, $event['snooze']);
        break;
    }
    
    // send out notifications
    if ($success && $event['_notify'] && ($event['attendees'] || $old['attendees'])) {
      // make sure we have the complete record
      $event = $action == 'remove' ? $old : $this->driver->get_event($event);
      
      // only notify if data really changed (TODO: do diff check on client already)
      if (!$old || $action == 'remove' || self::event_diff($event, $old)) {
        if ($this->notify_attendees($event, $old, $action) < 0)
          $this->rc->output->show_message('calendar.errornotifying', 'error');
        }
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

    // update event object on the client or trigger a complete refretch if too complicated
    if ($reload) {
      $args = array('source' => $event['calendar']);
      if ($reload > 1)
        $args['refetch'] = true;
      else if ($success && $action != 'remove')
        $args['update'] = $this->_client_event($this->driver->get_event($event));
      $this->rc->output->command('plugin.refresh_calendar', $args);
    }
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
   * Handler for check-recent requests which are accidentally sent to calendar taks
   */
  function check_recent()
  {
    // NOP
    $this->rc->output->send();
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
    $calendar_name = get_input_value('source', RCUBE_INPUT_GET);
    $events = $this->driver->load_events($start, $end, null, $calendar_name, 0);
   
    header("Content-Type: text/calendar");
    header("Content-Disposition: inline; filename=".$calendar_name.'.ics');
    
    $this->get_ical()->export($events, '', true);
    exit;
  }

  /**
   *
   */
  function load_settings()
  {
    $this->date_format_defaults();
    $settings = array();
    
    // configuration
    $settings['default_calendar'] = $this->rc->config->get('calendar_default_calendar');
    $settings['default_view'] = (string)$this->rc->config->get('calendar_default_view', $this->defaults['calendar_default_view']);
    
    $settings['date_format'] = (string)$this->rc->config->get('calendar_date_format', $this->defaults['calendar_date_format']);
    $settings['time_format'] = (string)$this->rc->config->get('calendar_time_format', $this->defaults['calendar_time_format']);
    $settings['date_short'] = (string)$this->rc->config->get('calendar_date_short', $this->defaults['calendar_date_short']);
    $settings['date_long']  = (string)$this->rc->config->get('calendar_date_long', $this->defaults['calendar_date_long']);
    $settings['dates_long'] = str_replace(' yyyy', '[ yyyy]', $settings['date_long']) . "{ '&mdash;' " . $settings['date_long'] . '}';
    $settings['date_agenda'] = (string)$this->rc->config->get('calendar_date_agenda', $this->defaults['calendar_date_agenda']);
    
    $settings['timeslots'] = (int)$this->rc->config->get('calendar_timeslots', $this->defaults['calendar_timeslots']);
    $settings['first_day'] = (int)$this->rc->config->get('calendar_first_day', $this->defaults['calendar_first_day']);
    $settings['first_hour'] = (int)$this->rc->config->get('calendar_first_hour', $this->defaults['calendar_first_hour']);
    $settings['work_start'] = (int)$this->rc->config->get('calendar_work_start', $this->defaults['calendar_work_start']);
    $settings['work_end'] = (int)$this->rc->config->get('calendar_work_end', $this->defaults['calendar_work_end']);
    $settings['agenda_range'] = (int)$this->rc->config->get('calendar_agenda_range', $this->defaults['calendar_agenda_range']);
    $settings['agenda_sections'] = $this->rc->config->get('calendar_agenda_sections', $this->defaults['calendar_agenda_sections']);
    $settings['event_coloring'] = (int)$this->rc->config->get('calendar_event_coloring', $this->defaults['calendar_event_coloring']);
    $settings['timezone'] = $this->timezone;
    $settings['dst'] = $this->dst_active;

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
    $settings['today'] = $this->rc->gettext('today');

    // get user identity to create default attendee
    if ($this->ui->screen == 'calendar') {
      foreach ($this->rc->user->list_identities() as $rec) {
        if (!$identity)
          $identity = $rec;
        $identity['emails'][] = $rec['email'];
      }
      $identity['emails'][] = $this->rc->user->get_username();
      $settings['identity'] = array('name' => $identity['name'], 'email' => $identity['email'], 'emails' => ';' . join(';', $identity['emails']));
    }

    return $settings;
  }
  
  /**
   * Helper function to set date/time format according to config and user preferences
   */
  private function date_format_defaults()
  {
    static $defaults = array();
    
    // nothing to be done
    if (isset($defaults['date_format']))
      return;
    
    $defaults['date_format'] = $this->rc->config->get('calendar_date_format', self::from_php_date_format($this->rc->config->get('date_format')));
    $defaults['time_format'] = $this->rc->config->get('calendar_time_format', self::from_php_date_format($this->rc->config->get('time_format')));
    
    // override defaults
    if ($defaults['date_format'])
      $this->defaults['calendar_date_format'] = $defaults['date_format'];
    if ($defaults['time_format'])
      $this->defaults['calendar_time_format'] = $defaults['time_format'];
    
    // derive format variants from basic date format
    $format_sets = $this->rc->config->get('calendar_date_format_sets', $this->defaults['calendar_date_format_sets']);
    if ($format_set = $format_sets[$this->defaults['calendar_date_format']]) {
      $this->defaults['calendar_date_long'] = $format_set[0];
      $this->defaults['calendar_date_short'] = $format_set[1];
      $this->defaults['calendar_date_agenda'] = $format_set[2];
    }
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
   * Fix DST difference between client and target date
   */
  function fixDST($time)
  {
    $date = new DateTime(null, $this->user_timezone);
    $date->setTimeStamp($time);
    $diff = $date->format('I') - $this->dst_active;
    $time += $diff * 3600;
    
    return $time;
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
      $json[] = $this->_client_event($event, $addcss);
    }
    return json_encode($json);
  }

  /**
   * Convert an event object to be used on the client
   */
  private function _client_event($event, $addcss = false)
  {
    // compose a human readable strings for alarms_text and recurrence_text
    if ($event['alarms'])
      $event['alarms_text'] = $this->_alarms_text($event['alarms']);
    if ($event['recurrence'])
      $event['recurrence_text'] = $this->_recurrence_text($event['recurrence']);

    return array(
      'start' => gmdate('c', $this->fromGMT($event['start'])), // client treats date strings as they were in users's timezone
      'end'   => gmdate('c', $this->fromGMT($event['end'])),   // so shift timestamps to users's timezone and render a date string
      'description' => strval($event['description']),
      'location'    => strval($event['location']),
      'className'   => ($addcss ? 'fc-event-cal-'.asciiwords($event['calendar'], true).' ' : '') . 'fc-event-cat-' . asciiwords(strtolower($event['categories']), true),
      'allDay'      => ($event['allday'] == 1),
    ) + $event;
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
    
    if ($rrule['INTERVAL'] <= 1)
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
    return strtr(strtr($from, array(
      'yyyy' => 'Y',
      'yy'   => 'y',
      'MMMM' => 'F',
      'MMM'  => 'M',
      'MM'   => 'm',
      'M'    => 'n',
      'dddd' => 'l',
      'ddd'  => 'D',
      'dd'   => 'd',
      'HH'   => '**',
      'hh'   => '%%',
      'H'    => 'G',
      'h'    => 'g',
      'mm'   => 'i',
      'ss'   => 's',
      'TT'   => 'A',
      'tt'   => 'a',
      'T'    => 'A',
      't'    => 'a',
      'u'    => 'c',
    )), array(
      '**'   => 'H',
      '%%'   => 'h',
    ));
  }
  
  /**
   * Convert from PHP date() format to fullcalendar format string
   */
  private static function from_php_date_format($from)
  {
    // "d.m.Y H:i:s" => "dd.MM.yyyy HH:mm:ss"
    return strtr($from, array(
      'y' => 'yy',
      'Y' => 'yyyy',
      'M' => 'MMM',
      'F' => 'MMMM',
      'm' => 'MM',
      'n' => 'M',
      'd' => 'dd',
      'D' => 'ddd',
      'l' => 'dddd',
      'H' => 'HH',
      'h' => 'hh',
      'G' => 'H',
      'g' => 'h',
      'i' => 'mm',
      's' => 'ss',
      'A' => 'TT',
      'a' => 'tt',
      'c' => 'u',
    ));
  }
  
  /**
   * TEMPORARY: generate random event data for testing
   * Create events by opening http://<roundcubeurl>/?_task=calendar&_action=randomdata&_num=500
   */
  public function generate_randomdata()
  {
    $num = $_REQUEST['_num'] ? intval($_REQUEST['_num']) : 100;
    $cats = array_keys($this->driver->list_categories());
    $cals = array();
    foreach ($this->driver->list_calendars() as $cid => $cal) {
      if ($cal['active'])
        $cals[$cid] = $cal;
    }
    
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
        'priority' => rand(0,9),
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

    $eventid = 'cal:'.$event;

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
  private function prepare_event(&$event, $action)
  {
    $event['start'] = $this->fixDST($event['start']);
    $event['end'] = $this->fixDST($event['end']);

    $attachments = array();
    $eventid = 'cal:'.$event['id'];
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
    if ($event['attendees'] && ($action == 'new' || $action == 'edit')) {
      $emails = $this->get_user_emails();
      $organizer = $owner = false;
      foreach ($event['attendees'] as $i => $attendee) {
        if ($attendee['role'] == 'ORGANIZER')
          $organizer = true;
        if ($attendee['email'] == in_array($attendee['email'], $emails))
          $owner = $i;
        else if (!isset($attendee['rsvp']))
          $event['attendees'][$i]['rsvp'] = true;
      }
      
      // set owner as organizer if yet missing
      if (!$organizer && $owner !== false) {
        $event['attendees'][$owner]['role'] = 'ORGANIZER';
        unset($event['attendees'][$owner]['rsvp']);
      }
      else if (!$organizer && $action == 'new' && ($identity = $this->rc->user->get_identity()) && $identity['email']) {
        array_unshift($event['attendees'], array('role' => 'ORGANIZER', 'name' => $identity['name'], 'email' => $identity['email'], 'status' => 'ACCEPTED'));
      }
    }
  }

  /**
   * Releases some resources after successful event save
   */
  private function cleanup_event(&$event)
  {
    // remove temp. attachment files
    $eventid = 'cal:'.$event['id'];
    if (!empty($_SESSION['event_session']) && ($eventid = $_SESSION['event_session']['id'])) {
      $this->rc->plugins->exec_hook('attachments_cleanup', array('group' => $eventid));
      unset($_SESSION['event_session']);
    }
  }

  /**
   * Send out an invitation/notification to all event attendees
   */
  private function notify_attendees($event, $old, $action = 'edit')
  {
    if ($action == 'remove') {
      $event['cancelled'] = true;
      $is_cancelled = true;
    }
    
    $itip = $this->load_itip();
    $emails = $this->get_user_emails();

    // compose multipart message using PEAR:Mail_Mime
    $method = $action == 'remove' ? 'CANCEL' : 'REQUEST';
    $message = $itip->compose_itip_message($event, $method);

    // list existing attendees from $old event
    $old_attendees = array();
    foreach ((array)$old['attendees'] as $attendee) {
      $old_attendees[] = $attendee['email'];
    }

    // send to every attendee
    $sent = 0;
    foreach ((array)$event['attendees'] as $attendee) {
      // skip myself for obvious reasons
      if (!$attendee['email'] || in_array($attendee['email'], $emails))
        continue;
      
      // which template to use for mail text
      $is_new = !in_array($attendee['email'], $old_attendees);
      $bodytext = $is_cancelled ? 'eventcancelmailbody' : ($is_new ? 'invitationmailbody' : 'eventupdatemailbody');
      $subject  = $is_cancelled ? 'eventcancelsubject'  : ($is_new ? 'invitationsubject' : ($event['title'] ? 'eventupdatesubject':'eventupdatesubjectempty'));
      
      // finally send the message
      if ($itip->send_itip_message($event, $method, $attendee, $subject, $bodytext, $message))
        $sent++;
      else
        $sent = -100;
    }
    
    return $sent;
  }
  
  /**
   * Compose a date string for the given event
   */
  public function event_date_text($event, $tzinfo = false)
  {
    $fromto = '';
    $duration = $event['end'] - $event['start'];
    
    $this->date_format_defaults();
    $date_format = self::to_php_date_format($this->rc->config->get('calendar_date_format', $this->defaults['calendar_date_format']));
    $time_format = self::to_php_date_format($this->rc->config->get('calendar_time_format', $this->defaults['calendar_time_format']));
    
    if ($event['allday']) {
      $fromto = format_date($event['start'], $date_format);
      if (($todate = format_date($event['end'], $date_format)) != $fromto)
        $fromto .= ' - ' . $todate;
    }
    else if ($duration < 86400 && gmdate('d', $event['start']) == gmdate('d', $event['end'])) {
      $fromto = format_date($event['start'], $date_format) . ' ' . format_date($event['start'], $time_format) .
        ' - ' . format_date($event['end'], $time_format);
    }
    else {
      $fromto = format_date($event['start'], $date_format) . ' ' . format_date($event['start'], $time_format) .
        ' - ' . format_date($event['end'], $date_format) . ' ' . format_date($event['end'], $time_format);
    }
    
    // add timezone information
    if ($tzinfo && ($tzname = $this->user_timezone->getName())) {
      $fromto .= ' (' . $tzname . ')';
    }
    
    return $fromto;
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
    
    $fbtypemap = array(calendar::FREEBUSY_UNKNOWN => 'UNKNOWN', calendar::FREEBUSY_FREE => 'FREE', calendar::FREEBUSY_BUSY => 'BUSY', calendar::FREEBUSY_TENTATIVE => 'TENTATIVE', calendar::FREEBUSY_OOF => 'OUT-OF-OFFICE');
    $status = 'UNKNOWN';
    
    // if the backend has free-busy information
    $fblist = $this->driver->get_freebusy_list($email, $start, $end);
    if (is_array($fblist)) {
      $status = 'FREE';
      
      foreach ($fblist as $slot) {
        list($from, $to, $type) = $slot;
        if ($from < $end && $to > $start) {
          $status = isset($type) && $fbtypemap[$type] ? $fbtypemap[$type] : 'BUSY';
          break;
        }
      }
    }
    
    // let this information be cached for 5min
    send_future_expire_header(300);
    
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
    $interval = intval(get_input_value('interval', RCUBE_INPUT_GET));
    
    if (!$start) $start = time();
    if (!$end)   $end = $start + 86400 * 30;
    if (!$interval) $interval = 60;  // 1 hour
    
    $fblist = $this->driver->get_freebusy_list($email, $start, $end);
    $slots = array();
    
    // build a list from $start till $end with blocks representing the fb-status
    for ($s = 0, $t = $start; $t <= $end; $s++) {
      $status = self::FREEBUSY_UNKNOWN;
      $t_end = $t + $interval * 60;
        
      // determine attendee's status
      if (is_array($fblist)) {
        $status = self::FREEBUSY_FREE;
        foreach ($fblist as $slot) {
          list($from, $to, $type) = $slot;
          if ($from < $t_end && $to > $t) {
            $status = isset($type) ? $type : self::FREEBUSY_BUSY;
            if ($status == self::FREEBUSY_BUSY)  // can't get any worse :-)
              break;
          }
        }
      }
      
      $slots[$s] = $status;
      $t = $t_end;
    }
    
    // let this information be cached for 5min
    send_future_expire_header(300);
    
    echo json_encode(array('email' => $email, 'start' => intval($start), 'end' => intval($t_end), 'interval' => $interval, 'slots' => $slots));
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

    if ($range = get_input_value('range', RCUBE_INPUT_GPC))
      $this->rc->output->set_env('listRange', intval($range));

    if (isset($_REQUEST['sections']))
      $this->rc->output->set_env('listSections', get_input_value('sections', RCUBE_INPUT_GPC));
    
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

  /**
   *
   */
  public function get_inline_ui()
  {
    foreach (array('save','cancel','savingdata') as $label)
      $texts['calendar.'.$label] = $this->gettext($label);
    
    $texts['calendar.new_event'] = $this->gettext('createfrommail');
    
    $this->ui->init_templates();
    $this->ui->calendar_list();  # set env['calendars']
    echo $this->api->output->parse('calendar.eventedit', false, false);
    echo html::tag('script', array('type' => 'text/javascript'),
      "rcmail.set_env('calendars', " . json_encode($this->api->output->env['calendars']) . ");\n".
      "rcmail.set_env('deleteicon', '" . $this->api->output->env['deleteicon'] . "');\n".
      "rcmail.set_env('cancelicon', '" . $this->api->output->env['cancelicon'] . "');\n".
      "rcmail.set_env('loadingicon', '" . $this->api->output->env['loadingicon'] . "');\n".
      "rcmail.add_label(" . json_encode($texts) . ");\n"
    );
    exit;
  }

  /**
   * Compare two event objects and return differing properties
   *
   * @param array Event A
   * @param array Event B
   * @return array List of differing event properties
   */
  public static function event_diff($a, $b)
  {
    $diff = array();
    $ignore = array('changed' => 1, 'attachments' => 1);
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
      if (!$ignore[$key] && $a[$key] != $b[$key])
        $diff[] = $key;
    }
    
    // only compare number of attachments
    if (count($a['attachments']) != count($b['attachments']))
      $diff[] = 'attachments';
    
    return $diff;
  }


  /****  Event invitation plugin hooks ****/
  
  /**
   * Handler for URLs that allow an invitee to respond on his invitation mail
   */
  public function itip_attend_response($p)
  {
    if ($p['action'] == 'attend') {
      $this->rc->output->set_env('task', 'calendar');  // override some env vars
      $this->rc->output->set_env('keep_alive', 0);
      $this->rc->output->set_pagetitle($this->gettext('calendar'));

      $itip = $this->load_itip();
      $token = get_input_value('_t', RCUBE_INPUT_GPC);
      
      // read event info stored under the given token
      if ($invitation = $itip->get_invitation($token)) {
        $this->token = $token;
        $this->event = $invitation['event'];

        // show message about cancellation
        if ($invitation['cancelled']) {
          $this->invitestatus = html::div('rsvp-status declined', $this->gettext('eventcancelled'));
        }
        // save submitted RSVP status
        else if (!empty($_POST['rsvp'])) {
          $status = null;
          foreach (array('accepted','tentative','declined') as $method) {
            if ($_POST['rsvp'] == $this->gettext('itip' . $method)) {
              $status = $method;
              break;
            }
          }
          
          // send itip reply to organizer
          if ($status && $itip->update_invitation($invitation, $invitation['attendee'], strtoupper($status))) {
            $this->invitestatus = html::div('rsvp-status ' . strtolower($status), $this->gettext('youhave'.strtolower($status)));
          }
          else
            $this->rc->output->command('display_message', $this->gettext('errorsaving'), 'error', -1);
        }
        
        $this->register_handler('plugin.event_inviteform', array($this, 'itip_event_inviteform'));
        $this->register_handler('plugin.event_invitebox', array($this->ui, 'event_invitebox'));
        
        if (!$this->invitestatus)
          $this->register_handler('plugin.event_rsvp_buttons', array($this->ui, 'event_rsvp_buttons'));
        
        $this->rc->output->set_pagetitle($this->gettext('itipinvitation') . ' ' . $this->event['title']);
      }
      else
        $this->rc->output->command('display_message', $this->gettext('itipinvalidrequest'), 'error', -1);
      
      $this->rc->output->send('calendar.itipattend');
    }
  }
  
  /**
   *
   */
  public function itip_event_inviteform($p)
  {
    $hidden = new html_hiddenfield(array('name' => "_t", 'value' => $this->token));
    return html::tag('form', array('action' => $this->rc->url(array('task' => 'calendar', 'action' => 'attend')), 'method' => 'post', 'noclose' => true)) . $hidden->show();
  }
  
  /**
   * Check mail message structure of there are .ics files attached
   */
  public function mail_message_load($p)
  {
    $this->message = $p['object'];
    $itip_part = null;

    // check all message parts for .ics files
    foreach ((array)$this->message->mime_parts as $idx => $part) {
      if ($this->is_vcalendar($part)) {
        if ($part->ctype_parameters['method'])
          $itip_part = $part->mime_id;
        else
          $this->ics_parts[] = $part->mime_id;
      }
    }
    
    // priorize part with method parameter
    if ($itip_part)
      $this->ics_parts = array($itip_part);
  }

  /**
   * Add UI element to copy event invitations or updates to the calendar
   */
  public function mail_messagebody_html($p)
  {
    // load iCalendar functions (if necessary)
    if (!empty($this->ics_parts)) {
      $this->get_ical();
    }

    $html = '';
    foreach ($this->ics_parts as $mime_id) {
      $part = $this->message->mime_parts[$mime_id];
      $charset = $part->ctype_parameters['charset'] ? $part->ctype_parameters['charset'] : RCMAIL_CHARSET;
      $events = $this->ical->import($this->message->get_part_content($mime_id), $charset);
      
      // successfully parsed events?
      if (empty($events))
          continue;

      // show a box for every event in the file
      foreach ($events as $idx => $event) {
        // define buttons according to method
        if ($this->ical->method == 'REPLY') {
          $title = $this->gettext('itipreply');
          $buttons = html::tag('input', array(
            'type' => 'button',
            'class' => 'button',
            'onclick' => "rcube_calendar.add_event_from_mail('" . JQ($mime_id.':'.$idx) . "')",
            'value' => $this->gettext('updateattendeestatus'),
          ));
        }
        else if ($this->ical->method == 'REQUEST') {
          $emails = $this->get_user_emails();
          $title = $event['SEQUENCE'] > 0 ? $this->gettext('itipupdate') : $this->gettext('itipinvitation');
          
          // add (hidden) buttons and activate them from asyncronous request
          foreach (array('accepted','tentative','declined') as $method) {
            $rsvp_buttons .= html::tag('input', array(
              'type' => 'button',
              'class' => 'button',
              'onclick' => "rcube_calendar.add_event_from_mail('" . JQ($mime_id.':'.$idx) . "', '$method')",
              'value' => $this->gettext('itip' . $method),
            ));
          }
          $import_button = html::tag('input', array(
            'type' => 'button',
            'class' => 'button',
            'onclick' => "rcube_calendar.add_event_from_mail('" . JQ($mime_id.':'.$idx) . "')",
            'value' => $this->gettext('importtocalendar'),
          ));
          
          // check my status
          $status = 'unknown';
          foreach ($event['attendees'] as $i => $attendee) {
            if ($attendee['email'] && in_array($attendee['email'], $emails)) {
              $status = strtoupper($attendee['status']);
              break;
            }
          }
          
          $dom_id = asciiwords($event['uid'], true);
          $buttons = html::div(array('id' => 'rsvp-'.$dom_id, 'style' => 'display:none'), $rsvp_buttons);
          $buttons .= html::div(array('id' => 'import-'.$dom_id, 'style' => 'display:none'), $import_button);
          $buttons_pre = html::div(array('id' => 'loading-'.$dom_id, 'class' => 'rsvp-status loading'), $this->gettext('loading'));
          
          $this->rc->output->add_script('rcube_calendar.fetch_event_rsvp_status(' . json_serialize(array('uid' => $event['uid'], 'changed' => $event['changed'], 'fallback' => $status)) . ')', 'docready');
        }
        else if ($this->ical->method == 'CANCEL') {
          $title = $this->gettext('itipcancellation');
          
          // create buttons to be activated from async request checking existence of this event in local calendars
          $button_import = html::tag('input', array(
            'type' => 'button',
            'class' => 'button',
            'onclick' => "rcube_calendar.add_event_from_mail('" . JQ($mime_id.':'.$idx) . "')",
            'value' => $this->gettext('importtocalendar'),
          ));
          $button_remove = html::tag('input', array(
            'type' => 'button',
            'class' => 'button',
            'onclick' => "rcube_calendar.remove_event_from_mail('" . JQ($event['uid']) . "', '" . JQ($event['title']) . "')",
            'value' => $this->gettext('removefromcalendar'),
          ));
          
          $dom_id = asciiwords($event['uid'], true);
          $buttons = html::div(array('id' => 'rsvp-'.$dom_id, 'style' => 'display:none'), $button_remove);
          $buttons .= html::div(array('id' => 'import-'.$dom_id, 'style' => 'display:none'), $button_import);
          $buttons_pre = html::div(array('id' => 'loading-'.$dom_id, 'class' => 'rsvp-status loading'), $this->gettext('loading'));
          
          $this->rc->output->add_script('rcube_calendar.fetch_event_rsvp_status(' . json_serialize(array('uid' => $event['uid'], 'changed' => $event['changed'], 'fallback' => 'CANCELLED')) . ')', 'docready');
        }
        else {
          $buttons = html::tag('input', array(
            'type' => 'button',
            'class' => 'button',
            'onclick' => "rcube_calendar.add_event_from_mail('" . JQ($mime_id.':'.$idx) . "')",
            'value' => $this->gettext('importtocalendar'),
          ));
        }
        
        // show event details with buttons
        $html .= html::div('calendar-invitebox', $this->ui->event_details_table($event, $title) . $buttons_pre . html::div('rsvp-buttons', $buttons));
        
        // limit listing
        if ($idx >= 3)
          break;
      }
    }

    // prepend event boxes to message body
    if ($html) {
      $this->ui->init();
      $p['content'] = $html . $p['content'];
      $this->rc->output->add_label('calendar.savingdata','calendar.deleteventconfirm');
    }

    return $p;
  }


  /**
   * Handler for POST request to import an event attached to a mail message
   */
  public function mail_import_event()
  {
    $uid = get_input_value('_uid', RCUBE_INPUT_POST);
    $mbox = get_input_value('_mbox', RCUBE_INPUT_POST);
    $mime_id = get_input_value('_part', RCUBE_INPUT_POST);
    $status = get_input_value('_status', RCUBE_INPUT_POST);
    $charset = RCMAIL_CHARSET;
    
    // establish imap connection
    $this->rc->imap_connect();
    $this->rc->imap->set_mailbox($mbox);

    if ($uid && $mime_id) {
      list($mime_id, $index) = explode(':', $mime_id);
      $part = $this->rc->imap->get_message_part($uid, $mime_id);
      if ($part->ctype_parameters['charset'])
        $charset = $part->ctype_parameters['charset'];
      $headers = $this->rc->imap->get_headers($uid);
    }

    $events = $this->get_ical()->import($part, $charset);

    $error_msg = $this->gettext('errorimportingevent');
    $success = false;

    // successfully parsed events?
    if (!empty($events) && ($event = $events[$index])) {
      // find writeable calendar to store event
      $cal_id = $this->rc->config->get('calendar_default_calendar');
      $calendars = $this->driver->list_calendars();
      $calendar = $calendars[$cal_id] ? $calendars[$calname] : null;
      if (!$calendar || $calendar['readonly']) {
        foreach ($calendars as $cal) {
          if (!$cal['readonly']) {
            $calendar = $cal;
            break;
          }
        }
      }

      // update my attendee status according to submitted method
      if (!empty($status)) {
        $organizer = null;
        $emails = $this->get_user_emails();
        foreach ($event['attendees'] as $i => $attendee) {
          if ($attendee['role'] == 'ORGANIZER') {
            $organizer = $attendee;
          }
          else if ($attendee['email'] && in_array($attendee['email'], $emails)) {
            $event['attendees'][$i]['status'] = strtoupper($status);
          }
        }
      }
      
      // save to calendar
      if ($calendar && !$calendar['readonly']) {
        $event['id'] = $event['uid'];
        $event['calendar'] = $calendar['id'];
        
        // check for existing event with the same UID
        $existing = $this->driver->get_event($event['uid'], true);
        
        if ($existing) {
          // only update attendee status
          if ($this->ical->method == 'REPLY') {
            // try to identify the attendee using the email sender address
            $sender = preg_match('/([a-z0-9][a-z0-9\-\.\+\_]*@[^&@"\'.][^@&"\']*\\.([^\\x00-\\x40\\x5b-\\x60\\x7b-\\x7f]{2,}|xn--[a-z0-9]{2,}))/', $headers->from, $m) ? $m[1] : '';
            $sender_utf = rcube_idn_to_utf8($sender);
            
            $existing_attendee = -1;
            foreach ($existing['attendees'] as $i => $attendee) {
              if ($sender && ($attendee['email'] == $sender || $attendee['email'] == $sender_utf)) {
                $existing_attendee = $i;
                break;
              }
            }
            $event_attendee = null;
            foreach ($event['attendees'] as $attendee) {
              if ($sender && ($attendee['email'] == $sender || $attendee['email'] == $sender_utf)) {
                $event_attendee = $attendee;
                break;
              }
            }
            
            // found matching attendee entry in both existing and new events
            if ($existing_attendee >= 0 && $event_attendee) {
              $existing['attendees'][$existing_attendee] = $event_attendee;
              $success = $this->driver->edit_event($existing);
            }
            // update the entire attendees block
            else if ($event['changed'] >= $existing['changed'] && $event['attendees']) {
              $existing['attendees'] = $event['attendees'];
              $success = $this->driver->edit_event($existing);
            }
            else {
              $error_msg = $this->gettext('newerversionexists');
            }
          }
          // import the (newer) event
          // TODO: compare SEQUENCE numbers instead of changed dates
          else if ($event['changed'] >= $existing['changed']) {
            $success = $this->driver->edit_event($event);
          }
          else if (!empty($status)) {
            $existing['attendees'] = $event['attendees'];
            $success = $this->driver->edit_event($existing);
          }
          else
            $error_msg = $this->gettext('newerversionexists');
        }
        else if (!$existing && $status != 'declined') {
          $success = $this->driver->new_event($event);
        }
        else if ($status == 'declined')
          $error_msg = null;
      }
      else if ($status == 'declined')
        $error_msg = null;
      else
        $error_msg = $this->gettext('nowritecalendarfound');
    }

    if ($success) {
      $message = $this->ical->method == 'REPLY' ? 'attendeupdateesuccess' : 'importedsuccessfully';
      $this->rc->output->command('display_message', $this->gettext(array('name' => $message, 'vars' => array('calendar' => $calendar['name']))), 'confirmation');
      $error_msg = null;
    }
    else if ($error_msg)
      $this->rc->output->command('display_message', $error_msg, 'error');


    // send iTip reply
    if ($this->ical->method == 'REQUEST' && $organizer && !in_array($organizer['email'], $emails) && !$error_msg) {
      $itip = $this->load_itip();
      if ($itip->send_itip_message($event, 'REPLY', $organizer, 'itipsubject' . $status, 'itipmailbody' . $status))
        $this->rc->output->command('display_message', $this->gettext(array('name' => 'sentresponseto', 'vars' => array('mailto' => $organizer['name'] ? $organizer['name'] : $organizer['email']))), 'confirmation');
      else
        $this->rc->output->command('display_message', $this->gettext('itipresponseerror'), 'error');
    }

    $this->rc->output->send();
  }


  /**
   * Read email message and return contents for a new event based on that message
   */
  public function mail_message2event()
  {
    $uid = get_input_value('_uid', RCUBE_INPUT_POST);
    $mbox = get_input_value('_mbox', RCUBE_INPUT_POST);
    $event = array();
    
    // establish imap connection
    $this->rc->imap_connect();
    $this->rc->imap->set_mailbox($mbox);
    $message = new rcube_message($uid);

    if ($message->headers) {
      $event['title'] = trim($message->subject);
      $event['description'] = trim($message->first_text_part());
      
      // copy mail attachments to event
      if ($message->attachments) {
        $eventid = 'cal:';
        if (!is_array($_SESSION['event_session']) || $_SESSION['event_session']['id'] != $eventid) {
          $_SESSION['event_session'] = array();
          $_SESSION['event_session']['id'] = $eventid;
          $_SESSION['event_session']['attachments'] = array();
        }

        foreach ((array)$message->attachments as $part) {
          $attachment = array(
            'data' => $this->rc->imap->get_message_part($uid, $part->mime_id, $part),
            'size' => $part->size,
            'name' => $part->filename,
            'mimetype' => $part->mimetype,
            'group' => $eventid,
          );

          $attachment = $this->rc->plugins->exec_hook('attachment_save', $attachment);

          if ($attachment['status'] && !$attachment['abort']) {
            $id = $attachment['id'];

            // store new attachment in session
            unset($attachment['status'], $attachment['abort'], $attachment['data']);
            $_SESSION['event_session']['attachments'][$id] = $attachment;

            $attachment['id'] = 'rcmfile' . $attachment['id'];  # add prefix to consider it 'new'
            $event['attachments'][] = $attachment;
          }
        }
      }
      
      $this->rc->output->command('plugin.mail2event_dialog', $event);
    }
    else {
      $this->rc->output->command('display_message', $this->gettext('messageopenerror'), 'error');
    }
    
    $this->rc->output->send();
  }


  /**
   * Checks if specified message part is a vcalendar data
   *
   * @param rcube_message_part Part object
   * @return boolean True if part is of type vcard
   */
  private function is_vcalendar($part)
  {
    return (
      in_array($part->mimetype, array('text/calendar', 'text/x-vcalendar', 'application/ics')) ||
      // Apple sends files as application/x-any (!?)
      ($part->mimetype == 'application/x-any' && $part->filename && preg_match('/\.ics$/i', $part->filename))
    );
  }


  /**
   * Get a list of email addresses of the current user (from login and identities)
   */
  private function get_user_emails()
  {
    $emails = array($this->rc->user->get_username());
    foreach ($this->rc->user->list_identities() as $identity)
      $emails[] = $identity['email'];
    
    return array_unique($emails);
  }


  /**
   * Build an absolute URL with the given parameters
   */
  public function get_url($param = array())
  {
    $param += array('task' => 'calendar');
    
    $schema = 'http';
    $default_port = 80;
    if (rcube_https_check()) {
      $schema = 'https';
      $default_port = 143;
    }
    $url = $schema . '://' . $_SERVER['HTTP_HOST'];
    if ($_SERVER['SERVER_PORT'] != $default_port)
      $url .= ':' . $_SERVER['SERVER_PORT'];
    if (dirname($_SERVER['SCRIPT_NAME']) != '/')
      $url .= dirname($_SERVER['SCRIPT_NAME']);
    $url .= preg_replace('!^\./!', '/', $this->rc->url($param));
    
    return $url; 
  }

}

