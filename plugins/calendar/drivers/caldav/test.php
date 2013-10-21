<?php
$url = "https://www.google.com/calendar/dav/daniel.morlock@gmail.com/events/";
//$url = "https://awesome-mail.de/irony/calendars/daniel.morlock@awesome-mail.de/178a4964348a-2e8fa083f700-dee00b57";
$userpwd = "daniel.morlock@gmail.com:tlegginernrqmymv";
$description = 'My event description here';
$summary = 'My event title 1';
$tstart = '201206015T000000Z';
$tend = '20120616T000000Z';
$tstamp = gmdate("Ymd\THis\Z");

$body = <<<__EOD
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
DTSTAMP:$tstamp
DTSTART:$tstart
DTEND:$tend
UID:123123123123
DESCRIPTION:$description
LOCATION:Office
SUMMARY:$summary
END:VEVENT
END:VCALENDAR
__EOD;

$headers = array(
	'Content-Type: application/xml',
	'Depth: 1');
  
  
$conf = array();
$conf[CURLOPT_RETURNTRANSFER] = true;
$conf[CURLOPT_FOLLOWLOCATION] = true;
$conf[CURLOPT_MAXREDIRS] = 5;
$conf[CURLOPT_SSL_VERIFYPEER] = false;
$conf[CURLOPT_HTTPHEADER] = $headers;
$conf[CURLOPT_HEADER] = true;
$conf[CURLOPT_POSTFIELDS] = '<?xml version="1.0"?>
<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <x:calendar-data xmlns:x="urn:ietf:params:xml:ns:caldav"/>
  </d:prop>
<d:href>/calendar/dav/daniel.morlock%40gmail.com/events/tk798sd8ctqc99sloidd3d1f64%40google.com.ics</d:href>
<d:href>/calendar/dav/daniel.morlock%40gmail.com/events/2nu32hj6f2f2qrmola84bc175o%40google.com.ics</d:href>
</c:calendar-multiget>';
/*
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:displayname />
  </d:prop>
</d:propfind>';*/
$conf[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
$conf[CURLOPT_USERPWD] = $userpwd;
//$conf[CURLOPT_CUSTOMREQUEST] = "PROPFIND";
$conf[CURLOPT_CUSTOMREQUEST] = "REPORT";

$consts = array("CURLOPT_RETURNTRANSFER", "CURLOPT_FOLLOWLOCATION", "CURLOPT_MAXREDIRS", 
"CURLOPT_SSL_VERIFYPEER", "CURLOPT_HTTPHEADER", "CURLOPT_HEADER", "CURLOPT_POSTFIELDS", "CURLOPT_HTTPAUTH",
"CURLOPT_USERPWD", "CURLOPT_CUSTOMREQUEST");

foreach($consts as $c)
{
  $val = $conf[constant($c)];
  if(is_array($val)) $val = print_r($val, true);
  echo "$c (".constant($c).") = ".$val."\n";
}

$ch = curl_init($url);
curl_setopt_array($ch, $conf);
//curl_setopt($ch, CURLOPT_URL, $url);
/*curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, '<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:displayname />
  </d:prop>
</d:propfind>');
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
 */
//curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
print_r (curl_exec($ch));
echo "\n";
curl_close($ch);
?>
