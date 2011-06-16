<?php

ini_set('error_reporting', E_ALL&~(E_DEPRECATED | E_NOTICE));

require_once 'Horde/Kolab/Storage/List.php';
require_once 'Horde/Kolab/Format.php';
require_once 'Horde/Auth.php';
require_once 'Horde/Auth/kolab.php';
require_once 'Horde/Perms.php';

/**
 * Glue class to handle access to the Kolab data using the Kolab_* classes
 * from the Horde project.
 *
 * @author Thomas Bruederli
 */
class rcube_kolab
{
    private static $horde_auth;
    private static $ready = false;


    /**
     * Setup the environment needed by the Kolab_* classes to access Kolab data
     */
    public static function setup()
    {
        global $conf;

        // setup already done
        if (self::$horde_auth)
            return;

        $rcmail = rcmail::get_instance();

        // Set Horde configuration (for cache db)
        $dsnw = MDB2::parseDSN($rcmail->config->get('db_dsnw'));
        $dsnr = MDB2::parseDSN($rcmail->config->get('db_dsnr'));

        $conf['sql'] = MDB2::parseDSN($dsnw);
        $conf['sql']['charset'] = 'utf-8';

        if (!empty($dsnr) && $dsnr != $dsnw) {
            $conf['sql']['read'] = MDB2::parseDSN($dsnr);
            $conf['sql']['read']['charset'] = 'utf-8';
            $conf['sql']['splitread'] = true;
        }

        // get password of logged user
        $pwd = $rcmail->decrypt($_SESSION['password']);

        // load ldap credentials from local config
        $conf['kolab'] = (array) $rcmail->config->get('kolab');

        // Set global Horde config (e.g. Cache settings)
        if (!empty($conf['kolab']['global'])) {
            $conf = array_merge($conf, $conf['kolab']['global']);
            unset($conf['kolab']['global']);
        }

        // Re-set LDAP/IMAP host config
        $ldap = array('server' => 'ldap://' . $_SESSION['imap_host'] . ':389');
        $imap = array('server' => $_SESSION['imap_host'], 'port' => $_SESSION['imap_port']);

        $conf['kolab']['ldap'] = array_merge($ldap, (array)$conf['kolab']['ldap']);
        $conf['kolab']['imap'] = array_merge($imap, (array)$conf['kolab']['imap']);

        // pass the current IMAP authentication credentials to the Horde auth system
        self::$horde_auth = Auth::singleton('kolab');
        if (self::$horde_auth->authenticate($_SESSION['username'], array('password' => $pwd), false)) {
            $_SESSION['__auth'] = array(
                'authenticated' => true,
                'userId' => $_SESSION['username'],
                'timestamp' => time(),
                'remote_addr' => $_SERVER['REMOTE_ADDR'],
            );
            Auth::setCredential('password', $pwd);
            self::$ready = true;
        }

        NLS::setCharset('UTF-8');
        String::setDefaultCharset('UTF-8');
    }

    /**
     * Get instance of a Kolab (XML) format object
     *
     * @param string Data type (contact,event,task,note)
     * @return object Horde_Kolab_Format_XML The format object
     */
    public static function get_format($type)
    {
      self::setup();
      return Horde_Kolab_Format::factory('XML', $type);
    }

    /**
     * Get a list of storage folders for the given data type
     *
     * @param string Data type to list folders for (contact,event,task,note)
     * @return array List of Kolab_Folder objects (folder names in UTF7-IMAP)
     */
    public static function get_folders($type)
    {
        self::setup();
        $kolab = Kolab_List::singleton();
        return self::$ready ? $kolab->getByType($type) : array();
    }

    /**
     * Get storage object for read/write access to the Kolab backend
     *
     * @param string IMAP folder to access (UTF7-IMAP)
     * @param string Object type to deal with (leave empty for auto-detection using annotations)
     * @return object Kolab_Data The data storage object
     */
    public static function get_storage($folder, $data_type = null)
    {
        self::setup();
        $kolab = Kolab_List::singleton();
        return self::$ready ? $kolab->getFolder($folder)->getData($data_type) : null;
    }

    /**
     * Cleanup session data when done
     */
    public static function shutdown()
    {
        // unset auth data from session. no need to store it persistantly
        if (isset($_SESSION['__auth']))
            unset($_SESSION['__auth']);
    }

    /**
     * Creates folder ID from folder name
     *
     * @param string $folder Folder name (UTF7-IMAP)
     *
     * @return string Folder ID string
     */
    public static function folder_id($folder)
    {
        return asciiwords(strtr($folder, '/.', '--'));
    }

}
