<?php
include('bennu/lib/bennu.inc.php');
include('config.php');

header('Content-type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$nameofcalendar.'.ics"');

function parse_time($time)
{
	$hourSplit = split(':', $time);
	$hour = $hourSplit[0];
	$minute = substr($hourSplit[1], 0, 2);
	$is_pm = substr($hourSplit[1], -1) == 'p';
	if ($is_pm && $hour != 12)
	{
		$hour += 12;
	}
	else if (!$is_pm && $hour == 12)
	{
		$hour = 0;
	}
	return array($hour, $minute);
}

function tzmktime($hour, $minute, $second, $month, $day, $year)
{
	global $timezone;
	date_default_timezone_set($timezone);
	$time = mktime($hour, $minute, $second, $month, $day, $year);
	date_default_timezone_set('UTC');
	return $time;
}

function output_calendar($year, $cal)
{
	global $dtstamp, $calname, $nameofcalendar, $timeoutseconds;
	$eventCount = 0;
	$context = stream_context_create(array('http' => array('timeout' => $timeoutseconds)));
	$contents = @file_get_contents('http://my.calendars.net/'.$nameofcalendar.'/d01/01/'.$year.'?style=C&display=Y&positioning=A', false, $context);
	if (substr($contents, -7) === '</html>')
	{
		@file_put_contents('cache/'.$year.'.html', $contents);
	}
	else
	{
		$contents = @file_get_contents('cache/'.$year.'.html');
	}
	$contents = strip_tags($contents, '<td><tr><br><a>');
	$contents = str_replace('<bR>', "\n", $contents);
	if (strlen($calname) == 0)
	{
		preg_match('/<td colspan=17 align=center bgcolor="BBEEEE">(?<name>.*?)<\/td>/', $contents, $calendar);
		$calname = trim($calendar['name']);
	}
	preg_match_all('/<TR><TD.*?>\r\n(?<date>.*?)<\/td><td.*?>(?<weekday>.*?)<\/td>\r\n(?<events>.*?)<\/TR>/s', $contents, $days);
	$dayNumber = 0;
	for ($dayNumber = 0; $dayNumber < count($days['date']); $dayNumber++)
	{
		$curDate = date_parse(strip_tags($days['date'][$dayNumber]));
		$curDate['year'] = $year;
		$curEvents = $days['events'][$dayNumber];
		preg_match_all('/<td.*?>\r\n(?<nametime>.*?)<\/TD>(?:\r\n)*?<TD.*?>\r\n(?<description>.*?)<\/(?:TD|td)>/s', $curEvents, $dayEvents);
		for ($eventNumber = 0; $eventNumber < count($dayEvents['nametime']); $eventNumber++)
		{
			$curEvent = new iCalendar_event;
			$curNameTime = split("\r\n", trim($dayEvents['nametime'][$eventNumber]));
			switch (count($curNameTime))
			{
				case 1:
					$dateStart = date('Ymd', tzmktime(0, 0, 0, $curDate['month'], $curDate['day'], $curDate['year']));
					$dateEnd = date('Ymd', tzmktime(0, 0, 0, $curDate['month'], $curDate['day'] + 1, $curDate['year']));
					$curEvent->add_property('DTSTART', $dateStart, array('value' => 'DATE'));
					$curEvent->add_property('DTEND', $dateEnd, array('value' => 'DATE'));
					$curName = $curNameTime[0];
					break;
				case 2:
					$curStart = parse_time($curNameTime[0]);
					$dateStart = date('Ymd\THis\Z', tzmktime($curStart[0], $curStart[1], 0, $curDate['month'], $curDate['day'], $curDate['year']));
					$curEvent->add_property('DTSTART', $dateStart);
					$curEvent->add_property('DTEND', $dateStart);
					$curName = $curNameTime[1];
					break;
				case 3:
					$curStart = parse_time($curNameTime[0]);
					$curEnd = parse_time(substr($curNameTime[1], 1));
					$dateStart = date('Ymd\THis\Z', tzmktime($curStart[0], $curStart[1], 0, $curDate['month'], $curDate['day'], $curDate['year']));
					$dateEnd = date('Ymd\THis\Z', tzmktime($curEnd[0], $curEnd[1], 0, $curDate['month'], $curDate['day'], $curDate['year']));
					$curEvent->add_property('DTSTART', $dateStart);
					$curEvent->add_property('DTEND', $dateEnd);
					$curName = $curNameTime[2];
					break;
			}
			$curDescription = trim($dayEvents['description'][$eventNumber]);
			if (strpos($curName, 'href'))
			{
				preg_match('/<a href=(?<url>.*?)>(?<name>.*?)<\/a>/', $curName, $urlName);
				$curName = $urlName['name'];
				if ($curDescription == '.')
				{
					$curDescription = $urlName['url'];
				}
				else
				{
					$curDescription .= "\n".$urlName['url'];
				}
			}
			if ($curDescription != '.')
			{
				$curEvent->add_property('DESCRIPTION', $curDescription);
			}
			$curEvent->add_property('SUMMARY', $curName);
			$curEvent->add_property('DTSTAMP', $dtstamp);
			$curEvent->add_property('UID', sha1($curDate['year'].$curDate['month'].$curDate['day'].$eventNumber).'@'.$nameofcalendar.'.my.calendars.net');
			$cal->add_component($curEvent);
			$eventCount++;
		}
	}
	return $eventCount;
}

$now = getdate($_SERVER['REQUEST_TIME']);
$dtstamp = date('Ymd\THis\Z', tzmktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon'], $now['mday'], $now['year']));

date_default_timezone_set($timezone);

if (file_exists('cache/output.ics') && ($_SERVER['REQUEST_TIME'] - filemtime('cache/output.ics')) < 60 * $refreshminutes)
{
	echo @file_get_contents('cache/output.ics');
}
else
{
	$cal = new iCalendar;

	$count = 0;
	$year = date('Y');
	do
	{
		$count = output_calendar($year, $cal);
		$year++;
	} while ($count > 0);
	if ($count != -1)
	{
		$year = date('Y') - 1;
		do
		{
			$count = output_calendar($year, $cal);
			$year--;
		} while ($count > 0);
	}

	$cal->add_property('X-WR-CALNAME', $calname);
	$cal->add_property('X-WR-CALDESC', $calname);
	$cal->add_property('X-WR-TIMEZONE', $timezone);
	$cal->add_property('X-PUBLISHED-TTL', 'PT'.$refreshminutes.'M');
	$cal->add_property('PRODID', '-//David Maher/NONSGML Anatolia 2.0//EN');

	$bennu = $cal->serialize();
	$handle = @fopen('cache/output.ics', 'w');
	if ($handle)
	{
		fwrite($handle, $bennu);
		fclose($handle);
	}
	echo $bennu;
}

?>
