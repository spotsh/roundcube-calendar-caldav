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

/* calendar initialization */
window.rcmail && rcmail.addEventListener('init', function(evt) {
  
  // quote html entities
  function Q(str)
  {
    return String(str).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  // php equivalent
  function nl2br(str)
  {
    return String(str).replace(/\n/g, "<br/>");
  }

  // Roundcube calendar client class
  function rcube_calendar(settings)
  {
    this.settings = settings;
    var me = this;
    
    // private vars
    var day_clicked = 0;
    var ignore_click = false;

    // event details dialog (show only)
    var event_show_dialog = function(event) {
      var $dialog = $("#eventshow");
      var calendar = event.calendar && me.calendars[event.calendar] ? me.calendars[event.calendar] : { editable:false };
      
      $dialog.find('div.event-section, div.event-line').hide();
      $('#event-title').html(Q(event.title)).show();
      
      if (event.location)
        $('#event-location').html('@ ' + Q(event.location)).show();
      if (event.description)
        $('#event-description').show().children('.event-text').html(nl2br(Q(event.description))); // TODO: format HTML with clickable links and stuff
      
      // TODO: create a nice human-readable string for the date/time range
      var fromto, duration = event.end.getTime() / 1000 - event.start.getTime() / 1000;
      if (event.allDay)
        fromto = $.fullCalendar.formatDate(event.start, settings['date_format']) + ' &mdash; ' + $.fullCalendar.formatDate(event.end, settings['date_format']);
      else if (duration < 86400 && event.start.getDay() == event.end.getDay())
        fromto = $.fullCalendar.formatDate(event.start, settings['date_format']) + ' ' + $.fullCalendar.formatDate(event.start, settings['time_format']) +  ' &mdash; '
          + $.fullCalendar.formatDate(event.end, settings['time_format']);
      else
        fromto = $.fullCalendar.formatDate(event.start, settings['date_format']) + ' ' + $.fullCalendar.formatDate(event.start, settings['time_format']) +  ' &mdash; '
          + $.fullCalendar.formatDate(event.end, settings['date_format']) + ' ' + $.fullCalendar.formatDate(event.end, settings['time_format']);
      $('#event-date').html(Q(fromto)).show();
      
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
      
      var buttons = {};
      if (calendar.editable) {
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
          $dialog.dialog('destroy');
          $dialog.hide();
        },
        buttons: buttons,
        minWidth: 320,
        width: 420
      }).show();
      
      $('<a>')
        .attr('href', '#')
        .html('More Options')
        .addClass('dropdown-link')
        .click(function(){ return false; })
        .insertBefore($dialog.parent().find('.ui-dialog-buttonset').children().first());
      
    };

    // bring up the event dialog (jquery-ui popup)
    var event_edit_dialog = function(action, event) {
      // close show dialog first
      $("#eventshow").dialog('close');
      
      var $dialog = $("#eventedit");
      var calendar = event.calendar && me.calendars[event.calendar] ? me.calendars[event.calendar] : { editable:action=='new' };

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
          $('select.edit-alarm-type').val(alarm[0]);
          
          if (alarm[1].match(/@(\d+)/)) {
            var ondate = new Date(parseInt(RegExp.$1) * 1000);
            $('select.edit-alarm-offset').val('@');
            $('input.edit-alarm-date').val($.fullCalendar.formatDate(ondate, settings['date_format']));
            $('input.edit-alarm-time').val($.fullCalendar.formatDate(ondate, settings['time_format']));
          }
          else if (alarm[1].match(/([-+])(\d+)([MHD])/)) {
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
      else if (event.start) {
        $('input.edit-recurrence-weekly-byday').val([weekdays[event.start.getDay()]]);
      }
      if (event.recurrence && event.recurrence.BYMONTHDAY) {
        $('input.edit-recurrence-monthly-bymonthday').val(String(event.recurrence.BYMONTHDAY).split(','));
        $('input.edit-recurrence-monthly-mode').val(['BYMONTHDAY']);
      }
      else if (event.start) {
        $('input.edit-recurrence-monthly-bymonthday').val([event.start.getDate()]);
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
      
      // buttons
      var buttons = {};
      
      buttons[rcmail.gettext('save', 'calendar')] = function() {
        var start = me.parse_datetime(starttime.val(), startdate.val());
        var end = me.parse_datetime(endtime.val(), enddate.val());
        
        // post data to server
        var data = {
          action: action,
          start: start.getTime()/1000,
          end: end.getTime()/1000,
          allday: allday.checked?1:0,
          title: title.val(),
          description: description.val(),
          location: location.val(),
          categories: categories.val(),
          free_busy: freebusy.val(),
          priority: priority.val(),
          recurrence: '',
          alarms:'',
        };
        
        // serialize alarm settings
        // TODO: support multiple alarm entries
        var alarm = $('select.edit-alarm-type').val();
        if (alarm) {
          var val, offset = $('select.edit-alarm-offset').val();
          if (offset == '@')
            data.alarms = alarm + ':@' + (me.parse_datetime($('input.edit-alarm-time').val(), $('input.edit-alarm-date').val()).getTime()/1000);
          else if ((val = parseInt($('input.edit-alarm-value').val())) && !isNaN(val) && val >= 0)
            data.alarms = alarm + ':' + offset[0] + val + offset[1];
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
            data.recurrence.UNTIL = me.parse_datetime(endtime.val(), rrenddate.val()).getTime()/1000;
          
          if (freq == 'WEEKLY') {
            var byday = [];
            $('input.edit-recurrence-weekly-byday:checked').each(function(){ byday.push(this.value); });
            data.recurrence.BYDAY = byday.join(',');
          }
          else if (freq == 'MONTHLY') {
            var mode = $('input.edit-recurrence-monthly-mode:checked').val(), bymonday = [];
            if (mode == 'BYMONTHDAY') {
               $('input.edit-recurrence-monthly-bymonthday:checked').each(function(){ bymonday.push(this.value); });
              data.recurrence.BYMONTHDAY = bymonday.join(',');
            }
            else
              data.recurrence.BYDAY = $('#edit-recurrence-monthly-prefix').val() + $('#edit-recurrence-monthly-byday').val();
          }
          else if (freq == 'YEARLY') {
            var byday, bymonth = [];
            $('input.edit-recurrence-yearly-bymonth:checked').each(function(){ bymonth.push(this.value); });
            data.recurrence.BYMONTH = bymonth.join(',');
            if ((byday = $('#edit-recurrence-yearly-byday').val()))
              data.recurrence.BYDAY = $('#edit-recurrence-yearly-prefix').val() + byday;
          }
        }
        
        if (event.id)
          data.id = event.id;
        else
          data.calendar = calendars.val();

        rcmail.http_post('plugin.event', { e:data });
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
          $dialog.dialog("destroy");
          $dialog.hide();
        },
        buttons: buttons,
        minWidth: 440,
        width: 480
      }).show();

      title.select();
    };
    
    // mouse-click handler to check if the show dialog is still open and prevent default action
    var dialog_check = function(e) {
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

    // general datepicker settings
    this.datepicker_settings = {
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


    // from time and date strings to a real date object
    this.parse_datetime = function(time, date) {
      // we use the utility function from datepicker to parse dates
      var date = $.datepicker.parseDate(me.datepicker_settings.dateFormat, date, me.datepicker_settings);
      var time_arr = time.split(/[:.]/);
      if (!isNaN(time_arr[0])) date.setHours(time_arr[0]);
      if (!isNaN(time_arr[1])) date.setMinutes(time_arr[1]);
      return date;
    };


    // public method to bring up the new event dialog
    this.add_event = function() {
      if (this.selected_calendar) {
        var now = new Date();
        var date = $('#calendar').fullCalendar('getDate') || now;
        date.setHours(now.getHours()+1);
        date.setMinutes(0);
        var end = new Date(date.getTime());
        end.setHours(date.getHours()+1);
        event_edit_dialog('new', { start:date, end:end, allDay:false, calendar:this.selected_calendar });
      }
    };

    // delete the given event after showing a confirmation dialog
    this.delete_event = function(event) {
      // send remove request to plugin
      if (confirm(rcmail.gettext('deleteventconfirm', 'calendar'))) {
        rcmail.http_post('plugin.event', { e:{ action:'remove', id:event.id } });
        return true;
      }

      return false;
    };


    // create list of event sources AKA calendars
    this.calendars = {};
    var li, cal, event_sources = [];
    for (var id in rcmail.env.calendars) {
      cal = rcmail.env.calendars[id];
      this.calendars[id] = $.extend({
        url: "./?_task=calendar&_action=plugin.load_events&source="+escape(id),
        editable: !cal.readonly,
        className: 'fc-event-cal-'+id,
        id: id
      }, cal);
      event_sources.push(this.calendars[id]);
      
      // init event handler on calendar list checkbox
      if ((li = rcmail.get_folder_li(id, 'rcmlical'))) {
        $('#'+li.id+' input').click(function(e){
          var id = $(this).data('id');
          if (me.calendars[id]) {  // add or remove event source on click
            var action = this.checked ? 'addEventSource' : 'removeEventSource';
            $('#calendar').fullCalendar(action, me.calendars[id]);
          }
        }).data('id', id);
        $(li).click(function(e){
          var id = $(this).data('id');
          rcmail.select_folder(id, me.selected_calendar, 'rcmlical');
          me.selected_calendar = id;
        }).data('id', id);
      }
      
      if (!cal.readonly) {
        this.selected_calendar = id;
        rcmail.enable_command('plugin.addevent', true);
      }
    }

    // initalize the fullCalendar plugin
    $('#calendar').fullCalendar({
      header: {
        left: 'prev,next today',
        center: 'title',
        right: 'agendaDay,agendaWeek,month'
      },
      aspectRatio: 1,
      height: $(window).height() - 95,
      eventSources: event_sources,
      monthNames : settings['months'],
      monthNamesShort : settings['months_short'],
      dayNames : settings['days'],
      dayNamesShort : settings['days_short'],
      firstDay : settings['first_day'],
      firstHour : settings['first_hour'],
      slotMinutes : 60/settings['timeslots'],
      timeFormat: settings['time_format'],
      axisFormat : settings['time_format'],
      columnFormat: {
        month: 'ddd', // Mon
        week: 'ddd ' + settings['date_short'], // Mon 9/7
        day: 'dddd ' + settings['date_short']  // Monday 9/7
      },
      defaultView: settings['default_view'],
      allDayText: rcmail.gettext('all-day', 'calendar'),
      buttonText: {
        today: settings['today'],
        day: rcmail.gettext('day', 'calendar'),
        week: rcmail.gettext('week', 'calendar'),
        month: rcmail.gettext('month', 'calendar')
      },
      selectable: true,
      selectHelper: true,
      loading : function(isLoading) {
        this._rc_loading = rcmail.set_busy(isLoading, 'loading', this._rc_loading);
      },
      // event rendering
      eventRender: function(event, element, view) {
        if(view.name != "month") {
          if (event.categories) {
            if(!event.allDay)
              element.find('span.fc-event-title').after('<span class="fc-event-categories">' + event.categories + '</span>');
          }
          if (event.location) {
            element.find('span.fc-event-title').after('<span class="fc-event-location">@' + event.location + '</span>');
          }
          if (event.description) {
            if (!event.allDay){
              element.find('span.fc-event-title').after('<span class="fc-event-description">' + event.description + '</span>');
            }
          }
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
        if (now - day_clicked < 400)  // emulate double-click on day
          event_edit_dialog('new', { start:date, end:date, allDay:allDay, calendar:me.selected_calendar });
        day_clicked = now;
        if (!ignore_click) {
          view.calendar.gotoDate(date);
          fullcalendar_update();
        }
      },
      // callback when a specific event is clicked
      eventClick : function(event) {
        event_show_dialog(event);
      },
      // callback when an event was dragged and finally dropped
      eventDrop: function(event, dayDelta, minuteDelta, allDay, revertFunc) {
        if (event.end == null) {
          event.end = event.start;
        }
        // send move request to server
        var data = {
          action: 'move',
          id: event.id,
          start: event.start.getTime()/1000,
          end: event.end.getTime()/1000,
          allday: allDay?1:0
        };
        rcmail.http_post('plugin.event', { e:data });
      },
      // callback for event resizing
      eventResize : function(event, delta) {
        // send resize request to server
        var data = {
          action: 'resize',
          id: event.id, 
          start: event.start.getTime()/1000,
          end: event.end.getTime()/1000, 
        };
        rcmail.http_post('plugin.event', { e:data });
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
        $('#calendar').fullCalendar('gotoDate', date).fullCalendar('setDate', date).fullCalendar('changeView', 'agendaWeek');
        $("#datepicker").datepicker('setDate', date);
        window.setTimeout(init_week_events, 10);
      }).css('cursor', 'pointer');
    };

    // initialize small calendar widget using jQuery UI datepicker
    $('#datepicker').datepicker($.extend(this.datepicker_settings, {
      inline: true,
      showWeek: true,
      changeMonth: false, // maybe enable?
      changeYear: false,  // maybe enable?
      onSelect: function(dateText, inst) {
        ignore_click = true;
        var d = $("#datepicker").datepicker('getDate'); //parse_datetime('0:0', dateText);
        $('#calendar').fullCalendar('gotoDate', d).fullCalendar('select', d, d, true);
        window.setTimeout(init_week_events, 10);
      },
      onChangeMonthYear: function(year, month, inst) {
        window.setTimeout(init_week_events, 10);
        var d = $("#datepicker").datepicker('getDate');
        d.setYear(year);
        d.setMonth(month - 1);
        $("#datepicker").data('year', year).data('month', month);
        //$('#calendar').fullCalendar('gotoDate', d).fullCalendar('setDate', d);
      },
    }));
    window.setTimeout(init_week_events, 10);

    // react on fullcalendar buttons
    var fullcalendar_update = function() {
      var d = $('#calendar').fullCalendar('getDate');
      $("#datepicker").datepicker('setDate', d);
      window.setTimeout(init_week_events, 10);
    };
    $("#calendar .fc-button-prev").click(fullcalendar_update);
    $("#calendar .fc-button-next").click(fullcalendar_update);
    $("#calendar .fc-button-today").click(fullcalendar_update);
    
    // hide event dialog when clicking somewhere into document
    $(document).bind('mousedown', dialog_check);

  } // end rcube_calendar class

  
  // configure toobar buttons
  rcmail.register_command('plugin.addevent', function(){ cal.add_event(); }, true);

  // export events
  rcmail.register_command('plugin.export', function(){ rcmail.goto_url('plugin.export_events', { source:cal.selected_calendar }); }, true);
  rcmail.enable_command('plugin.export', true);

  // reload calendar
  rcmail.addEventListener('plugin.reload_calendar', reload_calendar);
  function reload_calendar() {
    $('#calendar').fullCalendar('refetchEvents');
  }


  var formattime = function(hour, minutes) {
      return ((hour < 10) ? "0" : "") + hour + ((minutes < 10) ? ":0" : ":") + minutes;
  };

  // if start date is changed, shift end date according to initial duration
  var shift_enddate = function(dateText) {
    var newstart = cal.parse_datetime('0', dateText);
    var newend = new Date(newstart.getTime() + $('#edit-startdate').data('duration') * 1000);
    $('#edit-enddate').val($.fullCalendar.formatDate(newend, cal.settings['date_format']));
  };


  // let's go
  var cal = new rcube_calendar(rcmail.env.calendar_settings);

  $(window).resize(function() {
    $('#calendar').fullCalendar('option', 'height', $(window).height() - 95);
  }).resize();

  // show toolbar
  $('#toolbar').show();

  // init event dialog
  $('#eventtabs').tabs();
  $('#edit-enddate, input.edit-alarm-date').datepicker(cal.datepicker_settings);
  $('#edit-startdate').datepicker(cal.datepicker_settings).datepicker('option', 'onSelect', shift_enddate).change(function(){ shift_enddate(this.value); });
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
    $('#edit-recurrence-enddate').datepicker(cal.datepicker_settings).click(function(){ $("#edit-recurrence-repeat-until").prop('checked', true) });
    
    // avoid unselecting all weekdays, monthdays and months
    $('input.edit-recurrence-weekly-byday, input.edit-recurrence-monthly-bymonthday, input.edit-recurrence-yearly-bymonth').click(function(){
      if (!$('input.'+this.className+':checked').length)
        this.checked = true;
    });

    // initialize sidebar toggle
    $('#sidebartoggle').click(function() {
      var width = $(this).data('sidebarwidth');
      var offset = $(this).data('offset');
      var $sidebar = $('#sidebar'), time = 250;
      
      if ($sidebar.is(':visible')) {
        $sidebar.animate({ left:'-'+(width+10)+'px' }, time, function(){ $('#sidebar').hide(); });
        $(this).animate({ left:'6px'}, time, function(){ $('#sidebartoggle').addClass('sidebarclosed') });
        $('#calendar').animate({ left:'20px'}, time, function(){ $(this).fullCalendar('render'); });
      }
      else {
        $sidebar.show().animate({ left:'10px' }, time);
        $(this).animate({ left:offset+'px'}, time, function(){ $('#sidebartoggle').removeClass('sidebarclosed'); });
        $('#calendar').animate({ left:(width+20)+'px'}, time, function(){ $(this).fullCalendar('render'); });
      }
    })
    .data('offset', $('#sidebartoggle').position().left)
    .data('sidebarwidth', $('#sidebar').width() + $('#sidebar').position().left);

});
