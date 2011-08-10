<?php

$labels = array();

// preferences
$labels['default_view'] = 'Default view';
$labels['time_format'] = 'Time format';
$labels['timeslots'] = 'Timeslots per hour';
$labels['first_day'] = 'First weekday';
$labels['first_hour'] = 'First hour to show';
$labels['workinghours'] = 'Working hours';
$labels['add_category'] = 'Add category';
$labels['remove_category'] = 'Remove category';
$labels['defaultcalendar'] = 'Create new events in';

// calendar
$labels['calendar'] = 'Calendar';
$labels['calendars'] = 'Calendars';
$labels['category'] = 'Category';
$labels['categories'] = 'Categories';
$labels['createcalendar'] = 'Create new calendar';
$labels['editcalendar'] = 'Edit calendar properties';
$labels['name'] = 'Name';
$labels['color'] = 'Color';
$labels['day'] = 'Day';
$labels['week'] = 'Week';
$labels['month'] = 'Month';
$labels['agenda'] = 'Agenda';
$labels['new_event'] = 'New event';
$labels['edit_event'] = 'Edit event';
$labels['edit'] = 'Edit';
$labels['save'] = 'Save';
$labels['remove'] = 'Remove';
$labels['cancel'] = 'Cancel';
$labels['select'] = 'Select';
$labels['print'] = 'Print calendars';
$labels['title'] = 'Summary';
$labels['description'] = 'Description';
$labels['all-day'] = 'all-day';
$labels['export'] = 'Export to iCalendar';
$labels['location'] = 'Location';
$labels['date'] = 'Date';
$labels['start'] = 'Start';
$labels['end'] = 'End';
$labels['selectdate'] = 'Choose date';
$labels['freebusy'] = 'Show me as';
$labels['free'] = 'Free';
$labels['busy'] = 'Busy';
$labels['outofoffice'] = 'Out of Office';
$labels['tentative'] = 'Tentative';
$labels['priority'] = 'Priority';
$labels['sensitivity'] = 'Privacy';
$labels['public'] = 'public';
$labels['private'] = 'private';
$labels['confidential'] = 'confidential';
$labels['alarms'] = 'Reminder';
$labels['generated'] = 'generated at';
$labels['printdescriptions'] = 'Print descriptions';
$labels['parentcalendar'] = 'Superior calendar';
$labels['importtocalendar'] = 'Save to my calendar';
$labels['searchearlierdates'] = '« Search for earlier events';
$labels['searchlaterdates'] = 'Search for later events »';

// alarm/reminder settings
$labels['showalarms'] = 'Show alarms';
$labels['alarmemail'] = 'Send Email';
$labels['alarmdisplay'] = 'Show message';
$labels['alarmdisplayoption'] = 'Message';
$labels['alarmemailoption'] = 'Email';
$labels['alarmat'] = 'at $datetime';
$labels['trigger@'] = 'on date';
$labels['trigger-M'] = 'minutes before';
$labels['trigger-H'] = 'hours before';
$labels['trigger-D'] = 'days before';
$labels['trigger+M'] = 'minutes after';
$labels['trigger+H'] = 'hours after';
$labels['trigger+D'] = 'days after';
$labels['addalarm'] = 'add alarm';
$labels['defaultalarmtype'] = 'Default reminder setting';
$labels['defaultalarmoffset'] = 'Default reminder time';
$labels['dismissall'] = 'Dismiss all';
$labels['dismiss'] = 'Dismiss';
$labels['snooze'] = 'Snooze';
$labels['repeatinmin'] = 'Repeat in $min minutes';
$labels['repeatinhr'] = 'Repeat in 1 hour';
$labels['repeatinhrs'] = 'Repeat in $hrs hours';
$labels['repeattomorrow'] = 'Repeat tomorrow';
$labels['repeatinweek'] = 'Repeat in a week';
$labels['alarmtitle'] = 'Upcoming events';

// attendees
$labels['attendee'] = 'Participant';
$labels['role'] = 'Role';
$labels['availability'] = 'Avail.';
$labels['confirmstate'] = 'Status';
$labels['addattendee'] = 'Add participant';
$labels['roleorganizer'] = 'Organizer';
$labels['rolerequired'] = 'Required';
$labels['roleoptional'] = 'Optional';
$labels['roleresource'] = 'Resource';
$labels['availfree'] = 'Free';
$labels['availbusy'] = 'Busy';
$labels['availunknown'] = 'Unknown';
$labels['availtentative'] = 'Tentative';
$labels['availoutofoffice'] = 'Out of Office';
$labels['scheduletime'] = 'Find availability';
$labels['sendinvitations'] = 'Send invitations';
$labels['sendnotifications'] = 'Notify participants about modifications';
$labels['sendcancellation'] = 'Notify participants about event cancellation';
$labels['onlyworkinghours'] = 'Find availability within my working hours';
$labels['reqallattendees'] = 'Required/all participants';
$labels['prevslot'] = 'Previous Slot';
$labels['nextslot'] = 'Next Slot';
$labels['noslotfound'] = 'Unable to find a free time slot';
$labels['invitationsubject'] = 'You\'ve been invited to "$title"';
$labels['invitationmailbody'] = "*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees\n\nPlease find attached an iCalendar file with all the event details which you can import to your calendar application.";
$labels['eventupdatesubject'] = '"$title" has been updated';
$labels['eventupdatesubjectempty'] = 'An event that concerns you has been updated';
$labels['eventupdatemailbody'] = "*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees\n\nPlease find attached an iCalendar file with the updated event details which you can import to your calendar application.";
$labels['eventcancelsubject'] = '"$title" has been canceled';
$labels['eventcancelmailbody'] = "*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees\n\nThe event has been cancelled by \$organizer.\n\nPlease find attached an iCalendar file with the updated event details.";

// event dialog tabs
$labels['tabsummary'] = 'Summary';
$labels['tabrecurrence'] = 'Recurrence';
$labels['tabattendees'] = 'Participants';
$labels['tabattachments'] = 'Attachments';

// messages
$labels['deleteventconfirm'] = 'Do you really want to delete this event?';
$labels['deletecalendarconfirm'] = 'Do you really want to delete this calendar with all its events?';
$labels['savingdata'] = 'Saving data...';
$labels['errorsaving'] = 'Failed to save changes.';
$labels['operationfailed'] = 'The requested operation failed.';
$labels['invalideventdates'] = 'Invalid dates entered! Please check your input.';
$labels['invalidcalendarproperties'] = 'Invalid calendar properties! Please set a valid name.';
$labels['searchnoresults'] = 'No events found in the selected calendars.';
$labels['successremoval'] = 'The event has been deleted successfully.';
$labels['successrestore'] = 'The event has been restored successfully.';
$labels['errornotifying'] = 'Failed to send notifications to event participants';
$labels['errorimportingevent'] = 'Failed to import the event';
$labels['newerversionexists'] = 'A newer version of this event already exists! Aborted.';
$labels['nowritecalendarfound'] = 'No calendar found to save the event';
$labels['importedsuccessfully'] = 'The event was successfully added to \'$calendar\'';

// recurrence form
$labels['repeat'] = 'Repeat';
$labels['frequency'] = 'Repeat';
$labels['never'] = 'never';
$labels['daily'] = 'daily';
$labels['weekly'] = 'weekly';
$labels['monthly'] = 'monthly';
$labels['yearly'] = 'annually';
$labels['every'] = 'Every';
$labels['days'] = 'day(s)';
$labels['weeks'] = 'week(s)';
$labels['months'] = 'month(s)';
$labels['years'] = 'year(s) in:';
$labels['bydays'] = 'On';
$labels['until'] = 'the';
$labels['each'] = 'Each';
$labels['onevery'] = 'On every';
$labels['onsamedate'] = 'On the same date';
$labels['forever'] = 'forever';
$labels['recurrencend'] = 'until';
$labels['forntimes'] = 'for $nr time(s)';
$labels['first'] = 'first';
$labels['second'] = 'second';
$labels['third'] = 'third';
$labels['fourth'] = 'fourth';
$labels['last'] = 'last';
$labels['dayofmonth'] = 'Day of month';

$labels['changeeventconfirm'] = 'Change event';
$labels['removeeventconfirm'] = 'Remove event';
$labels['changerecurringeventwarning'] = 'This is a recurring event. Would you like to edit the current event only, this and all future occurences, all occurences or save it as a new event?';
$labels['removerecurringeventwarning'] = 'This is a recurring event. Would you like to remove the current event only, this and all future occurences or all occurences of this event?';
$labels['currentevent'] = 'Current';
$labels['futurevents'] = 'Future';
$labels['allevents'] = 'All';
$labels['saveasnew'] = 'Save as new';

?>
