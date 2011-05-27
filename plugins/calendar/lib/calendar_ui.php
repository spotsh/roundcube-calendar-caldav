<?php
/*
 +-------------------------------------------------------------------------+
 | User Interface for the Calendar Plugin                                  |
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
 +-------------------------------------------------------------------------+
*/

class calendar_ui
{
  private $rc;
  private $calendar;

  function __construct($calendar)
  {
    $this->calendar = $calendar;
    $this->rc = $calendar->rc;
  }
    
  /**
   * Calendar UI initialization and requests handlers
   */
  public function init()
  {
    // add taskbar button
    $this->calendar->add_button(array(
      'name' => 'calendar',
      'class' => 'button-calendar',
      'label' => 'calendar.calendar',
      'href' => './?_task=calendar',
      ), 'taskbar');
  }
  
  /**
   * Adds CSS stylesheets to the page header
   */
  public function addCSS()
  {
    $skin = $this->rc->config->get('skin');
    $this->calendar->include_stylesheet('skins/' . $skin . '/fullcalendar.css');
  }

  /**
   * Adds JS files to the page header
   */
  public function addJS()
  {
      $this->calendar->include_script('lib/js/fullcalendar.js');
      $this->calendar->include_script('calendar.js');
  }
  
  /**
   * Creates the Calendar toolbar
   */
  public function toolbar()
  {
    $skin = $this->rc->config->get('skin');
    $this->calendar->add_button(array(
      'command' => 'plugin.export_events',
      'href' => './?_task=calendar&amp;_action=plugin.export_events',
      'title' => 'calendar.export',
      'imagepas' => 'skins/' . $skin . '/images/export.png',
      'imageact' => 'skins/' . $skin . '/images/export.png'),
      'toolbar'
    );
  }
  
  /**
   *
   */
  function calendar_css()
  {
    $categories = $this->rc->config->get('calendar_categories', array());

    $css = "\n";
    
    foreach ((array)$categories as $class => $color) {
        $class = 'cat-' . asciiwords($class, true);
        $css .= "." . $class . ",\n";
        $css .= ".fc-event-" . $class . ",\n";
        $css .= "." . $class . " a {\n";
        $css .= "color: #" . $color . ";\n";
        $css .= "border-color: #" . $color . ";\n";
        $css .= "}\n";
    }
    
    $calendars = $this->calendar->driver->list_calendars();
    foreach ((array)$calendars as $id => $prop) {
      if (!$prop['color'])
        continue;
      $color = $prop['color'];
      $class = 'cal-' . asciiwords($id, true);
      $css .= "li." . $class . ", ";
      $css .= "#eventshow ." . $class . " { ";
      $css .= "color: #" . $color . " }\n";
      $css .= ".fc-event-" . $class . ", ";
      $css .= ".fc-event-" . $class . " .fc-event-inner, ";
      $css .= ".fc-event-" . $class . " .fc-event-time {\n";
      $css .= "background-color: #" . $color . ";\n";
      $css .= "border-color: #" . $color . ";\n";
      $css .= "}\n";
    }
    
    return html::tag('style', array('type' => 'text/css'), $css);
  }

  /**
   *
   */
  function calendar_list($attrib = array())
  {
    $calendars = $this->calendar->driver->list_calendars();

    $li = '';
    foreach ((array)$calendars as $id => $prop) {
      unset($prop['user_id']);
      $prop['alarms'] = $this->calendar->driver->alarms;
      $prop['attendees'] = $this->calendar->driver->attendees;
      $prop['attachments'] = $this->calendar->driver->attachments;
      $jsenv[$id] = $prop;
      
      $html_id = html_identifier($id);
      $li .= html::tag('li', array('id' => 'rcmlical' . $html_id, 'class' =>'cal-'  . asciiwords($id, true)),
        html::tag('input', array('type' => 'checkbox', 'name' => '_cal[]', 'value' => $id, 'checked' => true), '') . html::span(null, Q($prop['name'])));
    }

    $this->rc->output->set_env('calendars', $jsenv);
    $this->rc->output->add_gui_object('folderlist', $attrib['id']);
    
    unset($attrib['name']);
    return html::tag('ul', $attrib, $li);
  }

  /**
   * Render a HTML select box for calendar selection
   */
  function calendar_select($attrib = array())
  {
    $attrib['name'] = 'calendar';
    $select = new html_select($attrib);
    foreach ((array)$this->calendar->driver->list_calendars() as $id => $prop) {
      $select->add($prop['name'], $id);
    }

    return $select->show(null);
  }

  /**
   * Render a HTML select box to select an event category
   */
  function category_select($attrib = array())
  {
    $attrib['name'] = 'categories';
    $select = new html_select($attrib);
    $select->add('---', '');
    foreach ((array)$this->calendar->driver->list_categories() as $cat => $color) {
      $select->add($cat, $cat);
    }

    return $select->show(null);
  }

  /**
   * Render a HTML select box for free/busy/out-of-office property
   */
  function freebusy_select($attrib = array())
  {
    $attrib['name'] = 'freebusy';
    $select = new html_select($attrib);
    $select->add($this->calendar->gettext('free'), 'free');
    $select->add($this->calendar->gettext('busy'), 'busy');
    $select->add($this->calendar->gettext('outofoffice'), 'outofoffice');
    return $select->show(null);
  }

  /**
   * Render a HTML select for event priorities
   */
  function priority_select($attrib = array())
  {
    $attrib['name'] = 'priority';
    $select = new html_select($attrib);
    $select->add($this->calendar->gettext('normal'), '1');
    $select->add($this->calendar->gettext('low'), '0');
    $select->add($this->calendar->gettext('high'), '2');
    return $select->show(null);
  }
  
  /**
   * Render HTML input for sensitivity selection
   */
  function sensitivity_select($attrib = array())
  {
    $attrib['name'] = 'sensitivity';
    $select = new html_select($attrib);
    $select->add($this->calendar->gettext('public'), '0');
    $select->add($this->calendar->gettext('private'), '1');
    $select->add($this->calendar->gettext('confidential'), '2');
    return $select->show(null);
  }
  
  /**
   * Render HTML form for alarm configuration
   */
  function alarm_select($attrib = array())
  {
    unset($attrib['name']);
    $select_type = new html_select(array('name' => 'alarmtype[]', 'class' => 'edit-alarm-type'));
    $select_type->add($this->calendar->gettext('none'), '');
    foreach ($this->calendar->driver->alarm_types as $type)
      $select_type->add($this->calendar->gettext(strtolower("alarm{$type}option")), $type);
     
    $input_value = new html_inputfield(array('name' => 'alarmvalue[]', 'class' => 'edit-alarm-value', 'size' => 3));
    $input_date = new html_inputfield(array('name' => 'alarmdate[]', 'class' => 'edit-alarm-date', 'size' => 10));
    $input_time = new html_inputfield(array('name' => 'alarmtime[]', 'class' => 'edit-alarm-time', 'size' => 6));
    
    $select_offset = new html_select(array('name' => 'alarmoffset[]', 'class' => 'edit-alarm-offset'));
    foreach (array('-M','-H','-D','+M','+H','+D','@') as $trigger)
      $select_offset->add($this->calendar->gettext('trigger' . $trigger), $trigger);
     
    // pre-set with default values from user settings
    $preset = calendar::parse_alaram_value($this->rc->config->get('calendar_default_alarm_offset', '-15M'));
    $hidden = array('style' => 'display:none');
    $html = html::span('edit-alarm-set',
      $select_type->show($this->rc->config->get('calendar_default_alarm_type', '')) . ' ' .
      html::span(array('class' => 'edit-alarm-values', 'style' => 'display:none'),
        $input_value->show($preset[0]) . ' ' .
        $select_offset->show($preset[1]) . ' ' .
        $input_date->show('', $hidden) . ' ' .
        $input_time->show('', $hidden)
      )
    );
    
    // TODO: support adding more alarms
    #$html .= html::a(array('href' => '#', 'id' => 'edit-alam-add', 'title' => $this->calendar->gettext('addalarm')),
    #  $attrib['addicon'] ? html::img(array('src' => $attrib['addicon'], 'alt' => 'add')) : '(+)');
     
    return $html;
  }

  function snooze_select($attrib = array())
  {
    $steps = array(
       5 => 'repeatinmin',
      10 => 'repeatinmin',
      15 => 'repeatinmin',
      20 => 'repeatinmin',
      30 => 'repeatinmin',
      60 => 'repeatinhr',
      120 => 'repeatinhrs',
      1440 => 'repeattomorrow',
      10080 => 'repeatinweek',
    );
    
    $items = array();
    foreach ($steps as $n => $label) {
      $items[] = html::tag('li', null, html::a(array('href' => "#" . ($n * 60), 'class' => 'active'),
        $this->calendar->gettext(array('name' => $label, 'vars' => array('min' => $n % 60, 'hrs' => intval($n / 60))))));
    }
    
    return html::tag('ul', $attrib, join("\n", $items));
  }

  /**
   * Generate the form for recurrence settings
   */
  function recurrence_form($attrib = array())
  {
    switch ($attrib['part']) {
      // frequency selector
      case 'frequency':
        $select = new html_select(array('name' => 'frequency', 'id' => 'edit-recurrence-frequency'));
        $select->add($this->calendar->gettext('never'), '');
        $select->add($this->calendar->gettext('daily'), 'DAILY');
        $select->add($this->calendar->gettext('weekly'), 'WEEKLY');
        $select->add($this->calendar->gettext('monthly'), 'MONTHLY');
        $select->add($this->calendar->gettext('yearly'), 'YEARLY');
        $html = html::label('edit-frequency', $this->calendar->gettext('frequency')) . $select->show('');
        break;

      // daily recurrence
      case 'daily':
        $select = $this->interval_selector(array('name' => 'interval', 'class' => 'edit-recurrence-interval', 'id' => 'edit-recurrence-interval-daily'));
        $html = html::div($attrib, html::label(null, $this->calendar->gettext('every')) . $select->show(1) . html::span('label-after', $this->calendar->gettext('days')));
        break;

      // weekly recurrence form
      case 'weekly':
        $select = $this->interval_selector(array('name' => 'interval', 'class' => 'edit-recurrence-interval', 'id' => 'edit-recurrence-interval-weekly'));
        $html = html::div($attrib, html::label(null, $this->calendar->gettext('every')) . $select->show(1) . html::span('label-after', $this->calendar->gettext('weeks')));
        // weekday selection
        $daymap = array('sun','mon','tue','wed','thu','fri','sat');
        $checkbox = new html_checkbox(array('name' => 'byday', 'class' => 'edit-recurrence-weekly-byday'));
        $first = $this->rc->config->get('calendar_first_day', 1);
        for ($weekdays = '', $j = $first; $j <= $first+6; $j++) {
            $d = $j % 7;
            $weekdays .= html::label(array('class' => 'weekday'), $checkbox->show('', array('value' => strtoupper(substr($daymap[$d], 0, 2)))) . $this->calendar->gettext($daymap[$d])) . ' ';
        }
        $html .= html::div($attrib, html::label(null, $this->calendar->gettext('bydays')) . $weekdays);
        break;

      // monthly recurrence form
      case 'monthly':
        $select = $this->interval_selector(array('name' => 'interval', 'class' => 'edit-recurrence-interval', 'id' => 'edit-recurrence-interval-monthly'));
        $html = html::div($attrib, html::label(null, $this->calendar->gettext('every')) . $select->show(1) . html::span('label-after', $this->calendar->gettext('months')));
        // day of month selection
        $checkbox = new html_checkbox(array('name' => 'bymonthday', 'class' => 'edit-recurrence-monthly-bymonthday'));
        for ($monthdays = '', $d = 1; $d <= 31; $d++) {
            $monthdays .= html::label(array('class' => 'monthday'), $checkbox->show('', array('value' => $d)) . $d);
            $monthdays .= $d % 7 ? ' ' : html::br();
        }
        
        // rule selectors
        $radio = new html_radiobutton(array('name' => 'repeatmode', 'class' => 'edit-recurrence-monthly-mode'));
        $table = new html_table(array('cols' => 2, 'border' => 0, 'cellpadding' => 0, 'class' => 'formtable'));
        $table->add('label topalign', html::label(null, $radio->show('BYMONTHDAY', array('value' => 'BYMONTHDAY')) . ' ' . $this->calendar->gettext('each')));
        $table->add(null, $monthdays);
        $table->add('label', html::label(null, $radio->show('', array('value' => 'BYDAY')) . ' ' . $this->calendar->gettext('onevery')));
        $table->add(null, $this->rrule_selectors($attrib['part']));
        
        $html .= html::div($attrib, $table->show());
        break;

      // annually recurrence form
      case 'yearly':
        $select = $this->interval_selector(array('name' => 'interval', 'class' => 'edit-recurrence-interval', 'id' => 'edit-recurrence-interval-yearly'));
        $html = html::div($attrib, html::label(null, $this->calendar->gettext('every')) . $select->show(1) . html::span('label-after', $this->calendar->gettext('years')));
        // month selector
        $monthmap = array('','jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec');
        $checkbox = new html_checkbox(array('name' => 'bymonth', 'class' => 'edit-recurrence-yearly-bymonth'));
        for ($months = '', $m = 1; $m <= 12; $m++) {
            $months .= html::label(array('class' => 'month'), $checkbox->show('', array('value' => $m)) . $this->calendar->gettext($monthmap[$m]));
            $months .= $m % 4 ? ' ' : html::br();
        }
        $html .= html::div($attrib + array('id' => 'edit-recurrence-yearly-bymonthblock'), $months);
        
        // day rule selection
        $html .= html::div($attrib, html::label(null, $this->calendar->gettext('onevery')) . $this->rrule_selectors($attrib['part'], '---'));
        break;

      // end of recurrence form
      case 'until':
        $radio = new html_radiobutton(array('name' => 'repeat', 'class' => 'edit-recurrence-until'));
        $select = $this->interval_selector(array('name' => 'times', 'id' => 'edit-recurrence-repeat-times'));
        $input = new html_inputfield(array('name' => 'untildate', 'id' => 'edit-recurrence-enddate', 'size' => "10"));

        $table = new html_table(array('cols' => 2, 'border' => 0, 'cellpadding' => 0, 'class' => 'formtable'));

        $table->add('label', $this->calendar->gettext('recurrencend'));
        $table->add(null, html::label(null, $radio->show('', array('value' => '', 'id' => 'edit-recurrence-repeat-forever')) . ' ' .
          $this->calendar->gettext('forever')));

        $table->add('label', '');
        $table->add(null, html::label(null, $radio->show('', array('value' => 'count', 'id' => 'edit-recurrence-repeat-count')) . ' ' .
          $this->calendar->gettext(array(
            'name' => 'forntimes',
            'vars' => array('nr' => $select->show(1)))
          )));

        $table->add('label', '');
        $table->add(null, $radio->show('', array('value' => 'until', 'id' => 'edit-recurrence-repeat-until')) . ' ' .
          $this->calendar->gettext('until') . ' ' . $input->show(''));
        $html = $table->show();
        break;
    }

    return $html;
  }

  /**
   * Input field for interval selection
   */
  private function interval_selector($attrib)
  {
    $select = new html_select($attrib);
    $select->add(range(1,30), range(1,30));
    return $select;
  }
  
  /**
   * Drop-down menus for recurrence rules like "each last sunday of"
   */
  private function rrule_selectors($part, $noselect = null)
  {
    // rule selectors
    $select_prefix = new html_select(array('name' => 'bydayprefix', 'id' => "edit-recurrence-$part-prefix"));
    if ($noselect) $select_prefix->add($noselect, '');
    $select_prefix->add(array(
        $this->calendar->gettext('first'),
        $this->calendar->gettext('second'),
        $this->calendar->gettext('third'),
        $this->calendar->gettext('fourth'),
        $this->calendar->gettext('last')
      ),
      array(1, 2, 3, 4, -1));
    
    $select_wday = new html_select(array('name' => 'byday', 'id' => "edit-recurrence-$part-byday"));
    if ($noselect) $select_wday->add($noselect, '');
    
    $daymap = array('sunday','monday','tuesday','wednesday','thursday','friday','saturday');
    $first = $this->rc->config->get('calendar_first_day', 1);
    for ($j = $first; $j <= $first+6; $j++) {
      $d = $j % 7;
      $select_wday->add($this->calendar->gettext($daymap[$d]), strtoupper(substr($daymap[$d], 0, 2)));
    }
    if ($part == 'monthly')
      $select_wday->add($this->calendar->gettext('dayofmonth'), '');
    
    return $select_prefix->show() . '&nbsp;' . $select_wday->show();
  }
}