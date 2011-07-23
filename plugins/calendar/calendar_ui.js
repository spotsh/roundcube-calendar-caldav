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

// Roundcube calendar UI client class
function rcube_calendar_ui(settings)
{
    // extend base class
    rcube_calendar.call(this, settings);
    
    /***  member vars  ***/
    this.is_loading = false;
    this.selected_event = null;
    this.selected_calendar = null;
    this.search_request = null;
    this.eventcount = [];
    this.saving_lock = null;


    /***  private vars  ***/
    var me = this;
    var gmt_offset = (new Date().getTimezoneOffset() / -60) - (settings.timezone || 0);
    var day_clicked = day_clicked_ts = 0;
    var ignore_click = false;
    var event_attendees = null;
    var attendees_list;
    var freebusy_ui = {};
    var freebusy_data = {};
    var freebusy_needsupdate;

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
      selectOtherMonths: true
    };


    /***  private methods  ***/
    
    var Q = this.quote_html;
    
    // php equivalent
    var nl2br = function(str)
    {
      return String(str).replace(/\n/g, "<br/>");
    };
    
    // 
    var explode_quoted_string = function(str, delimiter)
    {
      var result = [],
        strlen = str.length,
        q, p, i;

      for (q = p = i = 0; i < strlen; i++) {
        if (str[i] == '"' && str[i-1] != '\\') {
          q = !q;
        }
        else if (!q && str[i] == delimiter) {
          result.push(str.substring(p, i));
          p = i + 1;
        }
      }

      result.push(str.substr(p));
      return result;
    };

    // from time and date strings to a real date object
    var parse_datetime = function(time, date)
    {
      // we use the utility function from datepicker to parse dates
      var date = date ? $.datepicker.parseDate(datepicker_settings.dateFormat, date, datepicker_settings) : new Date();
      
      var time_arr = time.replace(/\s*[ap][.m]*/i, '').replace(/0([0-9])/g, '$1').split(/[:.]/);
      if (!isNaN(time_arr[0])) {
        date.setHours(time_arr[0]);
        if (time.match(/p[.m]*/i) && date.getHours() < 12)
          date.setHours(parseInt(time_arr[0]) + 12);
        else if (time.match(/a[.m]*/i) && date.getHours() == 12)
          date.setHours(0);
      }
      if (!isNaN(time_arr[1]))
        date.setMinutes(time_arr[1]);
      
      return date;
    };
    
    // convert the given Date object into a unix timestamp respecting browser's and user's timezone settings
    var date2unixtime = function(date)
    {
      return Math.round(date.getTime()/1000 + gmt_offset * 3600);
    };
    
    var fromunixtime = function(ts)
    {
      ts -= gmt_offset * 3600;
      return new Date(ts * 1000);
    }

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

    var load_attachment = function(event, att)
    {
      var qstring = '_id='+urlencode(att.id)+'&_event='+urlencode(event.recurrence_id||event.id)+'&_cal='+urlencode(event.calendar);

      // open attachment in frame if it's of a supported mimetype
      if (id && att.mimetype && $.inArray(att.mimetype, rcmail.mimetypes)>=0) {
        rcmail.attachment_win = window.open(rcmail.env.comm_path+'&_action=get-attachment&'+qstring+'&_frame=1', 'rcubeeventattachment');
        if (rcmail.attachment_win) {
          window.setTimeout(function() { rcmail.attachment_win.focus(); }, 10);
          return;
        }
      }

      rcmail.goto_url('get-attachment', qstring+'&_download=1', false);
    };

    // build event attachments list
    var event_show_attachments = function(list, container, event, edit)
    {
      var i, id, len, img, content, li, elem,
        ul = document.createElement('UL');

      for (i=0, len=list.length; i<len; i++) {
        li = document.createElement('LI');
        elem = list[i];

        if (edit) {
          rcmail.env.attachments[elem.id] = elem;
          // delete icon
          content = document.createElement('A');
          content.href = '#delete';
          content.title = rcmail.gettext('delete');
          $(content).click({id: elem.id}, function(e) { remove_attachment(this, e.data.id); return false; });

          if (!rcmail.env.deleteicon)
            content.innerHTML = rcmail.gettext('delete');
          else {
            img = document.createElement('IMG');
            img.src = rcmail.env.deleteicon;
            img.alt = rcmail.gettext('delete');
            content.appendChild(img);
          }

          li.appendChild(content);
        }

        // name/link
        content = document.createElement('A');
        content.innerHTML = list[i].name;
        content.href = '#load';
        $(content).click({event: event, att: elem}, function(e) {
          load_attachment(e.data.event, e.data.att); return false; });
        li.appendChild(content);

        ul.appendChild(li);
      }

      if (edit && rcmail.gui_objects.attachmentlist) {
        ul.id = rcmail.gui_objects.attachmentlist.id;
        rcmail.gui_objects.attachmentlist = ul;
      }

      container.empty().append(ul);
    };

    var remove_attachment = function(elem, id)
    {
      $(elem.parentNode).hide();
      rcmail.env.deleted_attachments.push(id);
      delete rcmail.env.attachments[id];
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
      $('#event-date').html(Q(me.event_date_text(event))).show();
      
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

      // create attachments list
      if ($.isArray(event.attachments)) {
        event_show_attachments(event.attachments, $('#event-attachments').children('.event-text'), event);
        if (event.attachments.length > 0) {
          $('#event-attachments').show();
        }
      }
      else if (calendar.attachments) {
        // fetch attachments, some drivers doesn't set 'attachments' prop of the event?
      }
      
      // list event attendees
      if (calendar.attendees && event.attendees) {
        var data, dispname, organizer = false, html = '';
        for (var j=0; j < event.attendees.length; j++) {
          data = event.attendees[j];
          dispname = Q(data.name || data.email);
          if (data.email)
            dispname = '<a href="mailto:' + data.email + '" title="' + Q(data.email) + '" class="mailtolink">' + dispname + '</a>';
          html += '<span class="attendee ' + String(data.role == 'ORGANIZER' ? 'organizer' : data.status).toLowerCase() + '">' + dispname + '</span> ';
          if (data.role == 'ORGANIZER')
            organizer = true;
        }
        if (html && event.attendees.length > 1 || !organizer) {
          $('#event-attendees').show()
            .children('.event-text')
            .html(html)
            .find('a.mailtolink').click(function(e) { rcmail.redirect(rcmail.url('mail/compose', { _to:this.href.substr(7) })); return false; });
        }
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
      me.selected_event = $.extend({}, event);  // clone event object
      freebusy_needsupdate = false;

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
            var ondate = fromunixtime(parseInt(RegExp.$1));
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
        
      // attendees
      event_attendees = [];
      attendees_list = $('#edit-attendees-table > tbody').html('');
      if (calendar.attendees && event.attendees) {
        for (var j=0; j < event.attendees.length; j++)
          add_attendee(event.attendees[j], true);
      }

      // attachments
      if (calendar.attachments) {
        rcmail.enable_command('remove-attachment', !calendar.readonly);
        rcmail.env.deleted_attachments = [];
        // we're sharing some code for uploads handling with app.js
        rcmail.env.attachments = [];
        rcmail.env.compose_id = event.id; // for rcmail.async_upload_form()

        if ($.isArray(event.attachments)) {
          event_show_attachments(event.attachments, $('#edit-attachments'), event, true);
        }
        else {
          $('#edit-attachments > ul').empty();
          // fetch attachments, some drivers doesn't set 'attachments' array for event?
        }
      }

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
          start: date2unixtime(start),
          end: date2unixtime(end),
          allday: allday.checked?1:0,
          title: title.val(),
          description: description.val(),
          location: location.val(),
          categories: categories.val(),
          free_busy: freebusy.val(),
          priority: priority.val(),
          sensitivity: sensitivity.val(),
          recurrence: '',
          alarms: '',
          attendees: event_attendees,
          deleted_attachments: rcmail.env.deleted_attachments,
          attachments: []
        };

        // serialize alarm settings
        // TODO: support multiple alarm entries
        var alarm = $('select.edit-alarm-type').val();
        if (alarm) {
          var val, offset = $('select.edit-alarm-offset').val();
          if (offset == '@')
            data.alarms = '@' + date2unixtime(parse_datetime($('input.edit-alarm-time').val(), $('input.edit-alarm-date').val())) + ':' + alarm;
          else if ((val = parseInt($('input.edit-alarm-value').val())) && !isNaN(val) && val >= 0)
            data.alarms = offset[0] + val + offset[1] + ':' + alarm;
        }

        // uploaded attachments list
        for (var i in rcmail.env.attachments)
          if (i.match(/^rcmfile(.+)/))
            data.attachments.push(RegExp.$1);

        // read attendee roles
        $('select.edit-attendee-role').each(function(i, elem){
          if (data.attendees[i])
            data.attendees[i].role = $(elem).val();
        });

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
            data.recurrence.UNTIL = date2unixtime(parse_datetime(endtime.val(), rrenddate.val()));
          
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

        update_event(action, data);
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
        closeOnEscape: false,
        title: rcmail.gettext((action == 'edit' ? 'edit_event' : 'new_event'), 'calendar'),
        close: function() {
          $dialog.dialog("destroy").hide();
          freebusy_data = {};
        },
        buttons: buttons,
        minWidth: 500,
        width: 580
      }).show();

      title.select();
    };

    var event_freebusy_dialog = function()
    {
      var $dialog = $('#eventfreebusy').dialog('close');
      var event = me.selected_event;
      
      if (!event_attendees.length)
        return false;
      
      // set form elements
      var duration = Math.round((event.end.getTime() - event.start.getTime()) / 1000);
      var startdate = $('#schedule-startdate').val($.fullCalendar.formatDate(event.start, settings['date_format'])).data('duration', duration);
      var starttime = $('#schedule-starttime').val($.fullCalendar.formatDate(event.start, settings['time_format'])).show();
      var enddate = $('#schedule-enddate').val($.fullCalendar.formatDate(event.end, settings['date_format']));
      var endtime = $('#schedule-endtime').val($.fullCalendar.formatDate(event.end, settings['time_format'])).show();
      var allday = $('#edit-allday').get(0);
      
      if (allday.checked) {
        starttime.val("00:00").hide();
        endtime.val("23:59").hide();
      }
      
      // render time slots
      var now = new Date(), fb_start = new Date(), fb_end = new Date();
      fb_start.setTime(Math.max(now, event.start));
      fb_start.setHours(0); fb_start.setMinutes(0); fb_start.setSeconds(0); fb_start.setMilliseconds(0);
      fb_end.setTime(fb_start.getTime() + 86400000);
      
      freebusy_data = {};
      freebusy_ui.loading = 1;  // prevent render_freebusy_grid() to load data yet
      freebusy_ui.numdays = allday.checked ? 7 : 1;
      freebusy_ui.interval = allday.checked ? 360 : 60;
      freebusy_ui.start = fb_start;
      freebusy_ui.end = new Date(freebusy_ui.start.getTime() + 86400000 * freebusy_ui.numdays);
      render_freebusy_grid(0);
      
      // render list of attendees
      var domid, dispname, data, list_html = '';
      for (var i=0; i < event_attendees.length; i++) {
        data = event_attendees[i];
        dispname = Q(data.name || data.email);
        domid = String(data.email).replace(rcmail.identifier_expr, '');
        list_html += '<div class="attendee ' + String(data.role).toLowerCase() + '" id="rcmli' + domid + '">' + dispname + '</div>';
      }
      
      $('#schedule-attendees-list').html(list_html);
      
      // dialog buttons
      var buttons = {};
      
      buttons[rcmail.gettext('cancel', 'calendar')] = function() {
        $dialog.dialog("close");
      };
      
      $dialog.dialog({
        modal: true,
        resizable: true,
        closeOnEscape: true,
        title: rcmail.gettext('scheduletime', 'calendar'),
        close: function() {
          $dialog.dialog("destroy").hide();
        },
        buttons: buttons,
        minWidth: 640,
        width: 850
      }).show();
      
      // adjust dialog size to fit grid without scrolling
      var gridw = $('#schedule-freebusy-times').width();
      var overflow = gridw - $('#attendees-freebusy-table td.times').width() + 1;
      if (overflow > 0) {
        $dialog.dialog('option', 'width', Math.min((window.innerWidth || document.documentElement.clientWidth) - 40, 850 + overflow));
        $dialog.dialog('option', 'position', ['center', 'center']);
      }
      
      // fetch data from server
      freebusy_ui.loading = 0;
      load_freebusy_data(freebusy_ui.start, freebusy_ui.interval);
    };

    // render an HTML table showing free-busy status for all the event attendees
    var render_freebusy_grid = function(delta)
    {
      if (delta) {
        freebusy_ui.start.setTime(freebusy_ui.start.getTime() + 86400000 * delta);
        freebusy_ui.end = new Date(freebusy_ui.start.getTime() + 86400000 * freebusy_ui.numdays);
      }
      
      var dayslots = Math.floor(1440 / freebusy_ui.interval);
      var lastdate, datestr, css, curdate = new Date(), dates_row = '<tr class="dates">', times_row = '<tr class="times">', slots_row = '';
      for (var s = 0, t = freebusy_ui.start.getTime(); t < freebusy_ui.end.getTime(); s++) {
        curdate.setTime(t);
        datestr = $.fullCalendar.formatDate(curdate, settings['date_format']);
        if (datestr != lastdate) {
          dates_row += '<th colspan="' + dayslots + '" class="boxtitle date' + $.fullCalendar.formatDate(curdate, 'ddMMyyyy') + '">' + Q(datestr) + '</th>';
          lastdate = datestr;
        }
        
        // TODO: define working hours by config
        css = (freebusy_ui.numdays == 1 && (curdate.getHours() < 6 || curdate.getHours() > 18)) ? 'offhours' : 'workinghours';
        times_row += '<td class="' + css + '">' + Q($.fullCalendar.formatDate(curdate, settings['time_format'])) + '</td>';
        slots_row += '<td class="' + css + ' unknown">&nbsp;</td>';
        
        t += freebusy_ui.interval * 60000;
      }
      dates_row += '</tr>';
      times_row += '</tr>';
      
      // render list of attendees
      var domid, data, list_html = '', times_html = '';
      for (var i=0; i < event_attendees.length; i++) {
        data = event_attendees[i];
        domid = String(data.email).replace(rcmail.identifier_expr, '');
        times_html += '<tr id="fbrow' + domid + '">' + slots_row + '</tr>';
      }
      
      var table = $('#schedule-freebusy-times');
      table.children('thead').html(dates_row + times_row);
      table.children('tbody').html(times_html);
      
      // if we have loaded free-busy data, show it
      if (!freebusy_ui.loading) {
        if (date2unixtime(freebusy_ui.start) < freebusy_data.start || date2unixtime(freebusy_ui.end) > freebusy_data.end || freebusy_ui.interval != freebusy_data.interval) {
          load_freebusy_data(freebusy_ui.start, freebusy_ui.interval);
        }
        else {
          for (var email, i=0; i < event_attendees.length; i++) {
            if ((email = event_attendees[i].email))
              update_freebusy_display(email);
          }
        }
      }
      
      // render current event date/time selection over grid table
      // use timeout to let the dom attributes (width/height/offset) be set first
      window.setTimeout(function(){ render_freebusy_overlay(); }, 10);
    };
    
    // render overlay element over the grid to visiualize the current event date/time
    var render_freebusy_overlay = function()
    {
      var overlay = $('#schedule-event-time');
      if (me.selected_event.end.getTime() < freebusy_ui.start.getTime() || me.selected_event.start.getTime() > freebusy_ui.end.getTime()) {
        overlay.hide();
      }
      else {
        var table = $('#schedule-freebusy-times'),
          width = 0,
          pos = { top:table.children('thead').height(), left:0 },
          eventstart = Math.floor(me.selected_event.start.getTime() / 1000),
          eventend = Math.floor(me.selected_event.end.getTime() / 1000),
          slotstart = Math.floor(freebusy_ui.start.getTime() / 1000),
          slotsize = freebusy_ui.interval * 60,
          slotend, fraction, $cell;
        
        // iterate through slots to determine position and size of the overlay
        table.children('thead').find('td').each(function(i, cell){
          slotend = slotstart + slotsize - 60;
          // event starts in this slot: compute left
          if (eventstart >= slotstart && eventstart <= slotend) {
            fraction = 1 - (slotend - eventstart) / slotsize;
            pos.left = Math.round(cell.offsetLeft + cell.offsetWidth * fraction);
          }
          // event ends in this slot: compute width
          else if (eventend >= slotstart && eventend <= slotend) {
            fraction = 1 - (slotend - eventend) / slotsize;
            width = Math.round(cell.offsetLeft + cell.offsetWidth * fraction) - pos.left;
          }

          slotstart = slotstart + slotsize;
        });

        if (!width)
          width = table.width() - pos.left;

        // overlay is visible
        if (width > 0)
          overlay.css({ width: (width-5)+'px', height:(table.children('tbody').height() - 4)+'px', left:pos.left+'px', top:pos.top+'px' }).show();
        else
          overlay.hide();
      }
      
    };
    
    // fetch free-busy information for each attendee from server
    var load_freebusy_data = function(from, interval)
    {
      var start = new Date(from.getTime() - 86400000 * 2);  // start 1 days before event
      var end = new Date(start.getTime() + 86400000 * 14);   // load 14 days
      
      // load free-busy information for every attendee
      var domid, email
      for (var i=0; i < event_attendees.length; i++) {
        if ((email = event_attendees[i].email)) {
          domid = String(email).replace(rcmail.identifier_expr, '');
          $('#rcmli' + domid).addClass('loading');
          freebusy_ui.loading++;
          
          $.ajax({
            type: 'GET',
            dataType: 'json',
            url: rcmail.url('freebusy-times'),
            data: { email:email, start:date2unixtime(start), end:date2unixtime(end), interval:interval, _remote: 1 },
            success: function(data){
              freebusy_ui.loading--;
              
              // copy data to member var
              var ts = data.start - 0;
              freebusy_data.start = ts;
              freebusy_data[data.email] = {};
              for (var i=0; i < data.slots.length; i++) {
                freebusy_data[data.email][ts] = data.slots[i];
                ts += data.interval * 60;
              }
              freebusy_data.end = ts;
              freebusy_data.interval = data.interval;

              // hide loading indicator
              var domid = String(data.email).replace(rcmail.identifier_expr, '');
              $('#rcmli' + domid).removeClass('loading');
              
              // update display
              update_freebusy_display(data.email);
            }
          });
        }
      }
    };

    // update free-busy grid with status loaded from server
    var update_freebusy_display = function(email)
    {
      var status_classes = ['unknown','free','busy','tentative','out-of-office'];
      var domid = String(email).replace(rcmail.identifier_expr, '');
      var row = $('#fbrow' + domid);
      var ts = date2unixtime(freebusy_ui.start);
      var fbdata = freebusy_data[email];
      
      if (fbdata && fbdata[ts] && row.length) {
        row.children().each(function(i, cell){
          cell.className = cell.className.replace('unknown', fbdata[ts] ? status_classes[fbdata[ts]] : 'unknown');
          ts += freebusy_ui.interval * 60;
        });
      }
    };


    // update event properties and attendees availability if event times have changed
    var event_times_changed = function()
    {
      if (me.selected_event) {
        var allday = $('#edit-allday').get(0);
        me.selected_event.start = parse_datetime(allday.checked ? '00:00' : $('#edit-starttime').val(), $('#edit-startdate').val());
        me.selected_event.end   = parse_datetime(allday.checked ? '23:59' : $('#edit-endtime').val(), $('#edit-enddate').val());
        if (event_attendees)
          freebusy_needsupdate = true;
        $('#edit-startdate').data('duration', Math.round((me.selected_event.end.getTime() - me.selected_event.start.getTime()) / 1000));
      }
    };
    
    // add the given list of participants
    var add_attendees = function(names)
    {
      names = explode_quoted_string(names.replace(/,\s*$/, ''), ',');

      // parse name/email pairs
      var item, email, name, success = false;
      for (var i=0; i < names.length; i++) {
        email = name = null;
        item = $.trim(names[i]);
        
        if (!item.length) {
          continue;
        } // address in brackets without name (do nothing)
        else if (item.match(/^<[^@]+@[^>]+>$/)) {
          email = item.replace(/[<>]/g, '');
        } // address without brackets and without name (add brackets)
        else if (rcube_check_email(item)) {
          email = item;
        } // address with name
        else if (item.match(/([^\s<@]+@[^>]+)>*$/)) {
          email = RegExp.$1;
          name = item.replace(email, '').replace(/^["\s<>]+/, '').replace(/["\s<>]+$/, '');
        }
        
        if (email) {
          add_attendee({ email:email, name:name, role:'REQ-PARTICIPANT', status:'NEEDS-ACTION' });
          success = true;
        }
        else {
          alert(rcmail.gettext('noemailwarning'));
        }
      }
      
      return success;
    };
    
    // add the given attendee to the list
    var add_attendee = function(data, edit)
    {
      // check for dupes...
      var exists = false;
      $.each(event_attendees, function(i, v){ exists |= (v.email == data.email); });
      if (exists)
        return false;
      
      var dispname = Q(data.name || data.email);
      if (data.email)
        dispname = '<a href="mailto:' + data.email + '" title="' + Q(data.email) + '" class="mailtolink">' + dispname + '</a>';
      
      // role selection
      var opts = {
        'ORGANIZER': rcmail.gettext('calendar.roleorganizer'),
        'REQ-PARTICIPANT': rcmail.gettext('calendar.rolerequired'),
        'OPT-PARTICIPANT': rcmail.gettext('calendar.roleoptional'),
        'CHAIR': rcmail.gettext('calendar.roleresource')
      };
      var select = '<select class="edit-attendee-role">';
      for (var r in opts)
        select += '<option value="'+ r +'" class="' + r.toLowerCase() + '"' + (data.role == r ? ' selected="selected"' : '') +'>' + Q(opts[r]) + '</option>';
      select += '</select>';
      
      // availability
      var avail = data.email ? 'loading' : 'unknown';
      if (edit && data.role == 'ORGANIZER' && data.status == 'ACCEPTED')
        avail = 'free';

      // delete icon
      var icon = rcmail.env.deleteicon ? '<img src="' + rcmail.env.deleteicon + '" alt="" />' : rcmail.gettext('delete');
      var dellink = '<a href="#delete" class="deletelink" title="' + Q(rcmail.gettext('delete')) + '">' + icon + '</a>';
      
      var html = '<td class="role">' + select + '</td>' +
        '<td class="name">' + dispname + '</td>' +
        '<td class="availability"><img src="./program/blank.gif" class="availabilityicon ' + avail + '" /></td>' +
        '<td class="confirmstate"><span class="' + String(data.status).toLowerCase() + '">' + Q(data.status) + '</span></td>' +
        '<td class="options">' + dellink + '</td>';
      
      var tr = $('<tr>')
        .addClass(String(data.role).toLowerCase())
        .html(html)
        .appendTo(attendees_list);
      
      tr.find('a.deletelink').click({ id:(data.email || data.name) }, function(e) { remove_attendee(this, e.data.id); return false; });
      tr.find('a.mailtolink').click(function(e) { rcmail.redirect(rcmail.url('mail/compose', { _to:this.href.substr(7) })); return false; });
      
      // check free-busy status
      if (avail == 'loading') {
        check_freebusy_status(tr.find('img.availabilityicon'), data.email, me.selected_event);
      }
      
      event_attendees.push(data);
    };
    
    // iterate over all attendees and update their free-busy status display
    var update_freebusy_status = function(event)
    {
      var icons = attendees_list.find('img.availabilityicon');
      for (var i=0; i < event_attendees.length; i++) {
        if (icons.get(i) && event_attendees[i].email && event_attendees[i].status != 'ACCEPTED')
          check_freebusy_status(icons.get(i), event_attendees[i].email, event);
      }
      
      freebusy_needsupdate = false;
    };
    
    // load free-busy status from server and update icon accordingly
    var check_freebusy_status = function(icon, email, event)
    {
      icon = $(icon).removeClass().addClass('availabilityicon loading');
      
      $.ajax({
        type: 'GET',
        dataType: 'html',
        url: rcmail.url('freebusy-status'),
        data: { email:email, start:date2unixtime(event.start), end:date2unixtime(event.end), _remote: 1 },
        success: function(status){
          icon.removeClass('loading').addClass(String(status).toLowerCase());
        },
        error: function(){
          icon.removeClass('loading').addClass('unknown');
        }
      });
    };
    
    // remove an attendee from the list
    var remove_attendee = function(elem, id)
    {
      $(elem).closest('tr').remove();
      event_attendees = $.grep(event_attendees, function(data){ return (data.name != id && data.email != id) });
    };
    
    // post the given event data to server
    var update_event = function(action, data)
    {
      me.saving_lock = rcmail.set_busy(true, 'calendar.savingdata');
      rcmail.http_post('event', { action:action, e:data });
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
        update_event(action, event);
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
          fc.fullCalendar('refetchEvents');
        }
      }).show();
      
      return true;
    };


    /*** public methods ***/
    
    //public method to show the print dialog.
    this.print_calendars = function(view)
    {
      if (!view) view = fc.fullCalendar('getView').name;
      var date = fc.fullCalendar('getDate') || new Date();
      var printwin = window.open(rcmail.url('print', { view: view, date: date2unixtime(date), search: this.search_query }), "rc_print_calendars", "toolbar=no,location=yes,menubar=yes,resizable=yes,scrollbars=yes,width=800");
      window.setTimeout(function(){ printwin.focus() }, 50);
    };


    // public method to bring up the new event dialog
    this.add_event = function() {
      if (this.selected_calendar) {
        var now = new Date();
        var date = fc.fullCalendar('getDate') || now;
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
        update_event('remove', { id:event.id, calendar:event.calendar });
        return true;
      }

      return false;
    };
    
    // opens a jquery UI dialog with event properties (or empty for creating a new calendar)
    this.calendar_edit_dialog = function(calendar)
    {
      // close show dialog first
      var $dialog = $("#calendarform").dialog('close');
      
      if (!calendar)
        calendar = { name:'', color:'cc0000', editable:true };
      
      var form, name, color;
      
      $dialog.html(rcmail.get_label('loading'));
      $.ajax({
        type: 'GET',
        dataType: 'html',
        url: rcmail.url('calendar'),
        data: { action:(calendar.id ? 'form-edit' : 'form-new'), calendar:{ id:calendar.id } },
        success: function(data){
          $dialog.html(data);
          form = $('#calendarform > form');
          name = $('#calendar-name').prop('disabled', !calendar.editable).val(calendar.editname || calendar.name);
          color = $('#calendar-color').val(calendar.color).miniColors({ value: calendar.color });
          name.select();
        }
      });

      // dialog buttons
      var buttons = {};
      
      buttons[rcmail.gettext('save', 'calendar')] = function() {
        // form is not loaded
        if (!form)
          return;
          
        // TODO: do some input validation
        if (!name.val() || name.val().length < 2) {
          alert(rcmail.gettext('invalidcalendarproperties', 'calendar'));
          name.select();
          return;
        }
        
        // post data to server
        var data = form.serializeJSON();
        if (data.color)
          data.color = data.color.replace(/^#/, '');
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
        fc.fullCalendar('removeEventSource', this.calendars[id]);
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
          var sources = [];
          
          if (this._search_message)
            rcmail.hide_message(this._search_message);
          
          for (var sid in this.calendars) {
            if (this.calendars[sid]) {
              this.calendars[sid].url = this.calendars[sid].url.replace(/&q=.+/, '') + '&q='+escape(q);
              sources.push(sid);
            }
          }
          id += '@'+sources.join(',');
          
          // ignore if query didn't change
          if (this.search_request == id) {
            return;
          }
          // remember current view
          else if (!this.search_request) {
            this.default_view = fc.fullCalendar('getView').name;
          }
          
          this.search_request = id;
          this.search_query = q;
          
          // change to list view
          fc.fullCalendar('option', 'listSections', 'month');
          fc.fullCalendar('changeView', 'table');
          
          // refetch events with new url (if not already triggered by changeView)
          if (!this.is_loading)
            fc.fullCalendar('refetchEvents');
        }
        else  // empty search input equals reset
          this.reset_quicksearch();
      }
    };
    
    // reset search and get back to normal event listing
    this.reset_quicksearch = function()
    {
      $(rcmail.gui_objects.qsearchbox).val('');
      
      if (this._search_message)
        rcmail.hide_message(this._search_message);
      
      if (this.search_request) {
        // restore original event sources and view mode from fullcalendar
        fc.fullCalendar('option', 'listSections', 'smart');
        for (var sid in this.calendars) {
          if (this.calendars[sid])
            this.calendars[sid].url = this.calendars[sid].url.replace(/&q=.+/, '');
        }
        if (this.default_view)
          fc.fullCalendar('changeView', this.default_view);
        
        if (!this.is_loading)
          fc.fullCalendar('refetchEvents');
        
        this.search_request = this.search_query = null;
      }
    };
    
    // callback if all sources have been fetched from server
    this.events_loaded = function(count)
    {
      if (this.search_request && !count)
        this._search_message = rcmail.display_message(rcmail.gettext('searchnoresults', 'calendar'), 'notice');
    }


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
            
            // add/remove event source
            fc.fullCalendar(action, me.calendars[id]);
            rcmail.save_pref({ name:'hidden_calendars', value:settings.hidden_calendars.join(',') });
          }
        }).data('id', id).get(0).checked = active;
        
        $(li).click(function(e){
          var id = $(this).data('id');
          rcmail.select_folder(id, me.selected_calendar, 'rcmlical');
          rcmail.enable_command('calendar-edit', true);
          rcmail.enable_command('calendar-remove', !me.calendars[id].readonly);
          me.selected_calendar = id;
        })
        .dblclick(function(){ me.calendar_edit_dialog(me.calendars[me.selected_calendar]); })
        .data('id', id);
      }
      
      if (!cal.readonly && !this.selected_calendar && (!settings.default_calendar || settings.default_calendar == id)) {
        this.selected_calendar = id;
        rcmail.enable_command('addevent', true);
      }
    }

    // initalize the fullCalendar plugin
    var fc = $('#calendar').fullCalendar({
      header: {
        left: 'prev,next today',
        center: 'title',
        right: 'agendaDay,agendaWeek,month,table'
      },
      aspectRatio: 1,
      ignoreTimezone: true,  // will treat the given date strings as in local (browser's) timezone
      height: $('#main').height(),
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
      listSections: 'smart',
      listRange: 60,  // show 60 days in list view
      tableCols: ['handle', 'date', 'time', 'title', 'location'],
      defaultView: settings['default_view'],
      allDayText: rcmail.gettext('all-day', 'calendar'),
      buttonText: {
        prev: (bw.ie6 ? '&nbsp;&lt;&lt;&nbsp;' : '&nbsp;&#9668;&nbsp;'),
        next: (bw.ie6 ? '&nbsp;&gt;&gt;&nbsp;' : '&nbsp;&#9658;&nbsp;'),
        today: settings['today'],
        day: rcmail.gettext('day', 'calendar'),
        week: rcmail.gettext('week', 'calendar'),
        month: rcmail.gettext('month', 'calendar'),
        table: rcmail.gettext('agenda', 'calendar')
      },
      selectable: true,
      selectHelper: true,
      loading: function(isLoading) {
        me.is_loading = isLoading;
        this._rc_loading = rcmail.set_busy(isLoading, 'loading', this._rc_loading);
        // trigger callback
        if (!isLoading && me.search_request)
          me.events_loaded($(this).fullCalendar('clientEvents').length);
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
        if (now - day_clicked_ts < 400 && day_clicked == date.getTime()) {  // emulate double-click on day
          var enddate = new Date(); enddate.setTime(date.getTime() + 86400000 - 60000);
          return event_edit_dialog('new', { start:date, end:enddate, allDay:allDay, calendar:me.selected_calendar });
        }
        
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
          start: date2unixtime(event.start),
          end: date2unixtime(event.end),
          allday: allDay?1:0
        };
        if (event.recurrence)
          recurring_edit_confirm(data, 'move');
        else
          update_event('move', data);
      },
      // callback for event resizing
      eventResize: function(event, delta) {
        // send resize request to server
        var data = {
          id: event.id,
          calendar: event.calendar,
          start: date2unixtime(event.start),
          end: date2unixtime(event.end)
        };
        if (event.recurrence)
          recurring_edit_confirm(data, 'resize');
        else
          update_event('resize', data);
      },
      viewDisplay: function(view) {
        me.eventcount = [];
        if (!bw.ie)
          window.setTimeout(function(){ $('div.fc-content').css('overflow', view.name == 'month' ? 'auto' : 'hidden') }, 10);
      },
      windowResize: function(view) {
        me.eventcount = [];
      }
    });


    // event handler for clicks on calendar week cell of the datepicker widget
    var init_week_events = function(){
      $('#datepicker table.ui-datepicker-calendar td.ui-datepicker-week-col').click(function(e){
        var base_date = minical.datepicker('getDate');
        var day_off = base_date.getDay() - 1;
        if (day_off < 0) day_off = 6;
        var base_kw = $.datepicker.iso8601Week(base_date);
        var kw = parseInt($(this).html());
        var diff = (kw - base_kw) * 7 * 86400000;
        // select monday of the chosen calendar week
        var date = new Date(base_date.getTime() - day_off * 86400000 + diff);
        fc.fullCalendar('gotoDate', date).fullCalendar('setDate', date).fullCalendar('changeView', 'agendaWeek');
        minical.datepicker('setDate', date);
        window.setTimeout(init_week_events, 10);
      }).css('cursor', 'pointer');
    };

    // initialize small calendar widget using jQuery UI datepicker
    var minical = $('#datepicker').datepicker($.extend(datepicker_settings, {
      inline: true,
      showWeek: true,
      changeMonth: false, // maybe enable?
      changeYear: false,  // maybe enable?
      onSelect: function(dateText, inst) {
        ignore_click = true;
        var d = minical.datepicker('getDate'); //parse_datetime('0:0', dateText);
        fc.fullCalendar('gotoDate', d).fullCalendar('select', d, d, true);
        window.setTimeout(init_week_events, 10);
      },
      onChangeMonthYear: function(year, month, inst) {
        window.setTimeout(init_week_events, 10);
        var d = minical.datepicker('getDate');
        d.setYear(year);
        d.setMonth(month - 1);
        minical.data('year', year).data('month', month);
        //fc.fullCalendar('gotoDate', d).fullCalendar('setDate', d);
      }
    }));
    window.setTimeout(init_week_events, 10);

    // react on fullcalendar buttons
    var fullcalendar_update = function() {
      var d = fc.fullCalendar('getDate');
      minical.datepicker('setDate', d);
      window.setTimeout(init_week_events, 10);
    };
    $("#calendar .fc-button-prev").click(fullcalendar_update);
    $("#calendar .fc-button-next").click(fullcalendar_update);
    $("#calendar .fc-button-today").click(fullcalendar_update);

    // format time string
    var formattime = function(hour, minutes, start) {
      var time, diff, unit, duration = '', d = new Date();
      d.setHours(hour);
      d.setMinutes(minutes);
      time = $.fullCalendar.formatDate(d, settings['time_format']);
      if (start) {
        diff = Math.floor((d.getTime() - start.getTime()) / 60000);
        if (diff > 0) {
          unit = 'm';
          if (diff >= 60) {
            unit = 'h';
            diff = Math.round(diff / 3) / 20;
          }
          duration = ' (' + diff + unit + ')';
        }
      }
      return [time, duration];
    };
    
    var autocomplete_times = function(p, callback) {
      /* Time completions */
      var result = [];
      var now = new Date();
      var st, start = (this.element.attr('id').indexOf('endtime') > 0
        && (st = $('#edit-starttime').val())
        && $('#edit-startdate').val() == $('#edit-enddate').val())
        ? parse_datetime(st, '') : null;
      var full = p.term - 1 > 0 || p.term.length > 1;
      var hours = start ? start.getHours() :
        (full ? parse_datetime(p.term, '') : now).getHours();
      var step = 15;
      var minutes = hours * 60 + (full ? 0 : now.getMinutes());
      var min = Math.ceil(minutes / step) * step % 60;
      var hour = Math.floor(Math.ceil(minutes / step) * step / 60);
      // list hours from 0:00 till now
      for (var h = start ? start.getHours() : 0; h < hours; h++)
        result.push(formattime(h, 0, start));
      // list 15min steps for the next two hours
      for (; h < hour + 2 && h < 24; h++) {
        while (min < 60) {
          result.push(formattime(h, min, start));
          min += step;
        }
        min = 0;
      }
      // list the remaining hours till 23:00
      while (h < 24)
        result.push(formattime((h++), 0, start));
      
      return callback(result);
    };
    
    var autocomplete_open = function(event, ui) {
      // scroll to current time
      var $this = $(this);
      var widget = $this.autocomplete('widget');
      var menu = $this.data('autocomplete').menu;
      var amregex = /^(.+)(a[.m]*)/i;
      var pmregex = /^(.+)(a[.m]*)/i;
      var val = $(this).val().replace(amregex, '0:$1').replace(pmregex, '1:$1');
      var li, html;
      widget.css('width', '10em');
      widget.children().each(function(){
        li = $(this);
        html = li.children().first().html().replace(/\s+\(.+\)$/, '').replace(amregex, '0:$1').replace(pmregex, '1:$1');
        if (html == val)
          menu.activate($.Event({ type:'keypress' }), li);
      });
    };

    // if start date is changed, shift end date according to initial duration
    var shift_enddate = function(dateText) {
      var newstart = parse_datetime('0', dateText);
      var newend = new Date(newstart.getTime() + $('#edit-startdate').data('duration') * 1000);
      $('#edit-enddate').val($.fullCalendar.formatDate(newend, me.settings['date_format']));
      event_times_changed();
    };

    // init event dialog
    $('#eventtabs').tabs({
      show: function(event, ui) {
        if (ui.panel.id == 'event-tab-3') {
          $('#edit-attendee-name').select();
          if (freebusy_needsupdate && me.selected_event)
            update_freebusy_status(me.selected_event);
        }
      }
    });
    $('#edit-enddate, input.edit-alarm-date').datepicker(datepicker_settings);
    $('#edit-startdate').datepicker(datepicker_settings).datepicker('option', 'onSelect', shift_enddate).change(function(){ shift_enddate(this.value); });
    $('#edit-enddate').datepicker('option', 'onSelect', event_times_changed).change(event_times_changed);
    $('#edit-allday').click(function(){ $('#edit-starttime, #edit-endtime')[(this.checked?'hide':'show')](); event_times_changed(); });

    // configure drop-down menu on time input fields based on jquery UI autocomplete
    $('#edit-starttime, #edit-endtime, input.edit-alarm-time')
      .attr('autocomplete', "off")
      .autocomplete({
        delay: 100,
        minLength: 1,
        source: autocomplete_times,
        open: autocomplete_open,
        change: event_times_changed,
        select: function(event, ui) {
          $(this).val(ui.item[0]);
          return false;
        }
      })
      .click(function() {  // show drop-down upon clicks
        $(this).autocomplete('search', $(this).val() ? $(this).val().replace(/\D.*/, "") : " ");
      }).each(function(){
        $(this).data('autocomplete')._renderItem = function(ul, item) {
          return $('<li>')
            .data('item.autocomplete', item)
            .append('<a>' + item[0] + item[1] + '</a>')
            .appendTo(ul);
          };
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
    
    // init attendees autocompletion
    rcmail.init_address_input_events($('#edit-attendee-name'));
    rcmail.addEventListener('autocomplete_insert', function(e){ $('#edit-attendee-add').click(); });

    $('#edit-attendee-add').click(function(){
      var input = $('#edit-attendee-name');
      if (add_attendees(input.val()))
        input.val('');
    });
    
    $('#edit-attendee-schedule').click(function(){
      event_freebusy_dialog();
    });
    
    $('#shedule-freebusy-prev').html(bw.ie6 ? '&lt;&lt;' : '&#9668;').button().click(function(){ render_freebusy_grid(-1); });
    $('#shedule-freebusy-next').html(bw.ie6 ? '&gt;&gt;' : '&#9658;').button().click(function(){ render_freebusy_grid(1); }).parent().buttonset();
    
    $('#schedule-freebusy-wokinghours').click(function(){
      $('#workinghourscss').remove();
      if (this.checked)
        $('<style type="text/css" id="workinghourscss"> td.offhours { opacity:0.3; filter:alpha(opacity=30) } </style>').appendTo('head');
    });

    // add proprietary css styles if not IE
    if (!bw.ie)
      $('div.fc-content').addClass('rcube-fc-content');

    // hide event dialog when clicking somewhere into document
    $(document).bind('mousedown', dialog_check);

} // end rcube_calendar class


/* calendar plugin initialization */
window.rcmail && rcmail.addEventListener('init', function(evt) {

  // configure toolbar buttons
  rcmail.register_command('addevent', function(){ cal.add_event(); }, true);
  rcmail.register_command('print', function(){ cal.print_calendars(); }, true);

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
  rcmail.addEventListener('plugin.unlock_saving', function(p){ rcmail.set_busy(false, null, cal.saving_lock); });
  rcmail.addEventListener('plugin.ping_url', function(p){ p.event = null; new Image().src = rcmail.url(p.action, p); });

  // let's go
  var cal = new rcube_calendar_ui(rcmail.env.calendar_settings);

  $(window).resize(function() {
    $('#calendar').fullCalendar('option', 'height', $('#main').height());
  }).resize();

  // show toolbar
  $('#toolbar').show();

});
