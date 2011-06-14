/*
 +-------------------------------------------------------------------------+
 | Javascript for the Calendar Plugin                                      |
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

// Roundcube calendar client class
function rcube_calendar(settings)
{
    /***  member vars  ***/
    this.settings = settings;
    this.alarm_ids = [];
    this.alarm_dialog = null;
    this.snooze_popup = null;
    this.dismiss_link = null;
    this.selected_event = null;
    this.selected_calendar = null;
    this.search_request = null;
    this.search_source = null;
    this.eventcount = [];


    /***  private vars  ***/
    var me = this;
    var fcselector = '#calendar';
    var day_clicked = day_clicked_ts = 0;
    var ignore_click = false;

    // general datepicker settings
    var datepicker_settings = {
      // translate from fullcalendar format to datepicker format
      dateFormat: settings['date_format'].replace(/M/g, 'm').replace(/mmmmm/, 'MM').replace(/mmm/, 'M').replace(/dddd/, 'DD').replace(/ddd/, 'D').replace(/yy/g, 'y'),
      firstDay : settings['first_day'],
      dayNamesMin: settings['days_short'],
      monthNames: settings['months'],
      monthNamesShort: settings['months'],
      changeMonth: false,
      showOtherMonths: true,
      selectOtherMonths: true,
    };


    /***  private methods  ***/

    // quote html entities
    var Q = function(str)
    {
      return String(str).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };
    // php equivalent
    var nl2br = function(str)
    {
      return String(str).replace(/\n/g, "<br/>");
    };

    // from time and date strings to a real date object
    var parse_datetime = function(time, date) {
      // we use the utility function from datepicker to parse dates
      var date = $.datepicker.parseDate(datepicker_settings.dateFormat, date, datepicker_settings);
      var time_arr = time.split(/[:.]/);
      if (!isNaN(time_arr[0])) date.setHours(time_arr[0]);
      if (!isNaN(time_arr[1])) date.setMinutes(time_arr[1]);
      return date;
    };

    // create a nice human-readable string for the date/time range
    var event_date_text = function(event)
    {
      var fromto, duration = event.end.getTime() / 1000 - event.start.getTime() / 1000;
      if (event.allDay)
        fromto = $.fullCalendar.formatDate(event.start, settings['date_format']) + (duration > 86400 || event.start.getDay() != event.end.getDay() ? ' &mdash; ' + $.fullCalendar.formatDate(event.end, settings['date_format']) : '');
      else if (duration < 86400 && event.start.getDay() == event.end.getDay())
        fromto = $.fullCalendar.formatDate(event.start, settings['date_format']) + ' ' + $.fullCalendar.formatDate(event.start, settings['time_format']) +  ' &mdash; '
          + $.fullCalendar.formatDate(event.end, settings['time_format']);
      else
        fromto = $.fullCalendar.formatDate(event.start, settings['date_format']) + ' ' + $.fullCalendar.formatDate(event.start, settings['time_format']) +  ' &mdash; '
          + $.fullCalendar.formatDate(event.end, settings['date_format']) + ' ' + $.fullCalendar.formatDate(event.end, settings['time_format']);

      return fromto;
    };

    // event details dialog (show only)
    var event_show_dialog = function(event)
    {
      var $dialog = $("#eventshow");
      var calendar = event.calendar && me.calendars[event.calendar] ? me.calendars[event.calendar] : { editable:false };
      
      $dialog.find('div.event-section, div.event-line').hide();
      $('#event-title').html(Q(event.title)).show();
      
      if (event.location)
        $('#event-location').html('@ ' + Q(event.location)).show();
      if (event.description)
        $('#event-description').show().children('.event-text').html(nl2br(Q(event.description))); // TODO: format HTML with clickable links and stuff
      
      // render from-to in a nice human-readable way
      $('#event-date').html(Q(event_date_text(event))).show();
      
      if (event.recurrence && event.recurrence_text)
        $('#event-repeat').show().children('.event-text').html(Q(event.recurrence_text));
      
      if (event.alarms && event.alarms_text)
        $('#event-alarm').show().children('.event-text').html(Q(event.alarms_text));
      
      if (calendar.name)
        $('#event-calendar').show().children('.event-text').html(Q(calendar.name)).removeClass().addClass('event-text').addClass('cal-'+calendar.id);
      if (event.categories)
        $('#event-category').show().children('.event-text').html(Q(event.categories)).removeClass().addClass('event-text '+event.className);
      if (event.free_busy)
        $('#event-free-busy').show().children('.event-text').html(Q(rcmail.gettext(event.free_busy, 'calendar')));
      if (event.priority != 1) {
        var priolabels = { 0:rcmail.gettext('low'), 1:rcmail.gettext('normal'), 2:rcmail.gettext('high') };
        $('#event-priority').show().children('.event-text').html(Q(priolabels[event.priority]));
      }
      if (event.sensitivity != 0) {
        var sensitivitylabels = { 0:rcmail.gettext('public'), 1:rcmail.gettext('private'), 2:rcmail.gettext('confidential') };
        $('#event-sensitivity').show().children('.event-text').html(Q(sensitivitylabels[event.sensitivity]));
      }
      
      var buttons = {};
      if (calendar.editable && event.editable !== false) {
        buttons[rcmail.gettext('edit', 'calendar')] = function() {
          event_edit_dialog('edit', event);
        };
        buttons[rcmail.gettext('remove', 'calendar')] = function() {
          me.delete_event(event);
          $dialog.dialog('close');
        };
      }
      else {
        buttons[rcmail.gettext('close', 'calendar')] = function(){
          $dialog.dialog('close');
        };
      }
      
      // open jquery UI dialog
      $dialog.dialog({
        modal: false,
        resizable: true,
        title: null,
        close: function() {
          $dialog.dialog('destroy').hide();
        },
        buttons: buttons,
        minWidth: 320,
        width: 420
      }).show();
/* 
      // add link for "more options" drop-down
      $('<a>')
        .attr('href', '#')
        .html('More Options')
        .addClass('dropdown-link')
        .click(function(){ return false; })
        .insertBefore($dialog.parent().find('.ui-dialog-buttonset').children().first());
*/
    };

    // bring up the event dialog (jquery-ui popup)
    var event_edit_dialog = function(action, event)
    {
      // close show dialog first
      $("#eventshow").dialog('close');
      
      var $dialog = $("#eventedit");
      var calendar = event.calendar && me.calendars[event.calendar] ? me.calendars[event.calendar] : { editable:action=='new' };
      me.selected_event = event;

      // reset dialog first, enable/disable fields according to editable state
      $('#eventtabs').get(0).reset();
      $('#calendar-select')[(action == 'new' ? 'show' : 'hide')]();

      // event details
      var title = $('#edit-title').val(event.title);
      var location = $('#edit-location').val(event.location);
      var description = $('#edit-description').val(event.description);
      var categories = $('#edit-categories').val(event.categories);
      var calendars = $('#edit-calendar').val(event.calendar);
      var freebusy = $('#edit-free-busy').val(event.free_busy);
      var priority = $('#edit-priority').val(event.priority);
      var sensitivity = $('#edit-sensitivity').val(event.sensitivity);
      
      var duration = Math.round((event.end.getTime() - event.start.getTime()) / 1000);
      var startdate = $('#edit-startdate').val($.fullCalendar.formatDate(event.start, settings['date_format'])).data('duration', duration);
      var starttime = $('#edit-starttime').val($.fullCalendar.formatDate(event.start, settings['time_format'])).show();
      var enddate = $('#edit-enddate').val($.fullCalendar.formatDate(event.end, settings['date_format']));
      var endtime = $('#edit-endtime').val($.fullCalendar.formatDate(event.end, settings['time_format'])).show();
      var allday = $('#edit-allday').get(0);
      
      if (event.allDay) {
        starttime.val("00:00").hide();
        endtime.val("23:59").hide();
        allday.checked = true;
      }
      else {
        allday.checked = false;
      }
      
      // set alarm(s)
      // TODO: support multiple alarm entries
      if (event.alarms) {
        if (typeof event.alarms == 'string')
          event.alarms = event.alarms.split(';');
        
        for (var alarm, i=0; i < event.alarms.length; i++) {
          alarm = String(event.alarms[i]).split(':');
          if (!alarm[1] && alarm[0]) alarm[1] = 'DISPLAY';
          $('select.edit-alarm-type').val(alarm[1]);
          
          if (alarm[0].match(/@(\d+)/)) {
            var ondate = new Date(parseInt(RegExp.$1) * 1000);
            $('select.edit-alarm-offset').val('@');
            $('input.edit-alarm-date').val($.fullCalendar.formatDate(ondate, settings['date_format']));
            $('input.edit-alarm-time').val($.fullCalendar.formatDate(ondate, settings['time_format']));
          }
          else if (alarm[0].match(/([-+])(\d+)([MHD])/)) {
            $('input.edit-alarm-value').val(RegExp.$2);
            $('select.edit-alarm-offset').val(''+RegExp.$1+RegExp.$3);
          }
        }
      }
      // set correct visibility by triggering onchange handlers
      $('select.edit-alarm-type, select.edit-alarm-offset').change();
      
      // enable/disable alarm property according to backend support
      $('#edit-alarms')[(calendar.alarms ? 'show' : 'hide')]();
      
      // set recurrence form
      var recurrence = $('#edit-recurrence-frequency').val(event.recurrence ? event.recurrence.FREQ : '').change();
      var interval = $('select.edit-recurrence-interval').val(event.recurrence ? event.recurrence.INTERVAL : 1);
      var rrtimes = $('#edit-recurrence-repeat-times').val(event.recurrence ? event.recurrence.COUNT : 1);
      var rrenddate = $('#edit-recurrence-enddate').val(event.recurrence && event.recurrence.UNTIL ? $.fullCalendar.formatDate(new Date(event.recurrence.UNTIL*1000), settings['date_format']) : '');
      $('input.edit-recurrence-until:checked').prop('checked', false);
      
      var weekdays = ['SU','MO','TU','WE','TH','FR','SA'];
      var rrepeat_id = '#edit-recurrence-repeat-forever';
      if (event.recurrence && event.recurrence.COUNT)      rrepeat_id = '#edit-recurrence-repeat-count';
      else if (event.recurrence && event.recurrence.UNTIL) rrepeat_id = '#edit-recurrence-repeat-until';
      $(rrepeat_id).prop('checked', true);
      
      if (event.recurrence && event.recurrence.BYDAY && event.recurrence.FREQ == 'WEEKLY') {
        var wdays = event.recurrence.BYDAY.split(',');
        $('input.edit-recurrence-weekly-byday').val(wdays);
      }
      if (event.recurrence && event.recurrence.BYMONTHDAY) {
        $('input.edit-recurrence-monthly-bymonthday').val(String(event.recurrence.BYMONTHDAY).split(','));
        $('input.edit-recurrence-monthly-mode').val(['BYMONTHDAY']);
      }
      if (event.recurrence && event.recurrence.BYDAY && (event.recurrence.FREQ == 'MONTHLY' || event.recurrence.FREQ == 'YEARLY')) {
        var byday, section = event.recurrence.FREQ.toLowerCase();
        if ((byday = String(event.recurrence.BYDAY).match(/(-?[1-4])([A-Z]+)/))) {
          $('#edit-recurrence-'+section+'-prefix').val(byday[1]);
          $('#edit-recurrence-'+section+'-byday').val(byday[2]);
        }
        $('input.edit-recurrence-'+section+'-mode').val(['BYDAY']);
      }
      else if (event.start) {
        $('#edit-recurrence-monthly-byday').val(weekdays[event.start.getDay()]);
      }
      if (event.recurrence && event.recurrence.BYMONTH) {
        $('input.edit-recurrence-yearly-bymonth').val(String(event.recurrence.BYMONTH).split(','));
      }
      else if (event.start) {
        $('input.edit-recurrence-yearly-bymonth').val([String(event.start.getMonth()+1)]);
      }
      
      // show warning if editing a recurring event
      if (event.id && event.recurrence) {
        $('#edit-recurring-warning').show();
        $('input.edit-recurring-savemode[value="all"]').prop('checked', true);
      }
      else
        $('#edit-recurring-warning').hide();
      
      // buttons
      var buttons = {};
      
      buttons[rcmail.gettext('save', 'calendar')] = function() {
        var start = parse_datetime(starttime.val(), startdate.val());
        var end = parse_datetime(endtime.val(), enddate.val());
        
        // basic input validatetion
        if (start.getTime() > end.getTime()) {
          alert(rcmail.gettext('invalideventdates', 'calendar'));
          return false;
        }
        
        // post data to server
        var data = {
          calendar: event.calendar,
          start: start.getTime()/1000,
          end: end.getTime()/1000,
          allday: allday.checked?1:0,
          title: title.val(),
          description: description.val(),
          location: location.val(),
          categories: categories.val(),
          free_busy: freebusy.val(),
          priority: priority.val(),
          sensitivity: sensitivity.val(),
          recurrence: '',
          alarms: ''
        };
        
        // serialize alarm settings
        // TODO: support multiple alarm entries
        var alarm = $('select.edit-alarm-type').val();
        if (alarm) {
          var val, offset = $('select.edit-alarm-offset').val();
          if (offset == '@')
            data.alarms = '@' + (parse_datetime($('input.edit-alarm-time').val(), $('input.edit-alarm-date').val()).getTime()/1000) + ':' + alarm;
          else if ((val = parseInt($('input.edit-alarm-value').val())) && !isNaN(val) && val >= 0)
            data.alarms = offset[0] + val + offset[1] + ':' + alarm;
        }
        
        // gather recurrence settings
        var freq;
        if ((freq = recurrence.val()) != '') {
          data.recurrence = {
            FREQ: freq,
            INTERVAL: $('#edit-recurrence-interval-'+freq.toLowerCase()).val()
          };
          
          var until = $('input.edit-recurrence-until:checked').val();
          if (until == 'count')
            data.recurrence.COUNT = rrtimes.val();
          else if (until == 'until')
            data.recurrence.UNTIL = parse_datetime(endtime.val(), rrenddate.val()).getTime()/1000;
          
          if (freq == 'WEEKLY') {
            var byday = [];
            $('input.edit-recurrence-weekly-byday:checked').each(function(){ byday.push(this.value); });
            if (byday.length)
              data.recurrence.BYDAY = byday.join(',');
          }
          else if (freq == 'MONTHLY') {
            var mode = $('input.edit-recurrence-monthly-mode:checked').val(), bymonday = [];
            if (mode == 'BYMONTHDAY') {
              $('input.edit-recurrence-monthly-bymonthday:checked').each(function(){ bymonday.push(this.value); });
              if (bymonday.length)
                data.recurrence.BYMONTHDAY = bymonday.join(',');
            }
            else
              data.recurrence.BYDAY = $('#edit-recurrence-monthly-prefix').val() + $('#edit-recurrence-monthly-byday').val();
          }
          else if (freq == 'YEARLY') {
            var byday, bymonth = [];
            $('input.edit-recurrence-yearly-bymonth:checked').each(function(){ bymonth.push(this.value); });
            if (bymonth.length)
              data.recurrence.BYMONTH = bymonth.join(',');
            if ((byday = $('#edit-recurrence-yearly-byday').val()))
              data.recurrence.BYDAY = $('#edit-recurrence-yearly-prefix').val() + byday;
          }
        }
        
        if (event.id) {
          data.id = event.id;
          if (event.recurrence)
            data.savemode = $('input.edit-recurring-savemode:checked').val();
        }
        else
          data.calendar = calendars.val();

        rcmail.http_post('event', { action:action, e:data });
        $dialog.dialog("close");
      };

      if (event.id) {
        buttons[rcmail.gettext('remove', 'calendar')] = function() {
          me.delete_event(event);
          $dialog.dialog('close');
        };
      }

      buttons[rcmail.gettext('cancel', 'calendar')] = function() {
        $dialog.dialog("close");
      };

      // show/hide tabs according to calendar's feature support
      $('#edit-tab-attendees')[(calendar.attendees?'show':'hide')]();
      $('#edit-tab-attachments')[(calendar.attachments?'show':'hide')]();

      // activate the first tab
      $('#eventtabs').tabs('select', 0);

      // open jquery UI dialog
      $dialog.dialog({
        modal: true,
        resizable: true,
        title: rcmail.gettext((action == 'edit' ? 'edit_event' : 'new_event'), 'calendar'),
        close: function() {
          $dialog.dialog("destroy").hide();
        },
        buttons: buttons,
        minWidth: 440,
        width: 480
      }).show();

      title.select();
    };

    // mouse-click handler to check if the show dialog is still open and prevent default action
    var dialog_check = function(e)
    {
      var showd = $("#eventshow");
      if (showd.is(':visible') && !$(e.target).closest('.ui-dialog').length) {
        showd.dialog('close');
        e.stopImmediatePropagation();
        ignore_click = true;
        return false;
      }
      else if (ignore_click) {
        window.setTimeout(function(){ ignore_click = false; }, 20);
        return false;
      }
      return true;
    };

    // display confirm dialog when modifying/deleting a recurring event where the user needs to select the savemode
    var recurring_edit_confirm = function(event, action) {
      var $dialog = $('<div>').addClass('edit-recurring-warning');
      $dialog.html('<div class="message"><span class="ui-icon ui-icon-alert"></span>' +
        rcmail.gettext((action == 'remove' ? 'removerecurringeventwarning' : 'changerecurringeventwarning'), 'calendar') + '</div>' +
        '<div class="savemode">' +
          '<a href="#current" class="button">' + rcmail.gettext('currentevent', 'calendar') + '</a>' +
          '<a href="#future" class="button">' + rcmail.gettext('futurevents', 'calendar') + '</a>' +
          '<a href="#all" class="button">' + rcmail.gettext('allevents', 'calendar') + '</a>' +
          (action != 'remove' ? '<a href="#new" class="button">' + rcmail.gettext('saveasnew', 'calendar') + '</a>' : '') +
        '</div>');
      
      $dialog.find('a.button').button().click(function(e){
        event.savemode = String(this.href).replace(/.+#/, '');
        rcmail.http_post('event', { action:action, e:event });
        $dialog.dialog("destroy").hide();
        return false;
      });
      
      $dialog.dialog({
        modal: true,
        width: 420,
        dialogClass: 'warning',
        title: rcmail.gettext((action == 'remove' ? 'removerecurringevent' : 'changerecurringevent'), 'calendar'),
        buttons: [
          {
            text: rcmail.gettext('cancel', 'calendar'),
            click: function() {
              $(this).dialog("close");
            }
          }
        ],
        close: function(){
          $dialog.dialog("destroy").hide();
          $(fcselector).fullCalendar('refetchEvents');
        }
      }).show();
      
      return true;
    };


    /*** public methods ***/

    // public method to bring up the new event dialog
    this.add_event = function() {
      if (this.selected_calendar) {
        var now = new Date();
        var date = $(fcselector).fullCalendar('getDate') || now;
        date.setHours(now.getHours()+1);
        date.setMinutes(0);
        var end = new Date(date.getTime());
        end.setHours(date.getHours()+1);
        event_edit_dialog('new', { start:date, end:end, allDay:false, calendar:this.selected_calendar });
      }
    };

    // delete the given event after showing a confirmation dialog
    this.delete_event = function(event) {
      // show extended confirm dialog for recurring events, use jquery UI dialog
      if (event.recurrence)
        return recurring_edit_confirm({ id:event.id, calendar:event.calendar }, 'remove');
      
      // send remove request to plugin
      if (confirm(rcmail.gettext('deleteventconfirm', 'calendar'))) {
        rcmail.http_post('event', { action:'remove', e:{ id:event.id, calendar:event.calendar } });
        return true;
      }

      return false;
    };

    // display a notification for the given pending alarms
    this.display_alarms = function(alarms) {
      // clear old alert first
      if (this.alarm_dialog)
        this.alarm_dialog.dialog('destroy');
      
      this.alarm_dialog = $('<div>').attr('id', 'alarm-display');
      
      var actions, adismiss, asnooze, alarm, html, event_ids = [];
      for (var actions, html, alarm, i=0; i < alarms.length; i++) {
        alarm = alarms[i];
        alarm.start = new Date(alarm.start * 1000);
        alarm.end = new Date(alarm.end * 1000);
        event_ids.push(alarm.id);
        
        html = '<h3 class="event-title">' + Q(alarm.title) + '</h3>';
        html += '<div class="event-section">' + Q(alarm.location) + '</div>';
        html += '<div class="event-section">' + Q(event_date_text(alarm)) + '</div>';
        
        adismiss = $('<a href="#" class="alarm-action-dismiss"></a>').html(rcmail.gettext('dismiss','calendar')).click(function(){
          me.dismiss_link = $(this);
          me.dismiss_alarm(me.dismiss_link.data('id'), 0);
        });
        asnooze = $('<a href="#" class="alarm-action-snooze"></a>').html(rcmail.gettext('snooze','calendar')).click(function(){
          me.snooze_dropdown($(this));
        });
        actions = $('<div>').addClass('alarm-actions').append(adismiss.data('id', alarm.id)).append(asnooze.data('id', alarm.id));
        
        $('<div>').addClass('alarm-item').html(html).append(actions).appendTo(this.alarm_dialog);
      }
      
      var buttons = {};
      buttons[rcmail.gettext('dismissall','calendar')] = function() {
        // submit dismissed event_ids to server
        me.dismiss_alarm(me.alarm_ids.join(','), 0);
        $(this).dialog('close');
      };
      
      this.alarm_dialog.appendTo(document.body).dialog({
        modal: false,
        resizable: true,
        closeOnEscape: false,
        dialogClass: 'alarm',
        title: '<span class="ui-icon ui-icon-alert" style="float:left; margin:0 4px 0 0"></span>' + rcmail.gettext('alarmtitle', 'calendar'),
        buttons: buttons,
        close: function() {
          $('#alarm-snooze-dropdown').hide();
          $(this).dialog('destroy').remove();
          me.alarm_dialog = null;
          me.alarm_ids = null;
        },
        drag: function(event, ui) {
          $('#alarm-snooze-dropdown').hide();
        }
      });
      this.alarm_ids = event_ids;
    };

    // show a drop-down menu with a selection of snooze times
    this.snooze_dropdown = function(link)
    {
      if (!this.snooze_popup) {
        this.snooze_popup = $('#alarm-snooze-dropdown');
        $('#alarm-snooze-dropdown a').click(function(e){
          var time = String(this.href).replace(/.+#/, '');
          me.dismiss_alarm($('#alarm-snooze-dropdown').data('id'), time);
          return false;
        });
      }
      
      // hide visible popup
      if (this.snooze_popup.is(':visible') && this.snooze_popup.data('id') == link.data('id')) {
        this.snooze_popup.hide();
        this.dismiss_link = null;
      }
      else {  // open popup below the clicked link
        var pos = link.offset();
        pos.top += link.height() + 2;
        this.snooze_popup.data('id', link.data('id')).css({ top:Math.floor(pos.top)+'px', left:Math.floor(pos.left)+'px' }).show();
        this.dismiss_link = link;
      }
    };

    // dismiss or snooze alarms for the given event
    this.dismiss_alarm = function(id, snooze)
    {
      $('#alarm-snooze-dropdown').hide();
      rcmail.http_post('event', { action:'dismiss', e:{ id:id, snooze:snooze } });
      
      // remove dismissed alarm from list
      if (this.dismiss_link) {
        this.dismiss_link.closest('div.alarm-item').hide();
        var new_ids = jQuery.grep(this.alarm_ids, function(v){ return v != id; });
        if (new_ids.length)
          this.alarm_ids = new_ids;
        else
          this.alarm_dialog.dialog('close');
      }
      
      this.dismiss_link = null;
    };

    // opens a jquery UI dialog with event properties (or empty for creating a new calendar)
    this.calendar_edit_dialog = function(calendar)
    {
      // close show dialog first
      var $dialog = $("#calendarform").dialog('close');
      
      if (!calendar)
        calendar = { name:'', color:'cc0000' };

      // reset form first
      var form = $('#calendarform > form');
      form.get(0).reset();
      
      var name = $('#calendar-name').val(calendar.editname || calendar.name);
      var color = $('#calendar-color').val(calendar.color).miniColors('value', calendar.color);
      
      // dialog buttons
      var buttons = {};
      
      buttons[rcmail.gettext('save', 'calendar')] = function() {
        // TODO: do some input validation
        if (!name.val() || name.val().length < 2) {
          alert(rcmail.gettext('invalidcalendarproperties', 'calendar'));
          name.select();
          return;
        }
        
        // post data to server
        var data = {
          name: name.val(),
          color: color.val().replace(/^#/, '')
        };
        if (calendar.id)
          data.id = calendar.id;
        
        rcmail.http_post('calendar', { action:(calendar.id ? 'edit' : 'new'), c:data });
        $dialog.dialog("close");
      };

      buttons[rcmail.gettext('cancel', 'calendar')] = function() {
        $dialog.dialog("close");
      };

      // open jquery UI dialog
      $dialog.dialog({
        modal: true,
        resizable: true,
        title: rcmail.gettext((calendar.id ? 'editcalendar' : 'createcalendar'), 'calendar'),
        close: function() {
          $dialog.dialog("destroy").hide();
        },
        buttons: buttons,
        minWidth: 400,
        width: 420
      }).show();

      name.select();
    };
    
    this.calendar_remove = function(calendar)
    {
      if (confirm(rcmail.gettext('deletecalendarconfirm', 'calendar'))) {
        rcmail.http_post('calendar', { action:'remove', c:{ id:calendar.id } });
        return true;
      }
      return false;
    };
    
    this.calendar_destroy_source = function(id)
    {
      if (this.calendars[id]) {
        $(fcselector).fullCalendar('removeEventSource', this.calendars[id]);
        $(rcmail.get_folder_li(id, 'rcmlical')).remove();
        $('#edit-calendar option[value="'+id+'"]').remove();
        delete this.calendars[id];
      }
    };


    /***  event searching  ***/

    // execute search
    this.quicksearch = function()
    {
      if (rcmail.gui_objects.qsearchbox) {
        var q = rcmail.gui_objects.qsearchbox.value;
        if (q != '') {
          var id = 'search-'+q;
          var fc = $(fcselector);
          var sources = [];
          
          for (var sid in this.calendars) {
            if (this.calendars[sid] && this.calendars[sid].active) {
              fc.fullCalendar('removeEventSource', this.calendars[sid]);
              sources.push(sid);
            }
          }
          id += '@'+sources.join(',');
          
          // just refetch events if query didn't change
          if (this.search_request == id) {
            fc.fullCalendar('refetchEvents');
            return;
          }
          // remove old search results
          else if (this.search_source) {
            fc.fullCalendar('removeEventSource', this.search_source);
          }
          else {
            this.default_view = fc.fullCalendar('getView').name;
          }
          
          // replace event source from fullcalendar
          this.search_request = id;
          this.search_source = {
            url: "./?_task=calendar&_action=search_events&q="+escape(q)+'&source='+escape(sources.join(',')),
            editable: false
          };
          
          fc.fullCalendar('option', 'smartSections', false);
          fc.fullCalendar('addEventSource', this.search_source);
          fc.fullCalendar('changeView', 'table');
        }
        else  // empty search input equals reset
          this.reset_quicksearch();
      }
    };
    
    // reset search and get back to normal event listing
    this.reset_quicksearch = function()
    {
      $(rcmail.gui_objects.qsearchbox).val('');
      if (this.search_request) {
        // restore original event sources and view mode from fullcalendar
        var fc = $(fcselector);
        fc.fullCalendar('option', 'smartSections', true);
        fc.fullCalendar('removeEventSource', this.search_source);
        for (var sid in this.calendars) {
          if (this.calendars[sid] && this.calendars[sid].active)
            fc.fullCalendar('addEventSource', this.calendars[sid]);
        }
        if (this.default_view)
          fc.fullCalendar('changeView', this.default_view);
        
        this.search_request = this.search_source = null;
      }
    };


    /***  startup code  ***/

    // create list of event sources AKA calendars
    this.calendars = {};
    var li, cal, active, event_sources = [];
    for (var id in rcmail.env.calendars) {
      cal = rcmail.env.calendars[id];
      this.calendars[id] = $.extend({
        url: "./?_task=calendar&_action=load_events&source="+escape(id),
        editable: !cal.readonly,
        className: 'fc-event-cal-'+id,
        id: id
      }, cal);
      
      if ((active = ($.inArray(String(id), settings.hidden_calendars) < 0))) {
        this.calendars[id].active = true;
        event_sources.push(this.calendars[id]);
      }
      
      // init event handler on calendar list checkbox
      if ((li = rcmail.get_folder_li(id, 'rcmlical'))) {
        $('#'+li.id+' input').click(function(e){
          var id = $(this).data('id');
          if (me.calendars[id]) {  // add or remove event source on click
            var action;
            if (this.checked) {
              action = 'addEventSource';
              me.calendars[id].active = true;
              settings.hidden_calendars = $.map(settings.hidden_calendars, function(v){ return v == id ? null : v; });
            }
            else {
              action = 'removeEventSource';
              me.calendars[id].active = false;
              settings.hidden_calendars.push(id);
            }
            // just trigger search again (don't save prefs?)
            if (me.search_request) {
              me.quicksearch();
            }
            else {  // add/remove event source
              $(fcselector).fullCalendar(action, me.calendars[id]);
              rcmail.save_pref({ name:'hidden_calendars', value:settings.hidden_calendars.join(',') });
            }
          }
        }).data('id', id).get(0).checked = active;
        
        $(li).click(function(e){
          var id = $(this).data('id');
          rcmail.select_folder(id, me.selected_calendar, 'rcmlical');
          rcmail.enable_command('calendar-edit','calendar-remove', !me.calendars[id].readonly);
          me.selected_calendar = id;
        }).data('id', id);
      }
      
      if (!cal.readonly && !this.selected_calendar && (!settings.default_calendar || settings.default_calendar == id)) {
        this.selected_calendar = id;
        rcmail.enable_command('addevent', true);
      }
    }

    // initalize the fullCalendar plugin
    $(fcselector).fullCalendar({
      header: {
        left: 'prev,next today',
        center: 'title',
        right: 'agendaDay,agendaWeek,month,table'
      },
      aspectRatio: 1,
      ignoreTimezone: false,  // will translate event dates to the client's timezone
      height: $(window).height() - 96,
      eventSources: event_sources,
      monthNames : settings['months'],
      monthNamesShort : settings['months_short'],
      dayNames : settings['days'],
      dayNamesShort : settings['days_short'],
      firstDay : settings['first_day'],
      firstHour : settings['first_hour'],
      slotMinutes : 60/settings['timeslots'],
      timeFormat: {
        '': settings['time_format'],
        agenda: settings['time_format'] + '{ - ' + settings['time_format'] + '}',
        list: settings['time_format'] + '{ - ' + settings['time_format'] + '}',
        table: settings['time_format'] + '{ - ' + settings['time_format'] + '}'
      },
      axisFormat : settings['time_format'],
      columnFormat: {
        month: 'ddd', // Mon
        week: 'ddd ' + settings['date_short'], // Mon 9/7
        day: 'dddd ' + settings['date_short'],  // Monday 9/7
        list: settings['date_agenda'],
        table: settings['date_agenda']
      },
      titleFormat: {
        month: 'MMMM yyyy',
        week: settings['date_long'].replace(/ yyyy/, '[ yyyy]') + "{ '&mdash;' " + settings['date_long'] + "}",
        day: 'dddd ' + settings['date_long'],
        list: settings['date_long'],
        table: settings['date_long']
      },
      smartSections: true,
      defaultView: settings['default_view'],
      allDayText: rcmail.gettext('all-day', 'calendar'),
      buttonText: {
        today: settings['today'],
        day: rcmail.gettext('day', 'calendar'),
        week: rcmail.gettext('week', 'calendar'),
        month: rcmail.gettext('month', 'calendar'),
        table: rcmail.gettext('agenda', 'calendar')
      },
      selectable: true,
      selectHelper: true,
      loading : function(isLoading) {
        this._rc_loading = rcmail.set_busy(isLoading, 'loading', this._rc_loading);
      },
      // event rendering
      eventRender: function(event, element, view) {
        if (view.name != 'list' && view.name != 'table')
          element.attr('title', event.title);
        if (view.name == 'month') {
/* attempt to limit the number of events displayed
   (could also be used to init fish-eye-view)
          var max = 4;  // to be derrived from window size
          var sday = event.start.getMonth()*12 + event.start.getDate();
          var eday = event.end.getMonth()*12   + event.end.getDate();
          if (!me.eventcount[sday]) me.eventcount[sday] = 1;
          else                      me.eventcount[sday]++;
          if (!me.eventcount[eday]) me.eventcount[eday] = 1;
          else if (eday != sday)    me.eventcount[eday]++;
          
          if (me.eventcount[sday] > max || me.eventcount[eday] > max)
            return false;
*/
        }
        else {
          if (event.location) {
            element.find('div.fc-event-title').after('<div class="fc-event-location">@&nbsp;' + Q(event.location) + '</div>');
          }
          if (event.recurrence)
            element.find('div.fc-event-time').append('<i class="fc-icon-recurring"></i>');
          if (event.alarms)
            element.find('div.fc-event-time').append('<i class="fc-icon-alarms"></i>');
        }
      },
      // callback for date range selection
      select: function(start, end, allDay, e, view) {
        var range_select = (!allDay || start.getDate() != end.getDate())
        if (dialog_check(e) && range_select)
          event_edit_dialog('new', { start:start, end:end, allDay:allDay, calendar:me.selected_calendar });
        if (range_select || ignore_click)
          view.calendar.unselect();
      },
      // callback for clicks in all-day box
      dayClick: function(date, allDay, e, view) {
        var now = new Date().getTime();
        if (now - day_clicked_ts < 400 && day_clicked == date.getTime())  // emulate double-click on day
          return event_edit_dialog('new', { start:date, end:date, allDay:allDay, calendar:me.selected_calendar });
        
        if (!ignore_click) {
          view.calendar.gotoDate(date);
          fullcalendar_update();
          if (day_clicked && new Date(day_clicked).getMonth() != date.getMonth())
            view.calendar.select(date, date, allDay);
        }
        day_clicked = date.getTime();
        day_clicked_ts = now;
      },
      // callback when a specific event is clicked
      eventClick: function(event) {
        event_show_dialog(event);
      },
      // callback when an event was dragged and finally dropped
      eventDrop: function(event, dayDelta, minuteDelta, allDay, revertFunc) {
        if (event.end == null) {
          event.end = event.start;
        }
        // send move request to server
        var data = {
          id: event.id,
          calendar: event.calendar,
          start: event.start.getTime()/1000,
          end: event.end.getTime()/1000,
          allday: allDay?1:0
        };
        if (event.recurrence)
          recurring_edit_confirm(data, 'move');
        else
          rcmail.http_post('event', { action:'move', e:data });
      },
      // callback for event resizing
      eventResize: function(event, delta) {
        // send resize request to server
        var data = {
          id: event.id,
          calendar: event.calendar,
          start: event.start.getTime()/1000,
          end: event.end.getTime()/1000
        };
        if (event.recurrence)
          recurring_edit_confirm(data, 'resize');
        else
          rcmail.http_post('event', { action:'resize', e:data });
      },
      viewDisplay: function(view) {
        me.eventcount = [];
        window.setTimeout(function(){ $('div.fc-content').css('overflow', view.name == 'month' ? 'auto' : 'hidden') }, 10);
      },
      windowResize: function(view) {
        me.eventcount = [];
      }
    });


    // event handler for clicks on calendar week cell of the datepicker widget
    var init_week_events = function(){
      $('#datepicker table.ui-datepicker-calendar td.ui-datepicker-week-col').click(function(e){
        var base_date = $("#datepicker").datepicker('getDate');
        var day_off = base_date.getDay() - 1;
        if (day_off < 0) day_off = 6;
        var base_kw = $.datepicker.iso8601Week(base_date);
        var kw = parseInt($(this).html());
        var diff = (kw - base_kw) * 7 * 86400000;
        // select monday of the chosen calendar week
        var date = new Date(base_date.getTime() - day_off * 86400000 + diff);
        $(fcselector).fullCalendar('gotoDate', date).fullCalendar('setDate', date).fullCalendar('changeView', 'agendaWeek');
        $("#datepicker").datepicker('setDate', date);
        window.setTimeout(init_week_events, 10);
      }).css('cursor', 'pointer');
    };

    // initialize small calendar widget using jQuery UI datepicker
    $('#datepicker').datepicker($.extend(datepicker_settings, {
      inline: true,
      showWeek: true,
      changeMonth: false, // maybe enable?
      changeYear: false,  // maybe enable?
      onSelect: function(dateText, inst) {
        ignore_click = true;
        var d = $("#datepicker").datepicker('getDate'); //parse_datetime('0:0', dateText);
        $(fcselector).fullCalendar('gotoDate', d).fullCalendar('select', d, d, true);
        window.setTimeout(init_week_events, 10);
      },
      onChangeMonthYear: function(year, month, inst) {
        window.setTimeout(init_week_events, 10);
        var d = $("#datepicker").datepicker('getDate');
        d.setYear(year);
        d.setMonth(month - 1);
        $("#datepicker").data('year', year).data('month', month);
        //$(fcselector).fullCalendar('gotoDate', d).fullCalendar('setDate', d);
      },
    }));
    window.setTimeout(init_week_events, 10);

    // react on fullcalendar buttons
    var fullcalendar_update = function() {
      var d = $(fcselector).fullCalendar('getDate');
      $("#datepicker").datepicker('setDate', d);
      window.setTimeout(init_week_events, 10);
    };
    $("#calendar .fc-button-prev").click(fullcalendar_update);
    $("#calendar .fc-button-next").click(fullcalendar_update);
    $("#calendar .fc-button-today").click(fullcalendar_update);

    // format time string
    var formattime = function(hour, minutes) {
        return ((hour < 10) ? "0" : "") + hour + ((minutes < 10) ? ":0" : ":") + minutes;
    };

    // if start date is changed, shift end date according to initial duration
    var shift_enddate = function(dateText) {
      var newstart = parse_datetime('0', dateText);
      var newend = new Date(newstart.getTime() + $('#edit-startdate').data('duration') * 1000);
      $('#edit-enddate').val($.fullCalendar.formatDate(newend, me.settings['date_format']));
    };

    // init event dialog
    $('#eventtabs').tabs();
    $('#edit-enddate, input.edit-alarm-date').datepicker(datepicker_settings);
    $('#edit-startdate').datepicker(datepicker_settings).datepicker('option', 'onSelect', shift_enddate).change(function(){ shift_enddate(this.value); });
    $('#edit-allday').click(function(){ $('#edit-starttime, #edit-endtime')[(this.checked?'hide':'show')](); });

    // configure drop-down menu on time input fields based on jquery UI autocomplete
    $('#edit-starttime, #edit-endtime, input.edit-alarm-time')
      .attr('autocomplete', "off")
      .autocomplete({
        delay: 100,
        minLength: 1,
        source: function(p, callback) {
          /* Time completions */
          var result = [];
          var now = new Date();
          var full = p.term - 1 > 0 || p.term.length > 1;
          var hours = full? p.term - 0 : now.getHours();
          var step = 15;
          var minutes = hours * 60 + (full ? 0 : now.getMinutes());
          var min = Math.ceil(minutes / step) * step % 60;
          var hour = Math.floor(Math.ceil(minutes / step) * step / 60);
          // list hours from 0:00 till now
          for (var h = 0; h < hours; h++)
            result.push(formattime(h, 0));
            // list 15min steps for the next two hours
          for (; h < hour + 2; h++) {
            while (min < 60) {
              result.push(formattime(h, min));
              min += step;
            }
            min = 0;
          }
          // list the remaining hours till 23:00
          while (h < 24)
            result.push(formattime((h++), 0));
          return callback(result);
        },
        open: function(event, ui) {
          // scroll to current time
          var widget = $(this).autocomplete('widget');
          var menu = $(this).data('autocomplete').menu;
          var val = $(this).val();
          var li, html, offset = 0;
          widget.children().each(function(){
            li = $(this);
            html = li.children().first().html();
            if (html < val)
              offset += li.height();
            if (html == val)
              menu.activate($.Event({ type: 'mouseenter' }), li);
          });
          widget.scrollTop(offset - 1);
        }
      })
      .click(function() {  // show drop-down upon clicks
        $(this).autocomplete('search', $(this).val() ? $(this).val().replace(/\D.*/, "") : " ");
      });

    // register events on alarm fields
    $('select.edit-alarm-type').change(function(){
      $(this).parent().find('span.edit-alarm-values')[(this.selectedIndex>0?'show':'hide')]();
    });
    $('select.edit-alarm-offset').change(function(){
      var mode = $(this).val() == '@' ? 'show' : 'hide';
      $(this).parent().find('.edit-alarm-date, .edit-alarm-time')[mode]();
      $(this).parent().find('.edit-alarm-value').prop('disabled', mode == 'show');
    });

    // toggle recurrence frequency forms
    $('#edit-recurrence-frequency').change(function(e){
      var freq = $(this).val().toLowerCase();
      $('.recurrence-form').hide();
      if (freq)
        $('#recurrence-form-'+freq+', #recurrence-form-until').show();
    });
    $('#edit-recurrence-enddate').datepicker(datepicker_settings).click(function(){ $("#edit-recurrence-repeat-until").prop('checked', true) });
    
    $('#calendar-color').miniColors();

    // hide event dialog when clicking somewhere into document
    $(document).bind('mousedown', dialog_check);

} // end rcube_calendar class


/* calendar plugin initialization */
window.rcmail && rcmail.addEventListener('init', function(evt) {

  // configure toobar buttons
  rcmail.register_command('addevent', function(){ cal.add_event(); }, true);
  
  // configure list operations
  rcmail.register_command('calendar-create', function(){ cal.calendar_edit_dialog(null); }, true);
  rcmail.register_command('calendar-edit', function(){ cal.calendar_edit_dialog(cal.calendars[cal.selected_calendar]); }, false);
  rcmail.register_command('calendar-remove', function(){ cal.calendar_remove(cal.calendars[cal.selected_calendar]); }, false);

  // search and export events
  rcmail.register_command('export', function(){ rcmail.goto_url('export_events', { source:cal.selected_calendar }); }, true);
  rcmail.register_command('search', function(){ cal.quicksearch(); }, true);
  rcmail.register_command('reset-search', function(){ cal.reset_quicksearch(); }, true);

  // register callback commands
  rcmail.addEventListener('plugin.display_alarms', function(alarms){ cal.display_alarms(alarms); });
  rcmail.addEventListener('plugin.reload_calendar', function(p){ $('#calendar').fullCalendar('refetchEvents', cal.calendars[p.source]); });
  rcmail.addEventListener('plugin.destroy_source', function(p){ cal.calendar_destroy_source(p.id); });


  // let's go
  var cal = new rcube_calendar(rcmail.env.calendar_settings);

  $(window).resize(function() {
    $('#calendar').fullCalendar('option', 'height', $(window).height() - 96);
  }).resize();

  // show toolbar
  $('#toolbar').show();

});
