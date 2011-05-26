Kolab Integration Plugin README
-------------------------------

This plugin relies on classes from the Horde project. In order to have all
the required files available you need to install the following packages from
Horde:
	Horde_Framework
	Kolab_Format
	Kolab_Storage
	Horde_NLS
	Horde_DOM

This is best done using PEAR. Make sure that the local PEAR directory is in
the PHP isntall path and execute the following commands to install the
required packages:

pear channel-discover pear.horde.org

pear install horde/Horde_Framework
pear install horde/Horde_DOM
pear install horde/Horde_NLS
pear install horde/Horde_Share
pear install horde/Log
pear install horde/Kolab_Format
pear install horde/Kolab_Storage

WARNING: Above procedure installs Horde4 sources that doesn't work
with this plugin! Here's mine:

Get Horde3 framework from http://www.horde.org/download/horde and put Horde directory
somewhere in include path. Install PEAR packages:
pear install Net/Socket
pear install Net/LDAP2
pear install Net/IMAP
pear install Net/DNS2
pear install Net/SMTP
pear install Mail/MimeDecode
pear install Auth/SASL


Configuration
-------------

Rename the config.inc.php.dist to config.inc.php within this plugin directory
and add the corresponding values for your local Kolab server.
