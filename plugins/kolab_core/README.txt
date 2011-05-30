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

Get Horde3 framework from http://www.horde.org/download/horde and put Horde directory
somewhere in include path. Install PEAR packages:

pear install Net_Socket
pear install Net_LDAP2
pear install Net_IMAP
pear install Net_DNS2
pear install Net_SMTP
pear install Mail_mimeDecode
pear install Auth_SASL


Configuration
-------------

Rename the config.inc.php.dist to config.inc.php within this plugin directory
and add the corresponding values for your local Kolab server.
