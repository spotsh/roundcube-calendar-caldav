#!/bin/sh

# Copy Horde_Date_Recurrence classes and dependencies to stdout.
# This will create a standalone copy of the classes requried for date recurrence computation.

SRCDIR=$1

if [ ! -d "$SRCDIR" ]; then
  echo "Usage: get_horde_date.sh SRCDIR"
  echo "Please enter a valid source directory of the Horde libs"
  exit 1
fi

echo "<?php

/**
 * This is a concatenated copy of the following files:
 *   Horde/Date/Utils.php, Horde/Date/Recurrence.php
 * Pull the latest version of these files from the PEAR channel of the Horde
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
"

sed 's/<?php//; s/?>//' $SRCDIR/Date/Utils.php
echo "\n"
sed 's/<?php//; s/?>//' $SRCDIR/Date/Recurrence.php | sed -E "s/Horde_String::/strto/"
echo "\n"

