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
function rcube_calendar_print(settings)
{
    // extend base class
    rcube_calendar.call(this, settings);
      
    /***  private vars  ***/
    var me = this;
    var gmt_offset = (new Date().getTimezoneOffset() / -60) - (settings.timezone || 0);
       

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
              fc.fullCalendar(action, me.calendars[id]);
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
    var fc = $('#calendar').fullCalendar({
      header: {
        left: '',
        center: 'title',
        right: ''
      },
      aspectRatio: 1,
      ignoreTimezone: true,  // will treat the given date strings as in local (browser's) timezone
      height: '100%',
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
      defaultView: rcmail.env.nview,
      allDayText: rcmail.gettext('all-day', 'calendar'),
      selectable: false,
      selectHelper: true,
      loading: function(isLoading) {
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
      
      viewDisplay: function(view) {
        me.eventcount = [];
        if (!bw.ie)
          window.setTimeout(function(){ $('div.fc-content').css('overflow', view.name == 'month' ? 'auto' : 'hidden') }, 10);
      },
      windowResize: function(view) {
        me.eventcount = [];
      }
    });


    

   
   // add proprietary css styles if not IE
    if (!bw.ie)
      $('div.fc-content').addClass('rcube-fc-content');

    
} // end rcube_calendar class


/* calendar plugin initialization */
window.rcmail && rcmail.addEventListener('init', function(evt) {

  
  // let's go
  var cal = new rcube_calendar_print(rcmail.env.calendar_settings);

  $(window).resize(function() {
    $('#calendar').fullCalendar('option', 'height', $('#main').height());
  }).resize();

});
