#!/bin/sh

# Copy Horde_Date_Recurrence classes and dependencies to the given target directory.
# This will create a standalone copy of the classes requried for date recurrence computation.

SRCDIR=$1
DESTDIR=$2
BINDIR=`dirname $0`

if [ ! -d "$SRCDIR" -o ! -d "$DESTDIR" ]; then
  echo "Usage: get_horde_date.sh SRCDIR DESTDIR"
  echo "Please enter valid source and destination directories for the Horde libs"
  exit 1
fi


# concat Date.php and Date/Utils.php
HORDE_DATE="$DESTDIR/Horde_Date.php"

echo "<?php

/**
 * This is a concatenated copy of the following files:
 *   Horde/Date.php, Horde/Date/Utils.php
 * Pull the latest version of these files from the PEAR channel of the Horde
 * project at http://pear.horde.org by installing the Horde_Date package.
 */
" > $HORDE_DATE

patch $SRCDIR/Date.php $BINDIR/Date_last_weekday.diff --output=$HORDE_DATE.patched
sed 's/<?php//; s/?>//' $HORDE_DATE.patched >> $HORDE_DATE
sed 's/<?php//; s/?>//' $SRCDIR/Date/Utils.php >> $HORDE_DATE

# copy and patch Date/Recurrence.php
HORDE_DATE_RECURRENCE="$DESTDIR/Horde_Date_Recurrence.php"

echo "<?php

/**
 * This is a modified copy of Horde/Date/Recurrence.php
 * Pull the latest version of this file from the PEAR channel of the Horde
 * project at http://pear.horde.org by installing the Horde_Date package.
 */

if (!class_exists('Horde_Date'))
	require_once(dirname(__FILE__) . '/Horde_Date.php');

// minimal required implementation of Horde_Date_Translation to avoid a huge dependency nightmare
class Horde_Date_Translation
{
	function t(\$arg) { return \$arg; }
	function ngettext(\$sing, \$plur, \$num) { return (\$num > 1 ? \$plur : \$sing); }
}
" > $HORDE_DATE_RECURRENCE

patch $SRCDIR/Date/Recurrence.php $BINDIR/Date_Recurrence_weekday.diff --output=$HORDE_DATE_RECURRENCE.patched
sed 's/<?php//; s/?>//' $HORDE_DATE_RECURRENCE.patched >> $HORDE_DATE_RECURRENCE

# remove dependency to Horde_String
sed -i '' "s/Horde_String::/strto/" $HORDE_DATE_RECURRENCE

rm $DESTDIR/Horde_Date*.patched


