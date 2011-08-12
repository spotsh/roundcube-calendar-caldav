<?php

$labels = array();

// preferences
$labels['default_view'] = 'Ansicht';
$labels['time_format'] = 'Zeitformatierung';
$labels['timeslots'] = 'Zeitraster pro Stunde';
$labels['first_day'] = 'Erster Wochentag';
$labels['first_hour'] = 'Erste angezeigte Stunde';
$labels['workinghours'] = 'Arbeitszeiten';
$labels['add_category'] = 'Katgorie hinzufügen';
$labels['remove_category'] = 'Katgorie entfernen';
$labels['defaultcalendar'] = 'Neue Termine erstellen in';

// calendar
$labels['calendar'] = 'Kalender';
$labels['calendars'] = 'Kalender';
$labels['category'] = 'Kategorie';
$labels['categories'] = 'Kategorien';
$labels['createcalendar'] = 'Neuen Kalender erstellen';
$labels['editcalendar'] = 'Kalendereigenschaften bearbeiten';
$labels['name'] = 'Name';
$labels['color'] = 'Farbe';
$labels['day'] = 'Tag';
$labels['week'] = 'Woche';
$labels['month'] = 'Monat';
$labels['agenda'] = 'Agenda';
$labels['new_event'] = 'Neuer Termin';
$labels['edit_event'] = 'Termin bearbeiten';
$labels['edit'] = 'Bearbeiten';
$labels['save'] = 'Speichern';
$labels['remove'] = 'Entfernen';
$labels['cancel'] = 'Abbrechen';
$labels['select'] = 'Auswählen';
$labels['print'] = 'Kalender drucken';
$labels['title'] = 'Titel';
$labels['description'] = 'Beschrieb';
$labels['all-day'] = 'ganztägig';
$labels['export'] = 'Exportieren...';
$labels['location'] = 'Ort';
$labels['date'] = 'Datum';
$labels['start'] = 'Beginn';
$labels['end'] = 'Ende';
$labels['selectdate'] = 'Datum auswählen';
$labels['freebusy'] = 'Zeige mich als';
$labels['free'] = 'Frei';
$labels['busy'] = 'Gebucht';
$labels['outofoffice'] = 'Abwesend';
$labels['tentative'] = 'Mit Vorbehalt';
$labels['priority'] = 'Priorität';
$labels['sensitivity'] = 'Sichtbarkeit';
$labels['public'] = 'öffentlich';
$labels['private'] = 'privat';
$labels['confidential'] = 'vertraulich';
$labels['alarms'] = 'Erinnerung';
$labels['generated'] = 'erstellt am';
$labels['printdescriptions'] = 'Beschrieb drucken';
$labels['parentcalendar'] = 'Übergeordneter Kalender';
$labels['importtocalendar'] = 'In Kalender übernehmen';
$labels['searchearlierdates'] = '« Frühere Termine suchen';
$labels['searchlaterdates'] = 'Spätere Termine suchen »';
$labels['andnmore'] = 'und $nr weitere';

// alarm/reminder settings
$labels['showalarms'] = 'Erinnerungen anzeigen';
$labels['alarmemail'] = 'E-Mail senden';
$labels['alarmdisplay'] = 'Nachricht anzeigen';
$labels['alarmdisplayoption'] = 'Nachricht';
$labels['alarmemailoption'] = 'E-Mail';
$labels['alarmat'] = 'um $datetime';
$labels['trigger@'] = 'genau um';
$labels['trigger-M'] = 'Minuten davor';
$labels['trigger-H'] = 'Stunden davor';
$labels['trigger-D'] = 'Tage davor';
$labels['trigger+M'] = 'Minuten danach';
$labels['trigger+H'] = 'Stunden danach';
$labels['trigger+D'] = 'Tage danach';
$labels['addalarm'] = 'Erinnerung hinzufügen';
$labels['defaultalarmtype'] = 'Standard-Erinnerungseinstellung';
$labels['defaultalarmoffset'] = 'Standard-Erinnerungszeit';
$labels['dismissall'] = 'Alle ignorieren';
$labels['dismiss'] = 'Ignorieren';
$labels['snooze'] = 'Später erinnern';
$labels['repeatinmin'] = 'Wiederholung in $min Minuten';
$labels['repeatinhr'] = 'Wiederholung in 1 Stunde';
$labels['repeatinhrs'] = 'Wiederholung in $hrs Stunden';
$labels['repeattomorrow'] = 'Wiederholung morgen';
$labels['repeatinweek'] = 'Wiederholung in einer Woche';
$labels['alarmtitle'] = 'Anstehende Termine';

// attendees
$labels['attendee'] = 'Teilnehmer';
$labels['role'] = 'Rolle';
$labels['availability'] = 'Verfügbar';
$labels['confirmstate'] = 'Status';
$labels['addattendee'] = 'Hinzufügen';
$labels['roleorganizer'] = 'Organisator';
$labels['rolerequired'] = 'Erforderlich';
$labels['roleoptional'] = 'Optional';
$labels['roleresource'] = 'Ressource';
$labels['availfree'] = 'Frei';
$labels['availbusy'] = 'Gebucht';
$labels['availunknown'] = 'Unbekannt';
$labels['availtentative'] = 'Mit Vorbehalt';
$labels['availoutofoffice'] = 'Abwesend';
$labels['scheduletime'] = 'Verfügbarkeit anzeigen';
$labels['sendinvitations'] = 'Einladungen versenden';
$labels['sendnotifications'] = 'Teilnehmer über die Änderungen informieren';
$labels['sendcancellation'] = 'Teilnehmer über die Terminabsage informieren';
$labels['onlyworkinghours'] = 'Verfügbarkeit innerhalb meiner Arbeitszeiten suchen';
$labels['reqallattendees'] = 'Erforderliche/alle Teilnehmer';
$labels['prevslot'] = 'Vorheriger Vorschlag';
$labels['nextslot'] = 'Nächster Vorschlag';
$labels['noslotfound'] = 'Es konnten keine freien Zeiten gefunden werden';
$labels['invitationsubject'] = 'Sie wurden zu "$title" eingeladen';
$labels['invitationmailbody'] = "*\$title*\n\nWann: \$date\n\nTeilnehmer: \$attendees\n\nIm Anhang finden Sie eine iCalendar-Datei mit allen Details des Termins. Diese können Sie in Ihre Kalenderanwendung importieren.";
$labels['eventupdatesubject'] = '"$title" wurde aktualisiert';
$labels['eventupdatesubjectempty'] = 'An event that concerns you has been updated';
$labels['eventupdatemailbody'] = "*\$title*\n\nWann: \$date\n\nTeilnehmer: \$attendees\n\nIm Anhang finden Sie eine iCalendar-Datei mit den aktualisiereten Termindaten. Diese können Sie in Ihre Kalenderanwendung importieren";
$labels['eventcancelsubject'] = '"$title" wurde abgesagt';
$labels['eventcancelmailbody'] = "*\$title*\n\nWann: \$date\n\nTeilnehmer: \$attendees\n\nDer Termin wurde von \$organizer abgesagt.\n\nIm Anhang finden Sie eine iCalendar-Datei mit den Termindaten.";

// event dialog tabs
$labels['tabsummary'] = 'Übersicht';
$labels['tabrecurrence'] = 'Wiederholung';
$labels['tabattendees'] = 'Teilnehmer';
$labels['tabattachments'] = 'Anhänge';

// messages
$labels['deleteventconfirm'] = 'Möchten Sie diesen Termin wirklich löschen?';
$labels['deletecalendarconfirm'] = 'Möchten Sie diesen Kalender mit allen Terminen wirklich löschen?';
$labels['savingdata'] = 'Speichere Daten...';
$labels['errorsaving'] = 'Fehler beim Speichern.';
$labels['operationfailed'] = 'Die Aktion ist fehlgeschlagen.';
$labels['invalideventdates'] = 'Ungültige Daten eingegeben! Bitte überprüfen Sie die Eingaben.';
$labels['invalidcalendarproperties'] = 'Ungültige Kalenderinformationen! Bitte geben Sie einen Namen ein.';
$labels['searchnoresults'] = 'Keine Termine in den gewählten Kalendern gefunden.';
$labels['successremoval'] = 'Der Termin wurde erfolgreich gelöscht.';
$labels['successrestore'] = 'Der Termin wurde erfolgreich wieder hergestellt.';
$labels['errornotifying'] = 'Benachrichtigung an die Teilnehmer konnten nicht gesendet werden';
$labels['errorimportingevent'] = 'Fehler beim Importieren';
$labels['newerversionexists'] = 'Eine neuere Version dieses Termins exisitert bereits! Import abgebrochen.';
$labels['nowritecalendarfound'] = 'Kein Kalender zum Speichern gefunden';
$labels['importedsuccessfully'] = 'Der Termin wurde erfolgreich in \'$calendar\' gespeichert';

// recurrence form
$labels['repeat'] = 'Wiederholung';
$labels['frequency'] = 'Wiederholung';
$labels['never'] = 'nie';
$labels['daily'] = 'täglich';
$labels['weekly'] = 'wöchentlich';
$labels['monthly'] = 'monatlich';
$labels['yearly'] = 'jährlich';
$labels['every'] = 'Alle';
$labels['days'] = 'Tag(e)';
$labels['weeks'] = 'Woche(n)';
$labels['months'] = 'Monat(e)';
$labels['years'] = 'Jahre(e) im:';
$labels['bydays'] = 'Am';
$labels['until'] = 'am';
$labels['each'] = 'Jeden';
$labels['onevery'] = 'An jedem';
$labels['onsamedate'] = 'Am gleichen Tag';
$labels['forever'] = 'unendlich';
$labels['recurrencend'] = 'bis';
$labels['forntimes'] = '$nr Wiederholungen';
$labels['first'] = 'erster';
$labels['second'] = 'zweiter';
$labels['third'] = 'dritter';
$labels['fourth'] = 'vierter';
$labels['last'] = 'letzter';
$labels['dayofmonth'] = 'Tag des Montats';

$labels['changeeventconfirm'] = 'Termin ändern';
$labels['removeeventconfirm'] = 'Termin löschen';
$labels['changerecurringeventwarning'] = 'Dies ist eine Terminreihe. Möchten Sie nur den aktuellen, diesen und alle zukünftigen oder alle Termine bearbeiten oder die Änderungen als neuen Termin speichern?';
$labels['removerecurringeventwarning'] = 'Dies ist eine Terminreihe. Möchten Sie nur den aktuellen, diesen und alle zukünftigen oder alle Termine löschen?';
$labels['currentevent'] = 'Aktuellen';
$labels['futurevents'] = 'Zukünftige';
$labels['allevents'] = 'Alle';
$labels['saveasnew'] = 'Als neu speichern';

?>