/**
 * Base Javascript class for the Calendar plugin
 *
 * @version @package_version@
 * @author Lazlo Westerhof <hello@lazlo.me>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2010, Lazlo Westerhof <hello@lazlo.me>
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

// Basic setup for Roundcube calendar client class
function rcube_calendar(settings)
{
    // member vars
    this.ui;
    this.ui_loaded = false;
    this.settings = settings;
    this.alarm_ids = [];
    this.alarm_dialog = null;
    this.snooze_popup = null;
    this.dismiss_link = null;

    // private vars
    var me = this;

    // quote html entities
    var Q = this.quote_html = function(str)
    {
      return String(str).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    // create a nice human-readable string for the date/time range
    this.event_date_text = function(event)
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

    // display a notification for the given pending alarms
    this.display_alarms = function(alarms) {
      // clear old alert first
      if (this.alarm_dialog)
        this.alarm_dialog.dialog('destroy');
      
      this.alarm_dialog = $('<div>').attr('id', 'alarm-display');
      
      var actions, adismiss, asnooze, alarm, html, event_ids = [];
      for (var actions, html, alarm, i=0; i < alarms.length; i++) {
        alarm = alarms[i];
        alarm.start = $.fullCalendar.parseISO8601(alarm.start, true);
        alarm.end = $.fullCalendar.parseISO8601(alarm.end, true);
        event_ids.push(alarm.id);
        
        html = '<h3 class="event-title">' + Q(alarm.title) + '</h3>';
        html += '<div class="event-section">' + Q(alarm.location || '') + '</div>';
        html += '<div class="event-section">' + Q(this.event_date_text(alarm)) + '</div>';
        
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
        // create popup if not found
        if (!this.snooze_popup.length) {
          this.snooze_popup = $('<div>').attr('id', 'alarm-snooze-dropdown').addClass('popupmenu').appendTo(document.body);
          this.snooze_popup.html(rcmail.env.snooze_select)
        }
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
      rcmail.http_post('calendar/event', { action:'dismiss', e:{ id:id, snooze:snooze } });
      
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

    // create new event from current mail message
    this.create_from_mail = function()
    {
      var uid;
      if ((uid = rcmail.get_single_uid())) {
        // load calendar UI (scripts and edit dialog template)
        if (!this.ui_loaded) {
          $.when(
            $.getScript('./plugins/calendar/calendar_ui.js'),
            $.get(rcmail.url('calendar/inlineui'), function(html){ $(document.body).append(html); }, 'html')
          ).then(function() {
            // register attachments form
            rcmail.gui_object('attachmentlist', 'attachmentlist');
            
            // disable attendees feature (autocompletion and stuff is not initialized)
            for (var c in rcmail.env.calendars)
              rcmail.env.calendars[c].attendees = false;
            
            me.ui_loaded = true;
            me.ui = new rcube_calendar_ui(me.settings);
            me.create_from_mail();  // start over
          });
          return;
        }
        else {
          // get message contents for event dialog
          var lock = rcmail.set_busy(true, 'loading');
          rcmail.http_post('calendar/mailtoevent', {
              '_mbox': rcmail.env.mailbox,
              '_uid': uid
            }, lock);
        }
      }
    };
    
    // callback function triggered from server with contents for the new event
    this.mail2event_dialog = function(event)
    {
      if (event.title) {
        this.ui.add_event(event);
        rcmail.message_list.blur();
      }
    };
}

// static methods
rcube_calendar.add_event_from_mail = function(mime_id, status)
{
  var lock = rcmail.set_busy(true, 'calendar.savingdata');
  rcmail.http_post('calendar/mailimportevent', {
      '_uid': rcmail.env.uid,
      '_mbox': rcmail.env.mailbox,
      '_part': mime_id,
      '_calendar': $('#calendar-saveto').val(),
      '_status': status
    }, lock);
  return false;
};

rcube_calendar.remove_event_from_mail = function(uid, title)
{
  if (confirm(rcmail.gettext('calendar.deleteventconfirm'))) {
    var lock = rcmail.set_busy(true, 'calendar.savingdata');
    rcmail.http_post('calendar/event', {
        e:{ uid:uid },
        action: 'remove'
      }, lock);
  }
};

rcube_calendar.fetch_event_rsvp_status = function(event)
{
/*
  var id = event.uid.replace(rcmail.identifier_expr, '');
  $('#import-'+id+', #rsvp-'+id+', div.rsvp-status').hide();
  $('#loading-'+id).show();
*/
  rcmail.http_post('calendar/event', {
    e:event,
    action:'rsvp-status'
  });
};


// extend jQuery
(function($){
  $.fn.serializeJSON = function(){
    var json = {};
    jQuery.map($(this).serializeArray(), function(n, i) {
      json[n['name']] = n['value'];
    });
    return json;
  };
})(jQuery);

/* calendar plugin initialization (for non-calendar tasks) */
window.rcmail && rcmail.addEventListener('init', function(evt) {
  if (rcmail.task != 'calendar') {
    var cal = new rcube_calendar(rcmail.env.calendar_settings);

    rcmail.addEventListener('plugin.display_alarms', function(alarms){ cal.display_alarms(alarms); });
    
    rcmail.addEventListener('plugin.update_event_rsvp_status', function(p){
      if (p.html)
        $('#loading-'+p.id).hide().after(p.html);
      else
        $('#loading-'+p.id).hide();
      
      $('#'+p.action+'-'+p.id).show().append(p.select);
    });
    
    // register create-from-mail command to message_commands array
    if (rcmail.env.task == 'mail') {
      // place link above 'view source'
      $('#messagemenu a.calendarlink').parent().insertBefore($('#messagemenu a.sourcelink').parent());
      
      rcmail.register_command('calendar-create-from-mail', function() { cal.create_from_mail() });
      rcmail.addEventListener('plugin.mail2event_dialog', function(p){ cal.mail2event_dialog(p) });
      rcmail.addEventListener('plugin.unlock_saving', function(p){ rcmail.set_busy(false, null, cal.ui.saving_lock); });
      
      if (rcmail.env.action != 'show') {
        rcmail.env.message_commands.push('calendar-create-from-mail');
        rcmail.add_element($('<a>'));
      }
      else
        rcmail.enable_command('calendar-create-from-mail', true);
    }
  }

  rcmail.register_command('plugin.calendar', function() { rcmail.switch_task('calendar'); }, true);
  
  rcmail.addEventListener('plugin.ping_url', function(p){
    var action = p.action;
    p.action = p.event = null;
    new Image().src = rcmail.url(action, p);
  });
});
