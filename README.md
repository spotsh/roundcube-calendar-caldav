CalDAV/iCAL Support for Roundcube Calendar
==========================================
This repository was forked from [roundcubemail-plugins-kolab](http://git.kolab.org/roundcubemail-plugins-kolab) and contains a modified version of the Roundcube calendar plugin that enables client support for CalDAV and iCAL calendar resources. We added a feature branch [feature_caldav](https://gitlab.awesome-it.de/kolab/roundcube-plugins/tree/feature_caldav) with the modified calendar plugin and we try to frequently merge the latest release tags from upstream. You can find further information and a short introduction to this plugin on our [website](http://awesome-it.de/2014/02/22/Kolab-CalDAV-iCAL/).

Requirements
============
* Roundcube 1.0-RC or higher
* Optional: Kolab 3.1 or higher

Installation
============
* Clone this repo and checkout the `feature_caldav` branch:

    ```bash
    $ cd /path/to/your/roundcube/
    $ git clone https://gitlab.awesome-it.de/kolab/roundcube-plugins.git plugins-caldav
    ```

* Replace the origin calendar plugin folder with the modified one:

    ```bash
    $ mv plugins/calendar plugins/calendar.orig
    $ ln -s plugins-caldav/plugins/calendar plugins/calendar
    ```

* Copy `plugins/calendar.orig/config.inc.php` to the new plugin folder and modify accordingly:

    ```bash
    $ cp plugins/calendar.orig/config.inc.php plugins/calendar/config.inc.php
    $ vi plugins/calendar/config.inc.php
    ```
* The calendar setting `calendar_driver` now accepts an array with calendar drivers you want to enable:

    ```php
    $config['calendar_driver'] = array("kolab", "caldav", "ical");
    ```

    Note that the very first array value is used a default driver e.g. for creating events via email if no calendar was chosen.
    Further you can drop the Kolab dependency of the calendar by remocing the `kolab` driver.

* It is always a good idea to set a new crypt key to be used for encryption of you CalDAV passwords:

    ```php
    $config['calendar_crypt_key'] = 'some_random_characters`;
    ```

* Update Roundcube's MySQL database:

    ```bash
    $ mysql -h <db-host> -u <db-user> -p <db-name> < /path/to/your/roundcube/plugins-caldav/plugins/calendar/drivers/database/SQL/mysql.initial.sql

    # For CalDAV support
    $ mysql -h <db-host> -u <db-user> -p <db-name> < /path/to/your/roundcube/plugins-caldav/plugins/calendar/drivers/caldav/SQL/mysql.initial.sql

    # For iCAL support
    $ mysql -h <db-host> -u <db-user> -p <db-name> < /path/to/your/roundcube/plugins-caldav/plugins/calendar/drivers/ical/SQL/mysql.initial.sql
    ```

* You should now be able to select one of your configured drivers when creating a new calendar.

Troubleshooting
===============

* Enabling debug mode in `config.inc.php` will output additional debug information to `/path/to/your/roundcube/logs/console`:

    ```php
    $config['calendar_caldav_debug'] = true;
    $config['calendar_ical_debug'] = true;
    ```

* If you find any bugs, please fill an issue in our [bug tracker](https://gitlab.awesome-it.de/kolab/roundcube-plugins/issues).

License
=======

Calendar Modificatons
---------------------

Copyright (C) 2014, Awesome Information Technology GbR <info@awesome-it.de>
 
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as
published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version.
 
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU Affero General Public License for more details.
 
You should have received a copy of the GNU Affero General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.

Kolab Plugins
-------------
See http://git.kolab.org/roundcubemail-plugins-kolab/tree/plugins/calendar/LICENSE.
