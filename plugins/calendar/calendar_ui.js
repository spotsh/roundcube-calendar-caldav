/**
 * Client UI Javascript for the Calendar plugin
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
    this.saving_lock = null;


    /***  private vars  ***/
    var DAY_MS = 86400000;
    var HOUR_MS = 3600000;
    var me = this;
    var gmt_offset = (new Date().getTimezoneOffset() / -60) - (settings.timezone || 0) - (settings.dst || 0);
    var client_timezone = new Date().getTimezoneOffset();
    var day_clicked = day_clicked_ts = 0;
    var ignore_click = false;
    var event_defaults = { free_busy:'busy' };
    var event_attendees = [];
    var attendees_list;
    var freebusy_ui = { workinhoursonly:false, needsupdate:false };
    var freebusy_data = {};
    var event_resizing = false;
    var current_view = null;
    var exec_deferred = bw.ie6 ? 5 : 1;
    var sensitivitylabels = { 0:rcmail.gettext('public','calendar'), 1:rcmail.gettext('private','calendar'), 2:rcmail.gettext('confidential','calendar') };
    var ui_loading = rcmail.set_busy(true, 'loading');

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
    
    var text2html = function(str, maxlen, maxlines)
    {
      var html = Q(String(str));
      
      // limit visible text length
      if (maxlen) {
        var morelink = ' <a href="#more" onclick="$(this).hide().next().show();return false" class="morelink">'+rcmail.gettext('showmore','calendar')+'</a><span style="display:none">',
          lines = html.split(/\r?\n/),
          words, out = '', len = 0;
        
        for (var i=0; i < lines.length; i++) {
          len += lines[i].length;
          if (maxlines && i == maxlines - 1) {
            out += lines[i] + '\n' + morelink;
            maxlen = html.length * 2;
          }
          else if (len > maxlen) {
            len = out.length;
            words = lines[i].split(' ');
            for (var j=0; j < words.length; j++) {
              len += words[j].length + 1;
              out += words[j] + ' ';
              if (len > maxlen) {
                out += morelink;
                maxlen = html.length * 2;
              }
            }
            out += '\n';
          }
          else
            out += lines[i] + '\n';
        }
        
        if (maxlen > str.length)
          out += '</span>';
        
        html = out;
      }
      
      // simple link parser (similar to rcube_string_replacer class in PHP)
      var utf_domain = '[^?&@"\'/\\(\\)\\s\\r\\t\\n]+\\.([^\x00-\x2f\x3b-\x40\x5b-\x60\x7b-\x7f]{2,}|xn--[a-z0-9]{2,})';
      var url1 = '.:;,', url2 = 'a-z0-9%=#@+?&/_~\\[\\]-';
      var link_pattern = new RegExp('([hf]t+ps?://)('+utf_domain+'(['+url1+']?['+url2+']+)*)?', 'ig');
      var mailto_pattern = new RegExp('([^\\s\\n\\(\\);]+@'+utf_domain+')', 'ig');

      return html
        .replace(link_pattern, '<a href="$1$2" target="_blank">$1$2</a>')
        .replace(mailto_pattern, '<a href="mailto:$1">$1</a>')
        .replace(/(mailto:)([^"]+)"/g, '$1$2" onclick="rcmail.command(\'compose\', \'$2\');return false"')
        .replace(/\n/g, "<br/>");
    };
    
    // same as str.split(delimiter) but it ignores delimiters within quoted strings
    var explode_quoted_string = function(str, delimiter)
    {
      var result = [],
        strlen = str.length,
        q, p, i, char, last;

      for (q = p = i = 0; i < strlen; i++) {
        char = str.charAt(i);
        if (char == '"' && last != '\\') {
          q = !q;
        }
        else if (!q && char == delimiter) {
          result.push(str.substring(p, i));
          p = i + 1;
        }
        last = char;
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

    // clone the given date object and optionally adjust time
    var clone_date = function(date, adjust)
    {
      var d = new Date(date.getTime());
      
      // set time to 00:00
      if (adjust == 1) {
        d.setHours(0);
        d.setMinutes(0);
      }
      // set time to 23:59
      else if (adjust == 2) {
        d.setHours(23);
        d.setMinutes(59);
      }
      
      return d;
    };

    // convert the given Date object into a unix timestamp respecting browser's and user's timezone settings
    var date2unixtime = function(date)
    {
      var dst_offset = (client_timezone - date.getTimezoneOffset()) * 60;  // adjust DST offset
      return Math.round(date.getTime()/1000 + gmt_offset * 3600 + dst_offset);
    };
    
    var fromunixtime = function(ts)
    {
      ts -= gmt_offset * 3600;
      var date = new Date(ts * 1000),
        dst_offset = (client_timezone - date.getTimezoneOffset()) * 60;
      if (dst_offset)  // adjust DST offset
        date.setTime((ts + 3600) * 1000);
      return date;
    };
    
    // determine whether the given date is on a weekend
    var is_weekend = function(date)
    {
      return date.getDay() == 0 || date.getDay() == 6;
    };

    var is_workinghour = function(date)
    {
      if (settings['work_start'] > settings['work_end'])
        return date.getHours() >= settings['work_start'] || date.getHours() < settings['work_end'];
      else
        return date.getHours() >= settings['work_start'] && date.getHours() < settings['work_end'];
    };
    
    // check if the event has 'real' attendees, excluding the current user
    var has_attendees = function(event)
    {
      return (event.attendees && (event.attendees.length > 1 || event.attendees[0].email != settings.identity.email));
    };
    
    // check if the current user is an attendee of this event
    var is_attendee = function(event, role)
    {
      for (var i=0; event.attendees && i < event.attendees.length; i++) {
        if ((!role || event.attendees[i].role == role) && event.attendees[i].email && settings.identity.emails.indexOf(';'+event.attendees[i].email) >= 0)
          return true;
      }
      return false;
    };
    
    // check if the current user is the organizer
    var is_organizer = function(event)
    {
      return is_attendee(event, 'ORGANIZER') || !event.id;
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
      var $dialog = $("#eventshow").dialog('close').removeClass().addClass('uidialog');
      var calendar = event.calendar && me.calendars[event.calendar] ? me.calendars[event.calendar] : { editable:false };
      me.selected_event = event;
      
      $dialog.find('div.event-section, div.event-line').hide();
      $('#event-title').html(Q(event.title)).show();
      
      if (event.location)
        $('#event-location').html('@ ' + text2html(event.location)).show();
      if (event.description)
        $('#event-description').show().children('.event-text').html(text2html(event.description, 300, 6));
      
      // render from-to in a nice human-readable way
      $('#event-date').html(Q(me.event_date_text(event))).show();
      
      if (event.recurrence && event.recurrence_text)
        $('#event-repeat').show().children('.event-text').html(Q(event.recurrence_text));
      
      if (event.alarms && event.alarms_text)
        $('#event-alarm').show().children('.event-text').html(Q(event.alarms_text));
      
      if (calendar.name)
        $('#event-calendar').show().children('.event-text').html(Q(calendar.name)).removeClass().addClass('event-text').addClass('cal-'+calendar.id);
      if (event.categories)
        $('#event-category').show().children('.event-text').html(Q(event.categories)).removeClass().addClass('event-text cat-'+String(event.categories).replace(rcmail.identifier_expr, ''));
      if (event.free_busy)
        $('#event-free-busy').show().children('.event-text').html(Q(rcmail.gettext(event.free_busy, 'calendar')));
      if (event.priority > 0) {
        var priolabels = [ '', rcmail.gettext('high'), rcmail.gettext('highest'), '', '', rcmail.gettext('normal'), '', '', rcmail.gettext('low'), rcmail.gettext('lowest') ];
        $('#event-priority').show().children('.event-text').html(Q(event.priority+' '+priolabels[event.priority]));
      }
      if (event.sensitivity != 0) {
        var sensitivityclasses = { 0:'public', 1:'private', 2:'confidential' };
        $('#event-sensitivity').show().children('.event-text').html(Q(sensitivitylabels[event.sensitivity]));
        $dialog.addClass('sensitivity-'+sensitivityclasses[event.sensitivity]);
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
        var data, dispname, organizer = false, rsvp = false, html = '';
        for (var j=0; j < event.attendees.length; j++) {
          data = event.attendees[j];
          dispname = Q(data.name || data.email);
          if (data.email) {
            dispname = '<a href="mailto:' + data.email + '" title="' + Q(data.email) + '" class="mailtolink">' + dispname + '</a>';
            if (data.role == 'ORGANIZER')
              organizer = true;
            else if ((data.status == 'NEEDS-ACTION' || data.status == 'TENTATIVE') && settings.identity.emails.indexOf(';'+data.email) >= 0)
              rsvp = data.status.toLowerCase();
          }
          html += '<span class="attendee ' + String(data.role == 'ORGANIZER' ? 'organizer' : data.status).toLowerCase() + '">' + dispname + '</span> ';
          
          // stop listing attendees
          if (j == 7 && event.attendees.length >= 7) {
            html += ' <em>' + rcmail.gettext('andnmore', 'calendar').replace('$nr', event.attendees.length - j - 1) + '</em>';
            break;
          }
        }
        
        if (html && (event.attendees.length > 1 || !organizer)) {
          $('#event-attendees').show()
            .children('.event-text')
            .html(html)
            .find('a.mailtolink').click(function(e) { rcmail.redirect(rcmail.url('mail/compose', { _to:this.href.substr(7) })); return false; });
        }
        
        $('#event-rsvp')[(rsvp?'show':'hide')]();
        $('#event-rsvp .rsvp-buttons input').prop('disabled', false).filter('input[rel='+rsvp+']').prop('disabled', true);
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
        resizable: !bw.ie6,
        closeOnEscape: (!bw.ie6 && !bw.ie7),  // disable for performance reasons
        title: null,
        close: function() {
          $dialog.dialog('destroy').hide();
        },
        buttons: buttons,
        minWidth: 320,
        width: 420
      }).show();
      
      // set dialog size according to content
      me.dialog_resize($dialog.get(0), $dialog.height(), 420);
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
      
      var $dialog = $('<div>');
      var calendar = event.calendar && me.calendars[event.calendar] ? me.calendars[event.calendar] : { editable:action=='new' };
      me.selected_event = $.extend($.extend({}, event_defaults), event);  // clone event object (with defaults)
      event = me.selected_event; // change reference to clone
      freebusy_ui.needsupdate = false;

      // reset dialog first
      $('#eventtabs').get(0).reset();

      // event details
      var title = $('#edit-title').val(event.title || '');
      var location = $('#edit-location').val(event.location || '');
      var description = $('#edit-description').html(event.description || '');
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
      var notify = $('#edit-attendees-donotify').get(0);
      var invite = $('#edit-attendees-invite').get(0);
      notify.checked = has_attendees(event), invite.checked = true;
      
      if (event.allDay) {
        starttime.val("12:00").hide();
        endtime.val("13:00").hide();
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

      // check categories drop-down: add value if not exists
      if (event.categories && !categories.find("option[value='"+event.categories+"']").length) {
        $('<option>').attr('value', event.categories).text(event.categories).appendTo(categories).prop('selected', true);
      }

      // set recurrence form
      var recurrence, interval, rrtimes, rrenddate;
      var load_recurrence_tab = function()
      {
        recurrence = $('#edit-recurrence-frequency').val(event.recurrence ? event.recurrence.FREQ : '').change();
        interval = $('select.edit-recurrence-interval').val(event.recurrence ? event.recurrence.INTERVAL : 1);
        rrtimes = $('#edit-recurrence-repeat-times').val(event.recurrence ? event.recurrence.COUNT : 1);
        rrenddate = $('#edit-recurrence-enddate').val(event.recurrence && event.recurrence.UNTIL ? $.fullCalendar.formatDate(new Date(event.recurrence.UNTIL*1000), settings['date_format']) : '');
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
      };
      
      // show warning if editing a recurring event
      if (event.id && event.recurrence) {
        $('#edit-recurring-warning').show();
        $('input.edit-recurring-savemode[value="all"]').prop('checked', true);
      }
      else
        $('#edit-recurring-warning').hide();

      // init attendees tab
      var organizer = !event.attendees || is_organizer(event);
      event_attendees = [];
      attendees_list = $('#edit-attendees-table > tbody').html('');
      $('#edit-attendees-notify')[(notify.checked && organizer ? 'show' : 'hide')]();
      $('#edit-localchanges-warning')[(has_attendees(event) && !organizer ? 'show' : 'hide')]();

      var load_attendees_tab = function()
      {
        if (event.attendees) {
          for (var j=0; j < event.attendees.length; j++)
            add_attendee(event.attendees[j], !organizer);
        }

        $('#edit-attendees-form')[(organizer?'show':'hide')]();
        $('#edit-attendee-schedule')[(calendar.freebusy?'show':'hide')]();
      };

      // attachments
      var load_attachments_tab = function()
      {
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
      };
      
      
      // init dialog buttons
      var buttons = {};
      
      buttons[rcmail.gettext('save', 'calendar')] = function() {
        var start = parse_datetime(allday.checked ? '12:00' : starttime.val(), startdate.val());
        var end   = parse_datetime(allday.checked ? '13:00' : endtime.val(), enddate.val());
        
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
        
        // don't submit attendees if only myself is added as organizer
        if (data.attendees.length == 1 && data.attendees[0].role == 'ORGANIZER' && data.attendees[0].email == settings.identity.email)
          data.attendees = [];
        
        // tell server to send notifications
        if (data.attendees.length && organizer && ((event.id && notify.checked) || (!event.id && invite.checked))) {
          data._notify = 1;
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

        data.calendar = calendars.val();

        if (event.id) {
          data.id = event.id;
          if (event.recurrence)
            data._savemode = $('input.edit-recurring-savemode:checked').val();
          if (data.calendar && data.calendar != event.calendar)
            data._fromcalendar = event.calendar;
        }

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
      
      // hack: set task to 'calendar' to make all dialog actions work correctly
      var comm_path_before = rcmail.env.comm_path;
      rcmail.env.comm_path = comm_path_before.replace(/_task=[a-z]+/, '_task=calendar');

      var editform = $("#eventedit");

      // open jquery UI dialog
      $dialog.dialog({
        modal: true,
        resizable: (!bw.ie6 && !bw.ie7),  // disable for performance reasons
        closeOnEscape: false,
        title: rcmail.gettext((action == 'edit' ? 'edit_event' : 'new_event'), 'calendar'),
        close: function() {
          editform.hide().appendTo(document.body);
          $dialog.dialog("destroy").remove();
          rcmail.ksearch_blur();
          rcmail.ksearch_destroy();
          freebusy_data = {};
          rcmail.env.comm_path = comm_path_before;  // restore comm_path
        },
        buttons: buttons,
        minWidth: 500,
        width: 580
      }).append(editform.show());  // adding form content AFTERWARDS massively speeds up opening on IE6

      // set dialog size according to form content
      me.dialog_resize($dialog.get(0), editform.height() + (bw.ie ? 20 : 0), 530);

      title.select();

      // init other tabs asynchronously
      window.setTimeout(load_recurrence_tab, exec_deferred);
      if (calendar.attendees)
        window.setTimeout(load_attendees_tab, exec_deferred);
      if (calendar.attachments)
        window.setTimeout(load_attachments_tab, exec_deferred);
    };

    // open a dialog to display detailed free-busy information and to find free slots
    var event_freebusy_dialog = function()
    {
      var $dialog = $('#eventfreebusy').dialog('close');
      var event = me.selected_event;
      
      if (!event_attendees.length)
        return false;
      
      // set form elements
      var allday = $('#edit-allday').get(0);
      var duration = Math.round((event.end.getTime() - event.start.getTime()) / 1000);
      freebusy_ui.startdate = $('#schedule-startdate').val($.fullCalendar.formatDate(event.start, settings['date_format'])).data('duration', duration);
      freebusy_ui.starttime = $('#schedule-starttime').val($.fullCalendar.formatDate(event.start, settings['time_format'])).show();
      freebusy_ui.enddate = $('#schedule-enddate').val($.fullCalendar.formatDate(event.end, settings['date_format']));
      freebusy_ui.endtime = $('#schedule-endtime').val($.fullCalendar.formatDate(event.end, settings['time_format'])).show();
      
      if (allday.checked) {
        freebusy_ui.starttime.val("12:00").hide();
        freebusy_ui.endtime.val("13:00").hide();
        event.allDay = true;
      }
      
      // read attendee roles from drop-downs
      $('select.edit-attendee-role').each(function(i, elem){
        if (event_attendees[i])
          event_attendees[i].role = $(elem).val();
      });
      
      // render time slots
      var now = new Date(), fb_start = new Date(), fb_end = new Date();
      fb_start.setTime(event.start);
      fb_start.setHours(0); fb_start.setMinutes(0); fb_start.setSeconds(0); fb_start.setMilliseconds(0);
      fb_end.setTime(fb_start.getTime() + DAY_MS);
      
      freebusy_data = { required:{}, all:{} };
      freebusy_ui.loading = 1;  // prevent render_freebusy_grid() to load data yet
      freebusy_ui.numdays = Math.max(allday.checked ? 14 : 1, Math.ceil(duration * 2 / 86400));
      freebusy_ui.interval = allday.checked ? 1440 : 60;
      freebusy_ui.start = fb_start;
      freebusy_ui.end = new Date(freebusy_ui.start.getTime() + DAY_MS * freebusy_ui.numdays);
      render_freebusy_grid(0);
      
      // render list of attendees
      freebusy_ui.attendees = {};
      var domid, dispname, data, role_html, list_html = '';
      for (var i=0; i < event_attendees.length; i++) {
        data = event_attendees[i];
        dispname = Q(data.name || data.email);
        domid = String(data.email).replace(rcmail.identifier_expr, '');
        role_html = '<a class="attendee-role-toggle" id="rcmlia' + domid + '" title="' + Q(rcmail.gettext('togglerole', 'calendar')) + '">&nbsp;</a>';
        list_html += '<div class="attendee ' + String(data.role).toLowerCase() + '" id="rcmli' + domid + '">' + role_html + dispname + '</div>';
        
        // clone attendees data for local modifications
        freebusy_ui.attendees[i] = freebusy_ui.attendees[domid] = $.extend({}, data);
      }
      
      // add total row
      list_html += '<div class="attendee spacer">&nbsp;</div>';
      list_html += '<div class="attendee total">' + rcmail.gettext('reqallattendees','calendar') + '</div>';
      
      $('#schedule-attendees-list').html(list_html)
        .unbind('click.roleicons')
        .bind('click.roleicons', function(e){
          // toggle attendee status upon click on icon
          if (e.target.id && e.target.id.match(/rcmlia(.+)/)) {
            var attendee, domid = RegExp.$1, roles = [ 'REQ-PARTICIPANT', 'OPT-PARTICIPANT', 'CHAIR' ];
            if ((attendee = freebusy_ui.attendees[domid]) && attendee.role != 'ORGANIZER') {
              var req = attendee.role != 'OPT-PARTICIPANT';
              var j = $.inArray(attendee.role, roles);
              j = (j+1) % roles.length;
              attendee.role = roles[j];
              $(e.target).parent().removeClass().addClass('attendee '+String(attendee.role).toLowerCase());
              
              // update total display if required-status changed
              if (req != (roles[j] != 'OPT-PARTICIPANT')) {
                compute_freebusy_totals();
                update_freebusy_display(attendee.email);
              }
            }
          }
          
          return false;
        });
      
      // enable/disable buttons
      $('#shedule-find-prev').button('option', 'disabled', (fb_start.getTime() < now.getTime()));
      
      // dialog buttons
      var buttons = {};
      
      buttons[rcmail.gettext('select', 'calendar')] = function() {
        $('#edit-startdate').val(freebusy_ui.startdate.val());
        $('#edit-starttime').val(freebusy_ui.starttime.val());
        $('#edit-enddate').val(freebusy_ui.enddate.val());
        $('#edit-endtime').val(freebusy_ui.endtime.val());
        
        // write role changes back to main dialog
        $('select.edit-attendee-role').each(function(i, elem){
          if (event_attendees[i] && freebusy_ui.attendees[i]) {
            event_attendees[i].role = freebusy_ui.attendees[i].role;
            $(elem).val(event_attendees[i].role);
          }
        });
        
        if (freebusy_ui.needsupdate)
          update_freebusy_status(me.selected_event);
        freebusy_ui.needsupdate = false;
        $dialog.dialog("close");
      };
      
      buttons[rcmail.gettext('cancel', 'calendar')] = function() {
        $dialog.dialog("close");
      };
      
      $dialog.dialog({
        modal: true,
        resizable: true,
        closeOnEscape: (!bw.ie6 && !bw.ie7),
        title: rcmail.gettext('scheduletime', 'calendar'),
        close: function() {
          if (bw.ie6)
            $("#edit-attendees-table").css('visibility','visible');
          $dialog.dialog("destroy").hide();
        },
        resizeStop: function() {
          render_freebusy_overlay();
        },
        buttons: buttons,
        minWidth: 640,
        width: 850
      }).show();
      
      // hide edit dialog on IE6 because of drop-down elements
      if (bw.ie6)
        $("#edit-attendees-table").css('visibility','hidden');
      
      // adjust dialog size to fit grid without scrolling
      var gridw = $('#schedule-freebusy-times').width();
      var overflow = gridw - $('#attendees-freebusy-table td.times').width() + 1;
      me.dialog_resize($dialog.get(0), $dialog.height() + (bw.ie ? 20 : 0), 800 + Math.max(0, overflow));
      
      // fetch data from server
      freebusy_ui.loading = 0;
      load_freebusy_data(freebusy_ui.start, freebusy_ui.interval);
    };

    // render an HTML table showing free-busy status for all the event attendees
    var render_freebusy_grid = function(delta)
    {
      if (delta) {
        freebusy_ui.start.setTime(freebusy_ui.start.getTime() + DAY_MS * delta);
        // skip weekends if in workinhoursonly-mode
        if (Math.abs(delta) == 1 && freebusy_ui.workinhoursonly) {
          while (is_weekend(freebusy_ui.start))
            freebusy_ui.start.setTime(freebusy_ui.start.getTime() + DAY_MS * delta);
        }
        
        freebusy_ui.end = new Date(freebusy_ui.start.getTime() + DAY_MS * freebusy_ui.numdays);
      }
      
      var dayslots = Math.floor(1440 / freebusy_ui.interval);
      var date_format = 'ddd '+ (dayslots <= 2 ? settings.date_short : settings.date_format);
      var lastdate, datestr, css,
        curdate = new Date(),
        allday = (freebusy_ui.interval == 1440),
        times_css = (allday ? 'allday ' : ''),
        dates_row = '<tr class="dates">',
        times_row = '<tr class="times">',
        slots_row = '';
      for (var s = 0, t = freebusy_ui.start.getTime(); t < freebusy_ui.end.getTime(); s++) {
        curdate.setTime(t);
        datestr = fc.fullCalendar('formatDate', curdate, date_format);
        if (datestr != lastdate) {
          dates_row += '<th colspan="' + dayslots + '" class="boxtitle date' + $.fullCalendar.formatDate(curdate, 'ddMMyyyy') + '">' + Q(datestr) + '</th>';
          lastdate = datestr;
        }
        
        // set css class according to working hours
        css = is_weekend(curdate) || (freebusy_ui.interval <= 60 && !is_workinghour(curdate)) ? 'offhours' : 'workinghours';
        times_row += '<td class="' + times_css + css + '" id="t-' + Math.floor(t/1000) + '">' + Q(allday ? rcmail.gettext('all-day','calendar') : $.fullCalendar.formatDate(curdate, settings['time_format'])) + '</td>';
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
      
      // add line for all/required attendees
      times_html += '<tr class="spacer"><td colspan="' + (dayslots * freebusy_ui.numdays) + '">&nbsp;</td>';
      times_html += '<tr id="fbrowall">' + slots_row + '</tr>';
      
      var table = $('#schedule-freebusy-times');
      table.children('thead').html(dates_row + times_row);
      table.children('tbody').html(times_html);
      
      // initialize event handlers on grid
      if (!freebusy_ui.grid_events) {
        freebusy_ui.grid_events = true;
        table.children('thead').click(function(e){
          // move event to the clicked date/time
          if (e.target.id && e.target.id.match(/t-(\d+)/)) {
            var newstart = new Date(RegExp.$1 * 1000);
            // set time to 00:00
            if (me.selected_event.allDay) {
              newstart.setMinutes(0);
              newstart.setHours(0);
            }
            update_freebusy_dates(newstart, new Date(newstart.getTime() + freebusy_ui.startdate.data('duration') * 1000));
            render_freebusy_overlay();
          }
        })
      }
      
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
      if (me.selected_event.end.getTime() <= freebusy_ui.start.getTime() || me.selected_event.start.getTime() >= freebusy_ui.end.getTime()) {
        overlay.draggable('disable').hide();
      }
      else {
        var table = $('#schedule-freebusy-times'),
          width = 0,
          pos = { top:table.children('thead').height(), left:0 },
          eventstart = date2unixtime(clone_date(me.selected_event.start, me.selected_event.allDay?1:0)),
          eventend = date2unixtime(clone_date(me.selected_event.end, me.selected_event.allDay?2:0)) - 60,
          slotstart = date2unixtime(freebusy_ui.start),
          slotsize = freebusy_ui.interval * 60,
          slotend, fraction, $cell;
        
        // iterate through slots to determine position and size of the overlay
        table.children('thead').find('td').each(function(i, cell){
          slotend = slotstart + slotsize - 1;
          // event starts in this slot: compute left
          if (eventstart >= slotstart && eventstart <= slotend) {
            fraction = 1 - (slotend - eventstart) / slotsize;
            pos.left = Math.round(cell.offsetLeft + cell.offsetWidth * fraction);
          }
          // event ends in this slot: compute width
          if (eventend >= slotstart && eventend <= slotend) {
            fraction = 1 - (slotend - eventend) / slotsize;
            width = Math.round(cell.offsetLeft + cell.offsetWidth * fraction) - pos.left;
          }

          slotstart = slotstart + slotsize;
        });

        if (!width)
          width = table.width() - pos.left;

        // overlay is visible
        if (width > 0) {
          overlay.css({ width: (width-5)+'px', height:(table.children('tbody').height() - 4)+'px', left:pos.left+'px', top:pos.top+'px' }).draggable('enable').show();
          
          // configure draggable
          if (!overlay.data('isdraggable')) {
            overlay.draggable({
              axis: 'x',
              scroll: true,
              stop: function(e, ui){
                // convert pixels to time
                var px = ui.position.left;
                var range_p = $('#schedule-freebusy-times').width();
                var range_t = freebusy_ui.end.getTime() - freebusy_ui.start.getTime();
                var newstart = new Date(freebusy_ui.start.getTime() + px * (range_t / range_p));
                newstart.setSeconds(0); newstart.setMilliseconds(0);
                // snap to day boundaries
                if (me.selected_event.allDay) {
                  if (newstart.getHours() >= 12)  // snap to next day
                    newstart.setTime(newstart.getTime() + DAY_MS);
                  newstart.setMinutes(0);
                  newstart.setHours(0);
                }
                else {
                  // round to 5 minutes
                  var round = newstart.getMinutes() % 5;
                  if (round > 2.5) newstart.setTime(newstart.getTime() + (5 - round) * 60000);
                  else if (round > 0) newstart.setTime(newstart.getTime() - round * 60000);
                }
                // update event times and display
                update_freebusy_dates(newstart, new Date(newstart.getTime() + freebusy_ui.startdate.data('duration') * 1000));
                if (me.selected_event.allDay)
                  render_freebusy_overlay();
              }
            }).data('isdraggable', true);
          }
        }
        else
          overlay.draggable('disable').hide();
      }
      
    };
    
    
    // fetch free-busy information for each attendee from server
    var load_freebusy_data = function(from, interval)
    {
      var start = new Date(from.getTime() - DAY_MS * 2);  // start 1 days before event
      var end = new Date(start.getTime() + DAY_MS * Math.max(14, freebusy_ui.numdays + 7));   // load min. 14 days
      freebusy_ui.numrequired = 0;
      freebusy_data.all = [];
      freebusy_data.required = [];
      
      // load free-busy information for every attendee
      var domid, email;
      for (var i=0; i < event_attendees.length; i++) {
        if ((email = event_attendees[i].email)) {
          domid = String(email).replace(rcmail.identifier_expr, '');
          $('#rcmli' + domid).addClass('loading');
          freebusy_ui.loading++;
          
          $.ajax({
            type: 'GET',
            dataType: 'json',
            url: rcmail.url('freebusy-times'),
            data: { email:email, start:date2unixtime(clone_date(start, 1)), end:date2unixtime(clone_date(end, 2)), interval:interval, _remote:1 },
            success: function(data) {
              freebusy_ui.loading--;
              
              // find attendee 
              var attendee = null;
              for (var i=0; i < event_attendees.length; i++) {
                if (freebusy_ui.attendees[i].email == data.email) {
                  attendee = freebusy_ui.attendees[i];
                  break;
                }
              }
              
              // copy data to member var
              var req = attendee.role != 'OPT-PARTICIPANT';
              var ts = data.start - 0;
              freebusy_data.start = ts;
              freebusy_data[data.email] = {};
              for (var i=0; i < data.slots.length; i++) {
                freebusy_data[data.email][ts] = data.slots[i];
                
                // set totals
                if (!freebusy_data.required[ts])
                  freebusy_data.required[ts] = [0,0,0,0];
                if (req)
                  freebusy_data.required[ts][data.slots[i]]++;
                
                if (!freebusy_data.all[ts])
                  freebusy_data.all[ts] = [0,0,0,0];
                freebusy_data.all[ts][data.slots[i]]++;
                
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
          
          // count required attendees
          if (freebusy_ui.attendees[i].role != 'OPT-PARTICIPANT')
            freebusy_ui.numrequired++;
        }
      }
    };
    
    // re-calculate total status after role change
    var compute_freebusy_totals = function()
    {
      freebusy_ui.numrequired = 0;
      freebusy_data.all = [];
      freebusy_data.required = [];
      
      var email, req, status;
      for (var i=0; i < event_attendees.length; i++) {
        if (!(email = event_attendees[i].email))
          continue;
        
        req = freebusy_ui.attendees[i].role != 'OPT-PARTICIPANT';
        if (req)
          freebusy_ui.numrequired++;
        
        for (var ts in freebusy_data[email]) {
          if (!freebusy_data.required[ts])
            freebusy_data.required[ts] = [0,0,0,0];
          if (!freebusy_data.all[ts])
            freebusy_data.all[ts] = [0,0,0,0];
          
          status = freebusy_data[email][ts];
          freebusy_data.all[ts][status]++;
          
          if (req)
            freebusy_data.required[ts][status]++;
        }
      }
    };

    // update free-busy grid with status loaded from server
    var update_freebusy_display = function(email)
    {
      var status_classes = ['unknown','free','busy','tentative','out-of-office'];
      var domid = String(email).replace(rcmail.identifier_expr, '');
      var row = $('#fbrow' + domid);
      var rowall = $('#fbrowall').children();
      var ts = date2unixtime(freebusy_ui.start);
      var fbdata = freebusy_data[email];
      
      if (fbdata && fbdata[ts] !== undefined && row.length) {
        row.children().each(function(i, cell){
          cell.className = cell.className.replace('unknown', fbdata[ts] ? status_classes[fbdata[ts]] : 'unknown');
          
          // also update total row if all data was loaded
          if (freebusy_ui.loading == 0 && freebusy_data.all[ts] && (cell = rowall.get(i))) {
            var workinghours = cell.className.indexOf('workinghours') >= 0;
            var all_status = freebusy_data.all[ts][2] ? 'busy' : 'unknown';
              req_status = freebusy_data.required[ts][2] ? 'busy' : 'free';
            for (var j=1; j < status_classes.length; j++) {
              if (freebusy_ui.numrequired && freebusy_data.required[ts][j] >= freebusy_ui.numrequired)
                req_status = status_classes[j];
              if (freebusy_data.all[ts][j] == event_attendees.length)
                all_status = status_classes[j];
            }
            
            cell.className = (workinghours ? 'workinghours ' : 'offhours ') + req_status +  ' all-' + all_status;
          }
          
          ts += freebusy_ui.interval * 60;
        });
      }
    };
    
    // write changed event date/times back to form fields
    var update_freebusy_dates = function(start, end)
    {
      if (me.selected_event.allDay) {
        start.setHours(12);
        start.setMinutes(0);
        end.setHours(13);
        end.setMinutes(0);
      }
      me.selected_event.start = start;
      me.selected_event.end = end;
      freebusy_ui.startdate.val($.fullCalendar.formatDate(start, settings['date_format']));
      freebusy_ui.starttime.val($.fullCalendar.formatDate(start, settings['time_format']));
      freebusy_ui.enddate.val($.fullCalendar.formatDate(end, settings['date_format']));
      freebusy_ui.endtime.val($.fullCalendar.formatDate(end, settings['time_format']));
      freebusy_ui.needsupdate = true;
    };

    // attempt to find a time slot where all attemdees are available
    var freebusy_find_slot = function(dir)
    {
      var event = me.selected_event,
        eventstart = date2unixtime(event.start),  // calculate with unixtimes
        eventend = date2unixtime(event.end),
        duration = eventend - eventstart,
        sinterval = freebusy_data.interval * 60,
        intvlslots = 1,
        numslots = Math.ceil(duration / sinterval),
        checkdate, slotend, email, curdate;

      // shift event times to next possible slot
      eventstart += sinterval * intvlslots * dir;
      eventend += sinterval * intvlslots * dir;

      // iterate through free-busy slots and find candidates
      var candidatecount = 0, candidatestart = candidateend = success = false;
      for (var slot = dir > 0 ? freebusy_data.start : freebusy_data.end - sinterval; (dir > 0 && slot < freebusy_data.end) || (dir < 0 && slot >= freebusy_data.start); slot += sinterval * dir) {
        slotend = slot + sinterval;
        if ((dir > 0 && slotend <= eventstart) || (dir < 0 && slot >= eventend))  // skip
          continue;
        
        // respect workingours setting
        if (freebusy_ui.workinhoursonly) {
          curdate = fromunixtime(dir > 0 || !candidateend ? slot : (candidateend - duration));
          if (is_weekend(curdate) || (freebusy_data.interval <= 60 && !is_workinghour(curdate))) {  // skip off-hours
            candidatestart = candidateend = false;
            candidatecount = 0;
            continue;
          }
        }
        
        if (!candidatestart)
          candidatestart = slot;
        
        // check freebusy data for all attendees
        for (var i=0; i < event_attendees.length; i++) {
          if (freebusy_ui.attendees[i].role != 'OPT-PARTICIPANT' && (email = freebusy_ui.attendees[i].email) && freebusy_data[email] && freebusy_data[email][slot] > 1) {
            candidatestart = candidateend = false;
            break;
          }
        }
        
        // occupied slot
        if (!candidatestart) {
          slot += Math.max(0, intvlslots - candidatecount - 1) * sinterval * dir;
          candidatecount = 0;
          continue;
        }
        
        // set candidate end to slot end time
        candidatecount++;
        if (dir < 0 && !candidateend)
          candidateend = slotend;
        
        // if candidate is big enough, this is it!
        if (candidatecount == numslots) {
          if (dir > 0) {
            event.start = fromunixtime(candidatestart);
            event.end = fromunixtime(candidatestart + duration);
          }
          else {
            event.end = fromunixtime(candidateend);
            event.start = fromunixtime(candidateend - duration);
          }
          success = true;
          break;
        }
      }
      
      // update event date/time display
      if (success) {
        update_freebusy_dates(event.start, event.end);
        
        // move freebusy grid if necessary
        var offset = Math.ceil((event.start.getTime() - freebusy_ui.end.getTime()) / DAY_MS);
        if (event.start.getTime() >= freebusy_ui.end.getTime())
          render_freebusy_grid(Math.max(1, offset));
        else if (event.end.getTime() <= freebusy_ui.start.getTime())
          render_freebusy_grid(Math.min(-1, offset));
        else
          render_freebusy_overlay();
        
        var now = new Date();
        $('#shedule-find-prev').button('option', 'disabled', (event.start.getTime() < now.getTime()));
      }
      else {
        alert(rcmail.gettext('noslotfound','calendar'));
      }
    };


    // update event properties and attendees availability if event times have changed
    var event_times_changed = function()
    {
      if (me.selected_event) {
        var allday = $('#edit-allday').get(0);
        me.selected_event.allDay = allday.checked;
        me.selected_event.start = parse_datetime(allday.checked ? '12:00' : $('#edit-starttime').val(), $('#edit-startdate').val());
        me.selected_event.end   = parse_datetime(allday.checked ? '13:00' : $('#edit-endtime').val(), $('#edit-enddate').val());
        if (event_attendees)
          freebusy_ui.needsupdate = true;
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
        email = name = '';
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
    var add_attendee = function(data, readonly)
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
      var organizer = data.role == 'ORGANIZER';
      var opts = {};
      if (organizer)
        opts.ORGANIZER = rcmail.gettext('calendar.roleorganizer');
      opts['REQ-PARTICIPANT'] = rcmail.gettext('calendar.rolerequired');
      opts['OPT-PARTICIPANT'] = rcmail.gettext('calendar.roleoptional');
      opts['CHAIR'] =  rcmail.gettext('calendar.roleresource');
      
      var select = '<select class="edit-attendee-role"' + (organizer || readonly ? ' disabled="true"' : '') + '>';
      for (var r in opts)
        select += '<option value="'+ r +'" class="' + r.toLowerCase() + '"' + (data.role == r ? ' selected="selected"' : '') +'>' + Q(opts[r]) + '</option>';
      select += '</select>';
      
      // availability
      var avail = data.email ? 'loading' : 'unknown';

      // delete icon
      var icon = rcmail.env.deleteicon ? '<img src="' + rcmail.env.deleteicon + '" alt="" />' : rcmail.gettext('delete');
      var dellink = '<a href="#delete" class="deletelink" title="' + Q(rcmail.gettext('delete')) + '">' + icon + '</a>';
      
      var html = '<td class="role">' + select + '</td>' +
        '<td class="name">' + dispname + '</td>' +
        '<td class="availability"><img src="./program/blank.gif" class="availabilityicon ' + avail + '" /></td>' +
        '<td class="confirmstate"><span class="' + String(data.status).toLowerCase() + '">' + Q(data.status) + '</span></td>' +
        '<td class="options">' + (organizer || readonly ? '' : dellink) + '</td>';
      
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
        if (icons.get(i) && event_attendees[i].email)
          check_freebusy_status(icons.get(i), event_attendees[i].email, event);
      }
      
      freebusy_ui.needsupdate = false;
    };
    
    // load free-busy status from server and update icon accordingly
    var check_freebusy_status = function(icon, email, event)
    {
      var calendar = event.calendar && me.calendars[event.calendar] ? me.calendars[event.calendar] : { freebusy:false };
      if (!calendar.freebusy) {
        $(icon).removeClass().addClass('availabilityicon unknown');
        return;
      }
      
      icon = $(icon).removeClass().addClass('availabilityicon loading');
      
      $.ajax({
        type: 'GET',
        dataType: 'html',
        url: rcmail.url('freebusy-status'),
        data: { email:email, start:date2unixtime(clone_date(event.start, event.allDay?1:0)), end:date2unixtime(clone_date(event.end, event.allDay?2:0)), _remote: 1 },
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
    
    // when the user accepts or declines an event invitation
    var event_rsvp = function(response)
    {
      if (me.selected_event && me.selected_event.attendees && response) {
        // update attendee status
        for (var data, i=0; i < me.selected_event.attendees.length; i++) {
          data = me.selected_event.attendees[i];
          if (settings.identity.emails.indexOf(';'+data.email) >= 0)
            data.status = response.toUpperCase();
        }
        event_show_dialog(me.selected_event);
        
        // submit status change to server
        me.saving_lock = rcmail.set_busy(true, 'calendar.savingdata');
        rcmail.http_post('event', { action:'rsvp', e:me.selected_event, status:response });
      }
    }
    
    // post the given event data to server
    var update_event = function(action, data)
    {
      me.saving_lock = rcmail.set_busy(true, 'calendar.savingdata');
      rcmail.http_post('calendar/event', { action:action, e:data });
      
      // render event temporarily into the calendar
      if ((data.start && data.end) || data.id) {
        var event = data.id ? $.extend(fc.fullCalendar('clientEvents', data.id)[0], data) : data;
        if (data.start)
          event.start = fromunixtime(data.start);
        if (data.end)
          event.end = fromunixtime(data.end);
        if (data.allday !== undefined)
          event.allDay = data.allday;
        event.editable = false;
        event.temp = true;
        event.className = 'fc-event-cal-'+data.calendar+' fc-event-temp';
        fc.fullCalendar(data.id ? 'updateEvent' : 'renderEvent', event);
      }
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

    // display confirm dialog when modifying/deleting an event
    var update_event_confirm = function(action, event, data)
    {
      if (!data) data = event;
      var decline = false, notify = false, html = '', cal = me.calendars[event.calendar];
      
      // event has attendees, ask whether to notify them
      if (has_attendees(event)) {
        if (is_organizer(event)) {
          notify = true;
          html += '<div class="message">' +
            '<label><input class="confirm-attendees-donotify" type="checkbox" checked="checked" value="1" name="notify" />&nbsp;' +
            rcmail.gettext((action == 'remove' ? 'sendcancellation' : 'sendnotifications'), 'calendar') + 
            '</label></div>';
        }
        else if (action == 'remove' && is_attendee(event)) {
          decline = true;
          html += '<div class="message">' +
            '<label><input class="confirm-attendees-decline" type="checkbox" checked="checked" value="1" name="decline" />&nbsp;' +
            rcmail.gettext('itipdeclineevent', 'calendar') + 
            '</label></div>';
        }
        else {
          html += '<div class="message">' + rcmail.gettext('localchangeswarning', 'calendar') + '</div>';
        }
      }
      
      // recurring event: user needs to select the savemode
      if (event.recurrence) {
        html += '<div class="message"><span class="ui-icon ui-icon-alert"></span>' +
          rcmail.gettext((action == 'remove' ? 'removerecurringeventwarning' : 'changerecurringeventwarning'), 'calendar') + '</div>' +
          '<div class="savemode">' +
            '<a href="#current" class="button">' + rcmail.gettext('currentevent', 'calendar') + '</a>' +
            '<a href="#future" class="button">' + rcmail.gettext('futurevents', 'calendar') + '</a>' +
            '<a href="#all" class="button">' + rcmail.gettext('allevents', 'calendar') + '</a>' +
            (action != 'remove' ? '<a href="#new" class="button">' + rcmail.gettext('saveasnew', 'calendar') + '</a>' : '') +
          '</div>';
      }
      
      // show dialog
      if (html) {
        var $dialog = $('<div>').html(html);
      
        $dialog.find('a.button').button().click(function(e){
          data._savemode = String(this.href).replace(/.+#/, '');
          if ($dialog.find('input.confirm-attendees-donotify').get(0))
            data._notify = notify && $dialog.find('input.confirm-attendees-donotify').get(0).checked ? 1 : 0;
          if (decline && $dialog.find('input.confirm-attendees-decline:checked'))
            data.decline = 1;
          update_event(action, data);
          $dialog.dialog("destroy").hide();
          return false;
        });
        
        var buttons = [{
          text: rcmail.gettext('cancel', 'calendar'),
          click: function() {
            $(this).dialog("close");
          }
        }];
        
        if (!event.recurrence) {
          buttons.push({
            text: rcmail.gettext((action == 'remove' ? 'remove' : 'save'), 'calendar'),
            click: function() {
              data._notify = notify && $dialog.find('input.confirm-attendees-donotify').get(0).checked ? 1 : 0;
              data.decline = decline && $dialog.find('input.confirm-attendees-decline:checked').length ? 1 : 0;
              update_event(action, data);
              $(this).dialog("close");
            }
          });
        }
      
        $dialog.dialog({
          modal: true,
          width: 460,
          dialogClass: 'warning',
          title: rcmail.gettext((action == 'remove' ? 'removeeventconfirm' : 'changeeventconfirm'), 'calendar'),
          buttons: buttons,
          close: function(){
            $dialog.dialog("destroy").hide();
            if (!rcmail.busy)
              fc.fullCalendar('refetchEvents');
          }
        }).addClass('event-update-confirm').show();
        
        return false;
      }
      // show regular confirm box when deleting
      else if (action == 'remove' && !cal.undelete) {
        if (!confirm(rcmail.gettext('deleteventconfirm', 'calendar')))
          return false;
      }

      // do update
      update_event(action, data);
      
      return true;
    };

    var update_agenda_toolbar = function()
    {
      $('#agenda-listrange').val(fc.fullCalendar('option', 'listRange'));
      $('#agenda-listsections').val(fc.fullCalendar('option', 'listSections'));
    }

    /*** fullcalendar event handlers ***/

    var fc_event_render = function(event, element, view) {
      if (view.name != 'list' && view.name != 'table') {
        var prefix = event.sensitivity != 0 ? String(sensitivitylabels[event.sensitivity]).toUpperCase()+': ' : '';
        element.attr('title', prefix + event.title);
      }
      if (view.name == 'month') {
        if (event_resizing)
          return true;
        else if (view._suppressed[event.id])
          return false;
        
        // limit the number of events displayed
        var sday = event.start.getMonth()*100 + event.start.getDate();
        var eday = event.end ? event.end.getMonth()*100   + event.end.getDate() : sday;
        
        // increase counter for every day
        for (var d = sday; d <= eday; d++) {
          if (!view._eventcount[d]) view._eventcount[d] = 1;
          else                      view._eventcount[d]++;
        }
        
        if (view._eventcount[sday] >= view._maxevents) {
          view._suppressed[event.id] = true;
          
          // register this event to be the last of this day segment
          if (!view._morelink[sday]) {
            view._morelink[sday] = 1;
            view._morelink['e'+event.id] = sday;
          }
          else {
            view._morelink[sday]++;
            return false;  // suppress event
          }
        }
      }
      else {
        if (event.location) {
          element.find('div.fc-event-title').after('<div class="fc-event-location">@&nbsp;' + Q(event.location) + '</div>');
        }
        if (event.sensitivity != 0)
          element.find('div.fc-event-time').append('<i class="fc-icon-sensitive"></i>');
        if (event.recurrence)
          element.find('div.fc-event-time').append('<i class="fc-icon-recurring"></i>');
        if (event.alarms)
          element.find('div.fc-event-time').append('<i class="fc-icon-alarms"></i>');
      }
    };


    /*** public methods ***/

    // opens calendar day-view in a popup
    this.fisheye_view = function(date)
    {
      $('#fish-eye-view').dialog('close');
      
      // create list of active event sources
      var src, cals = {}, sources = [];
      for (var id in this.calendars) {
        src = $.extend({}, this.calendars[id]);
        src.editable = false;
        src.url = null;
        src.events = [];

        if (src.active) {
          cals[id] = src;
          sources.push(src);
        }
      }
      
      // copy events already loaded
      var events = fc.fullCalendar('clientEvents');
      for (var event, i=0; i< events.length; i++) {
        event = events[i];
        if (event.source && (src = cals[event.source.id])) {
          src.events.push(event);
        }
      }
      
      var h = $(window).height() - 50;
      var dialog = $('<div>')
        .attr('id', 'fish-eye-view')
        .dialog({
          modal: true,
          width: 680,
          height: h,
          title: $.fullCalendar.formatDate(date, 'dddd ' + settings['date_long']),
          close: function(){
            dialog.dialog("destroy");
            me.fisheye_date = null;
          }
        })
        .fullCalendar({
          header: { left: '', center: '', right: '' },
          height: h - 50,
          defaultView: 'agendaDay',
          date: date.getDate(),
          month: date.getMonth(),
          year: date.getFullYear(),
          ignoreTimezone: true,  // will treat the given date strings as in local (browser's) timezone
          eventSources: sources,
          monthNames : settings['months'],
          monthNamesShort : settings['months_short'],
          dayNames : settings['days'],
          dayNamesShort : settings['days_short'],
          firstDay : settings['first_day'],
          firstHour : settings['first_hour'],
          slotMinutes : 60/settings['timeslots'],
          timeFormat: { '': settings['time_format'] },
          axisFormat : settings['time_format'],
          columnFormat: { day: 'dddd ' + settings['date_short'] },
          titleFormat: { day: 'dddd ' + settings['date_long'] },
          allDayText: rcmail.gettext('all-day', 'calendar'),
          currentTimeIndicator: settings.time_indicator,
          eventRender: fc_event_render,
          eventClick: function(event) {
            event_show_dialog(event);
          }
        });
        
        this.fisheye_date = date;
    };

    //public method to show the print dialog.
    this.print_calendars = function(view)
    {
      if (!view) view = fc.fullCalendar('getView').name;
      var date = fc.fullCalendar('getDate') || new Date();
      var range = fc.fullCalendar('option', 'listRange');
      var sections = fc.fullCalendar('option', 'listSections');
      var printwin = window.open(rcmail.url('print', { view: view, date: date2unixtime(date), range: range, sections: sections, search: this.search_query }), "rc_print_calendars", "toolbar=no,location=yes,menubar=yes,resizable=yes,scrollbars=yes,width=800");
      window.setTimeout(function(){ printwin.focus() }, 50);
    };

    // public method to bring up the new event dialog
    this.add_event = function(templ) {
      if (this.selected_calendar) {
        var now = new Date();
        var date = fc.fullCalendar('getDate');
        if (typeof date != 'Date')
          date = now;
        date.setHours(now.getHours()+1);
        date.setMinutes(0);
        var end = new Date(date.getTime());
        end.setHours(date.getHours()+1);
        event_edit_dialog('new', $.extend({ start:date, end:end, allDay:false, calendar:this.selected_calendar }, templ || {}));
      }
    };

    // delete the given event after showing a confirmation dialog
    this.delete_event = function(event) {
      // show confirm dialog for recurring events, use jquery UI dialog
      return update_event_confirm('remove', event, { id:event.id, calendar:event.calendar, attendees:event.attendees });
    };
    
    // opens a jquery UI dialog with event properties (or empty for creating a new calendar)
    this.calendar_edit_dialog = function(calendar)
    {
      // close show dialog first
      var $dialog = $("#calendarform").dialog('close');
      
      if (!calendar)
        calendar = { name:'', color:'cc0000', editable:true, showalarms:true };
      
      var form, name, color, alarms;
      
      $dialog.html(rcmail.get_label('loading'));
      $.ajax({
        type: 'GET',
        dataType: 'html',
        url: rcmail.url('calendar'),
        data: { action:(calendar.id ? 'form-edit' : 'form-new'), c:{ id:calendar.id } },
        success: function(data) {
          $dialog.html(data);
          // resize and reposition dialog window
          form = $('#calendarpropform');
          me.dialog_resize('#calendarform', form.height(), form.width());
          name = $('#calendar-name').prop('disabled', !calendar.editable).val(calendar.editname || calendar.name);
          color = $('#calendar-color').val(calendar.color).miniColors({ value: calendar.color, colorValues:rcmail.env.mscolors });
          alarms = $('#calendar-showalarms').prop('checked', calendar.showalarms).get(0);
          name.select();
        }
      });

      // dialog buttons
      var buttons = {};
      
      buttons[rcmail.gettext('save', 'calendar')] = function() {
        // form is not loaded
        if (!form || !form.length)
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
        if (alarms)
          data.showalarms = alarms.checked ? 1 : 0;

        me.saving_lock = rcmail.set_busy(true, 'calendar.savingdata');
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
        closeOnEscape: false,
        title: rcmail.gettext((calendar.id ? 'editcalendar' : 'createcalendar'), 'calendar'),
        close: function() {
          $dialog.html('').dialog("destroy").hide();
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

    // open a dialog to upload an .ics file with events to be imported
    this.import_events = function(calendar)
    {
      // close show dialog first
      var $dialog = $("#eventsimport").dialog('close');
      var form = rcmail.gui_objects.importform;
      
      $('#event-import-calendar').val(calendar.id);
      
      var buttons = {};
      buttons[rcmail.gettext('import', 'calendar')] = function() {
        if (form && form.elements._data.value) {
          rcmail.async_upload_form(form, 'import_events', function(e) {
            rcmail.set_busy(false, null, me.saving_lock);
          });

          // display upload indicator
          me.saving_lock = rcmail.set_busy(true, 'uploading');
        }
      };
      
      buttons[rcmail.gettext('cancel', 'calendar')] = function() {
        $dialog.dialog("close");
      };
      
      // open jquery UI dialog
      $dialog.dialog({
        modal: true,
        resizable: false,
        closeOnEscape: false,
        title: rcmail.gettext('importevents', 'calendar'),
        close: function() {
          $dialog.dialog("destroy").hide();
        },
        buttons: buttons,
        width: 520
      }).show();
      
    };

    // callback from server if import succeeded
    this.import_success = function(p)
    {
      $("#eventsimport").dialog('close');
      rcmail.set_busy(false, null, me.saving_lock);
      rcmail.gui_objects.importform.reset();

      if (p.refetch)
        this.refresh(p);
    };

    // refresh the calendar view after saving event data
    this.refresh = function(p)
    {
      var source = me.calendars[p.source];

      if (source && (p.refetch || (p.update && !source.active))) {
        // activate event source if new event was added to an invisible calendar
        if (!source.active) {
          source.active = true;
          fc.fullCalendar('addEventSource', source);
          $('#' + rcmail.get_folder_li(source.id, 'rcmlical').id + ' input').prop('checked', true);
        }
        else
          fc.fullCalendar('refetchEvents', source);
      }
      // add/update single event object
      else if (source && p.update) {
        var event = p.update;
        event.temp = false;
        event.editable = source.editable;
        var existing = fc.fullCalendar('clientEvents', event.id);
        if (existing.length) {
          $.extend(existing[0], event);
          fc.fullCalendar('updateEvent', existing[0]);
        }
        else {
          event.source = source;  // link with source
          fc.fullCalendar('renderEvent', event);
        }
        // refresh fish-eye view
        if (me.fisheye_date)
          me.fisheye_view(me.fisheye_date);
      }

      // remove temp events
      fc.fullCalendar('removeEvents', function(e){ return e.temp; });
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
          fc.fullCalendar('option', 'listSections', 'month')
            .fullCalendar('option', 'listRange', Math.max(60, settings['agenda_range']))
            .fullCalendar('changeView', 'table');
          
          update_agenda_toolbar();
          
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
        // hide bottom links of agenda view
        fc.find('.fc-list-content > .fc-listappend').hide();
        
        // restore original event sources and view mode from fullcalendar
        fc.fullCalendar('option', 'listSections', settings['agenda_sections'])
          .fullCalendar('option', 'listRange', settings['agenda_range']);
        
        update_agenda_toolbar();
        
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
      var addlinks, append = '';
      
      // enhance list view when searching
      if (this.search_request) {
        if (!count) {
          this._search_message = rcmail.display_message(rcmail.gettext('searchnoresults', 'calendar'), 'notice');
          append = '<div class="message">' + rcmail.gettext('searchnoresults', 'calendar') + '</div>';
        }
        append += '<div class="fc-bottomlinks formlinks"></div>';
        addlinks = true;
      }
      
      if (fc.fullCalendar('getView').name == 'table') {
        var container = fc.find('.fc-list-content > .fc-listappend');
        if (append) {
          if (!container.length)
            container = $('<div class="fc-listappend"></div>').appendTo(fc.find('.fc-list-content'));
          container.html(append).show();
        }
        else if (container.length)
          container.hide();
        
        // add links to adjust search date range
        if (addlinks) {
          var lc = container.find('.fc-bottomlinks');
          $('<a>').attr('href', '#').html(rcmail.gettext('searchearlierdates', 'calendar')).appendTo(lc).click(function(){
            fc.fullCalendar('incrementDate', 0, -1, 0);
          });
          lc.append(" ");
          $('<a>').attr('href', '#').html(rcmail.gettext('searchlaterdates', 'calendar')).appendTo(lc).click(function(){
            var range = fc.fullCalendar('option', 'listRange');
            if (range < 90) {
              fc.fullCalendar('option', 'listRange', fc.fullCalendar('option', 'listRange') + 30).fullCalendar('render');
              update_agenda_toolbar();
            }
            else
              fc.fullCalendar('incrementDate', 0, 1, 0);
          });
        }
      }
      
      if (this.fisheye_date)
        this.fisheye_view(this.fisheye_date);
    };

    // resize and reposition (center) the dialog window
    this.dialog_resize = function(id, height, width)
    {
      var win = $(window), w = win.width(), h = win.height();
      $(id).dialog('option', { height: Math.min(h-20, height+110), width: Math.min(w-20, width+50) })
        .dialog('option', 'position', ['center', 'center']);  // only works in a separate call (!?)
    };

    // adjust calendar view size
    this.view_resize = function()
    {
      var footer = fc.fullCalendar('getView').name == 'table' ? $('#agendaoptions').height() + 2 : 0;
      fc.fullCalendar('option', 'height', $('#main').height() - footer);
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
      
      this.calendars[id].color = settings.event_coloring % 2  ? '' : '#' + cal.color;
      
      if ((active = cal.active || false)) {
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
            }
            else {
              action = 'removeEventSource';
              me.calendars[id].active = false;
            }
            
            // add/remove event source
            fc.fullCalendar(action, me.calendars[id]);
            rcmail.http_post('calendar', { action:'subscribe', c:{ id:id, active:me.calendars[id].active?1:0 } });
          }
        }).data('id', id).get(0).checked = active;
        
        $(li).click(function(e){
          var id = $(this).data('id');
          rcmail.select_folder(id, 'rcmlical');
          rcmail.enable_command('calendar-edit', true);
          rcmail.enable_command('calendar-remove', 'events-import', !me.calendars[id].readonly);
          me.selected_calendar = id;
        })
        .dblclick(function(){ me.calendar_edit_dialog(me.calendars[me.selected_calendar]); })
        .data('id', id);
      }
      
      if (!cal.readonly && !this.selected_calendar) {
        this.selected_calendar = id;
        rcmail.enable_command('addevent', true);
      }
    }
    
    // select default calendar
    if (settings.default_calendar && this.calendars[settings.default_calendar] && !this.calendars[settings.default_calendar].readonly)
      this.selected_calendar = settings.default_calendar;
    
    var viewdate = new Date();
    if (rcmail.env.date)
      viewdate.setTime(fromunixtime(rcmail.env.date));
    
    // initalize the fullCalendar plugin
    var fc = $('#calendar').fullCalendar({
      header: {
        left: 'prev,next today',
        center: 'title',
        right: 'agendaDay,agendaWeek,month,table'
      },
      aspectRatio: 1,
      date: viewdate.getDate(),
      month: viewdate.getMonth(),
      year: viewdate.getFullYear(),
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
        table: settings['date_agenda']
      },
      titleFormat: {
        month: 'MMMM yyyy',
        week: settings['dates_long'],
        day: 'dddd ' + settings['date_long'],
        table: settings['dates_long']
      },
      listPage: 1,  // advance one day in agenda view
      listRange: settings['agenda_range'],
      listSections: settings['agenda_sections'],
      tableCols: ['handle', 'date', 'time', 'title', 'location'],
      defaultView: rcmail.env.view || settings['default_view'],
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
      listTexts: {
        until: rcmail.gettext('until', 'calendar'),
        past: rcmail.gettext('pastevents', 'calendar'),
        today: rcmail.gettext('today', 'calendar'),
        tomorrow: rcmail.gettext('tomorrow', 'calendar'),
        thisWeek: rcmail.gettext('thisweek', 'calendar'),
        nextWeek: rcmail.gettext('nextweek', 'calendar'),
        thisMonth: rcmail.gettext('thismonth', 'calendar'),
        nextMonth: rcmail.gettext('nextmonth', 'calendar'),
        future: rcmail.gettext('futureevents', 'calendar'),
        week: rcmail.gettext('weekofyear', 'calendar')
      },
      selectable: true,
      selectHelper: false,
      currentTimeIndicator: settings.time_indicator,
      loading: function(isLoading) {
        me.is_loading = isLoading;
        this._rc_loading = rcmail.set_busy(isLoading, 'loading', this._rc_loading);
        // trigger callback
        if (!isLoading)
          me.events_loaded($(this).fullCalendar('clientEvents').length);
      },
      // event rendering
      eventRender: fc_event_render,
      eventAfterRender: function(event, element, view) {
        // replace event element with more... link
        var sday, overflow, link;
        if (view.name == 'month' && (sday = view._morelink['e'+event.id]) && (overflow = view._morelink[sday]) > 1) {
          link = $('<div>')
            .addClass('fc-event-more')
            .html(rcmail.gettext('andnmore', 'calendar').replace('$nr', overflow))
            .css({ position:'absolute', left:element.css('left'), top:element.css('top'), width:element.css('width') })
            .data('date', new Date(event.start.getTime()))
            .click(function(e){ me.fisheye_view($(this).data('date')); });
          element.replaceWith(link);
        }
      },
      eventResizeStart: function(event, jsEvent, ui, view) {
        event_resizing = event.id;
      },
      eventResizeStop: function(event, jsEvent, ui, view) {
        event_resizing = false;
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
          var enddate = new Date(); enddate.setTime(date.getTime() + DAY_MS - 60000);
          return event_edit_dialog('new', { start:date, end:enddate, allDay:allDay, calendar:me.selected_calendar });
        }
        
        if (!ignore_click) {
          view.calendar.gotoDate(date);
          if (day_clicked && new Date(day_clicked).getMonth() != date.getMonth())
            view.calendar.select(date, date, allDay);
        }
        day_clicked = date.getTime();
        day_clicked_ts = now;
      },
      // callback when a specific event is clicked
      eventClick: function(event) {
        if (!event.temp)
          event_show_dialog(event);
      },
      // callback when an event was dragged and finally dropped
      eventDrop: function(event, dayDelta, minuteDelta, allDay, revertFunc) {
        if (event.end == null || event.end.getTime() < event.start.getTime()) {
          event.end = new Date(event.start.getTime() + (allDay ? DAY_MS : HOUR_MS));
        }
        // moved to all-day section: set times to 12:00 - 13:00
        if (allDay && !event.allday) {
          event.start.setHours(12);
          event.start.setMinutes(0);
          event.start.setSeconds(0);
          event.end.setHours(13);
          event.end.setMinutes(0);
          event.end.setSeconds(0);
        }
        // moved from all-day section: set times to working hours
        else if (event.allday && !allDay) {
          var newstart = event.start.getTime();
          revertFunc();  // revert to get original duration
          var numdays = Math.max(1, Math.round((event.end.getTime() - event.start.getTime()) / DAY_MS)) - 1;
          event.start = new Date(newstart);
          event.end = new Date(newstart + numdays * DAY_MS);
          event.end.setHours(settings['work_end'] || 18);
          event.end.setMinutes(0);
          
          if (event.end.getTime() < event.start.getTime())
            event.end = new Date(newstart + HOUR_MS);
        }
        console.log(event.start, event.end);
        // send move request to server
        var data = {
          id: event.id,
          calendar: event.calendar,
          start: date2unixtime(event.start),
          end: date2unixtime(event.end),
          allday: allDay?1:0
        };
        update_event_confirm('move', event, data);
      },
      // callback for event resizing
      eventResize: function(event, delta) {
        // sanitize event dates
        if (event.allDay)
          event.start.setHours(12);
        if (!event.end || event.end.getTime() < event.start.getTime())
          event.end = new Date(event.start.getTime() + HOUR_MS);

        // send resize request to server
        var data = {
          id: event.id,
          calendar: event.calendar,
          start: date2unixtime(event.start),
          end: date2unixtime(event.end)
        };
        update_event_confirm('resize', event, data);
      },
      viewDisplay: function(view) {
        $('#agendaoptions')[view.name == 'table' ? 'show' : 'hide']();
        if (minical) {
          window.setTimeout(function(){ minical.datepicker('setDate', fc.fullCalendar('getDate')); }, exec_deferred);
          if (view.name != current_view)
            me.view_resize();
          current_view = view.name;
        }
      },
      viewRender: function(view) {
        view._maxevents = Math.floor((view.element.parent().height()-18) / 108) - 1;
        view._eventcount = [];
        view._suppressed = [];
        view._morelink = [];
      }
    });

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


    var minical;
    var init_calendar_ui = function()
    {
      // initialize small calendar widget using jQuery UI datepicker
      minical = $('#datepicker').datepicker($.extend(datepicker_settings, {
        inline: true,
        showWeek: true,
        changeMonth: false, // maybe enable?
        changeYear: false,  // maybe enable?
        onSelect: function(dateText, inst) {
          ignore_click = true;
          var d = minical.datepicker('getDate'); //parse_datetime('0:0', dateText);
          fc.fullCalendar('gotoDate', d).fullCalendar('select', d, d, true);
        },
        onChangeMonthYear: function(year, month, inst) {
          minical.data('year', year).data('month', month);
        },
        beforeShowDay: function(date) {
          var view = fc.fullCalendar('getView');
          var active = view.visStart && date.getTime() >= view.visStart.getTime() && date.getTime() < view.visEnd.getTime();
          return [ true, (active ? 'ui-datepicker-activerange ui-datepicker-active-' + view.name : ''), ''];
        }
      })) // set event handler for clicks on calendar week cell of the datepicker widget
        .click(function(e) {
          var cell = $(e.target);
          if (e.target.tagName == 'TD' && cell.hasClass('ui-datepicker-week-col')) {
            var base_date = minical.datepicker('getDate');
            if (minical.data('month'))
              base_date.setMonth(minical.data('month')-1);
            if (minical.data('year'))
              base_date.setYear(minical.data('year'));
            base_date.setHours(12);
            var day_off = base_date.getDay() - 1;
            if (day_off < 0) day_off = 6;
            var base_kw = $.datepicker.iso8601Week(base_date);
            var kw = parseInt(cell.html());
            var diff = (kw - base_kw) * 7 * DAY_MS;
            // select monday of the chosen calendar week
            var date = new Date(base_date.getTime() - day_off * DAY_MS + diff);
            fc.fullCalendar('gotoDate', date).fullCalendar('setDate', date).fullCalendar('changeView', 'agendaWeek');
            minical.datepicker('setDate', date);
          }
      });

      // init event dialog
      $('#eventtabs').tabs({
        show: function(event, ui) {
          if (ui.panel.id == 'event-tab-3') {
            $('#edit-attendee-name').select();
            // update free-busy status if needed
            if (freebusy_ui.needsupdate && me.selected_event)
              update_freebusy_status(me.selected_event);
            // add current user as organizer if non added yet
            if (!event_attendees.length) {
              add_attendee($.extend({ role:'ORGANIZER' }, settings.identity));
              $('#edit-attendees-form .attendees-invitebox').show();
            }
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
      $('#edit-recurrence-repeat-times').change(function(e){ $('#edit-recurrence-repeat-count').prop('checked', true); });

      // init attendees autocompletion
      var ac_props;
      // parallel autocompletion
      if (rcmail.env.autocomplete_threads > 0) {
        ac_props = {
          threads: rcmail.env.autocomplete_threads,
          sources: rcmail.env.autocomplete_sources
        };
      }
      rcmail.init_address_input_events($('#edit-attendee-name'), ac_props);
      rcmail.addEventListener('autocomplete_insert', function(e){ $('#edit-attendee-add').click(); });

      $('#edit-attendee-add').click(function(){
        var input = $('#edit-attendee-name');
        rcmail.ksearch_blur();
        if (add_attendees(input.val())) {
          input.val('');
        }
      });

      // keep these two checkboxes in sync
      $('#edit-attendees-donotify, #edit-attendees-invite').click(function(){
        $('#edit-attendees-donotify, #edit-attendees-invite').prop('checked', this.checked);
      });

      $('#edit-attendee-schedule').click(function(){
        event_freebusy_dialog();
      });

      $('#shedule-freebusy-prev').html(bw.ie6 ? '&lt;&lt;' : '&#9668;').button().click(function(){ render_freebusy_grid(-1); });
      $('#shedule-freebusy-next').html(bw.ie6 ? '&gt;&gt;' : '&#9658;').button().click(function(){ render_freebusy_grid(1); }).parent().buttonset();

      $('#shedule-find-prev').button().click(function(){ freebusy_find_slot(-1); });
      $('#shedule-find-next').button().click(function(){ freebusy_find_slot(1); });

      $('#schedule-freebusy-workinghours').click(function(){
        freebusy_ui.workinhoursonly = this.checked;
        $('#workinghourscss').remove();
        if (this.checked)
          $('<style type="text/css" id="workinghourscss"> td.offhours { opacity:0.3; filter:alpha(opacity=30) } </style>').appendTo('head');
      });

      $('#event-rsvp input.button').click(function(){
        event_rsvp($(this).attr('rel'))
      })

      $('#agenda-listrange').change(function(e){
        settings['agenda_range'] = parseInt($(this).val());
        fc.fullCalendar('option', 'listRange', settings['agenda_range']).fullCalendar('render');
        // TODO: save new settings in prefs
      }).val(settings['agenda_range']);
      
      $('#agenda-listsections').change(function(e){
        settings['agenda_sections'] = $(this).val();
        fc.fullCalendar('option', 'listSections', settings['agenda_sections']).fullCalendar('render');
        // TODO: save new settings in prefs
      }).val(fc.fullCalendar('option', 'listSections'));

      // hide event dialog when clicking somewhere into document
      $(document).bind('mousedown', dialog_check);

      rcmail.set_busy(false, 'loading', ui_loading);
    }

    // initialize more UI elements (deferred)
    window.setTimeout(init_calendar_ui, exec_deferred);

    // add proprietary css styles if not IE
    if (!bw.ie)
      $('div.fc-content').addClass('rcube-fc-content');

    // IE supresses 2nd click event when double-clicking
    if (bw.ie && bw.vendver < 9) {
      $('div.fc-content').bind('dblclick', function(e){
        if (!$(this).hasClass('fc-widget-header') && fc.fullCalendar('getView').name != 'table') {
          var date = fc.fullCalendar('getDate');
          var enddate = new Date(); enddate.setTime(date.getTime() + DAY_MS - 60000);
          event_edit_dialog('new', { start:date, end:enddate, allDay:true, calendar:me.selected_calendar });
        }
      });
    }
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
  rcmail.register_command('events-import', function(){ cal.import_events(cal.calendars[cal.selected_calendar]); }, false);
 
  // search and export events
  rcmail.register_command('export', function(){ rcmail.goto_url('export_events', { source:cal.selected_calendar }); }, true);
  rcmail.register_command('search', function(){ cal.quicksearch(); }, true);
  rcmail.register_command('reset-search', function(){ cal.reset_quicksearch(); }, true);

  // register callback commands
  rcmail.addEventListener('plugin.display_alarms', function(alarms){ cal.display_alarms(alarms); });
  rcmail.addEventListener('plugin.destroy_source', function(p){ cal.calendar_destroy_source(p.id); });
  rcmail.addEventListener('plugin.unlock_saving', function(p){ rcmail.set_busy(false, null, cal.saving_lock); });
  rcmail.addEventListener('plugin.refresh_calendar', function(p){ cal.refresh(p); });
  rcmail.addEventListener('plugin.import_success', function(p){ cal.import_success(p); });

  // let's go
  var cal = new rcube_calendar_ui(rcmail.env.calendar_settings);

  $(window).resize(function(e) {
    // check target due to bugs in jquery
    // http://bugs.jqueryui.com/ticket/7514
    // http://bugs.jquery.com/ticket/9841
    if (e.target == window) {
      cal.view_resize();
    }
  }).resize();

  // show calendars list when ready
  $('#calendars').css('visibility', 'inherit');

  // show toolbar
  $('#toolbar').show();

});
