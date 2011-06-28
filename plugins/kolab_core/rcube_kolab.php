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

        // get password of logged user
        $pwd = $rcmail->decrypt($_SESSION['password']);

        // load ldap credentials from local config
        $conf['kolab'] = (array) $rcmail->config->get('kolab');

        // Set global Horde config (e.g. Cache settings)
        if (!empty($conf['kolab']['global'])) {
            $conf = array_merge($conf, $conf['kolab']['global']);
            unset($conf['kolab']['global']);
        }

        // Set Horde configuration (for cache db)
        $dsnw = $rcmail->config->get('db_dsnw');
        $dsnr = $rcmail->config->get('db_dsnr');

        $conf['sql'] = MDB2::parseDSN($dsnw);
        $conf['sql']['charset'] = 'utf-8';

        if (!empty($dsnr) && $dsnr != $dsnw) {
            $conf['sql']['read'] = MDB2::parseDSN($dsnr);
            $conf['sql']['read']['charset'] = 'utf-8';
            $conf['sql']['splitread'] = true;
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
     *
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
     *
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
     *
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

    /**
     * Deletes IMAP folder
     *
     * @param string $name Folder name (UTF7-IMAP)
     *
     * @return bool True on success, false on failure
     */
    public static function folder_delete($name)
    {
        self::setup();
        $kolab  = Kolab_List::singleton();

        $folder = $kolab->getFolder($name);
        $result = $folder->delete();

        if (is_a($result, 'PEAR_Error')) {
            return false;
        }

        return true;
    }

    /**
     * Creates IMAP folder
     *
     * @param string $name    Folder name (UTF7-IMAP)
     * @param string $type    Folder type
     * @param bool   $default True if older is default (for specified type)
     *
     * @return bool True on success, false on failure
     */
    public static function folder_create($name, $type=null, $default=false)
    {
        self::setup();
        $kolab  = Kolab_List::singleton();

        $folder = new Kolab_Folder();
        $folder->setList($kolab);
        $folder->setFolder($name);

        $result = $folder->save(array(
            'type' => $type,
            'default' => $default,
        ));

        if (is_a($result, 'PEAR_Error')) {
            return false;
        }

        return true;
    }

    /**
     * Renames IMAP folder
     *
     * @param string $oldname Old folder name (UTF7-IMAP)
     * @param string $newname New folder name (UTF7-IMAP)
     *
     * @return bool True on success, false on failure
     */
    public static function folder_rename($oldname, $newname)
    {
        self::setup();
        $kolab  = Kolab_List::singleton();

        $folder = $kolab->getFolder($oldname);
        $folder->setFolder($newname);
        $result = $kolab->rename($folder);

        if (is_a($result, 'PEAR_Error')) {
            return false;
        }

        return true;
    }

    /**
     * Getter for human-readable name of Kolab object (folder)
     * See http://wiki.kolab.org/UI-Concepts/Folder-Listing for reference
     *
     * @param string $folder    IMAP folder name (UTF7-IMAP)
     * @param string $namespace Will be set to namespace name of the folder
     *
     * @return string Name of the folder-object
     */
    public static function object_name($folder, &$namespace=null)
    {
        $rcmail = rcmail::get_instance();
        $rcmail->imap_init();

        $namespace = $rcmail->imap->get_namespace();
        $found     = false;

        if (!empty($namespace['shared'])) {
            foreach ($namespace['shared'] as $ns) {
                if (strlen($ns[0]) && strpos($folder, $ns[0]) === 0) {
                    $prefix = '';
                    $folder = substr($folder, strlen($ns[0]));
                    $delim  = $ns[1];
                    $found  = true;
                    $namespace = 'shared';
                    break;
                }
            }
        }
        if (!$found && !empty($namespace['other'])) {
            foreach ($namespace['other'] as $ns) {
                if (strlen($ns[0]) && strpos($folder, $ns[0]) === 0) {
                    // remove namespace prefix
                    $folder = substr($folder, strlen($ns[0]));
                    $delim  = $ns[1];
                    // get username
                    $pos    = strpos($folder, $delim);
                    $prefix = '('.substr($folder, 0, $pos).') ';
                    $found  = true;
                    $namespace = 'other';
                    break;
                }
            }
        }
        if (!$found && !empty($namespace['personal'])) {
            foreach ($namespace['personal'] as $ns) {
                if (strlen($ns[0]) && strpos($folder, $ns[0]) === 0) {
                    // remove namespace prefix
                    $folder = substr($folder, strlen($ns[0]));
                    $prefix = '';
                    $delim  = $ns[1];
                    $found  = true;
                    $namespace = 'personal';
                    break;
                }
            }
        }

        if (empty($delim))
            $delim = $rcmail->imap->get_hierarchy_delimiter();

        $folder = rcube_charset_convert($folder, 'UTF7-IMAP');
        $folder = str_replace($delim, ' &raquo; ', $folder);

        if ($prefix)
            $folder = $prefix . ' ' . $folder;

        return $folder;
    }

    /**
     * Getter for the name of the namespace to which the IMAP folder belongs
     *
     * @param string $folder    IMAP folder name (UTF7-IMAP)
     *
     * @return string Name of the namespace (personal, other, shared)
     */
    public static function folder_namespace($folder)
    {
        $rcmail = rcmail::get_instance();
        $rcmail->imap_init();

        $namespace = $rcmail->imap->get_namespace();

        if (!empty($namespace)) {
            foreach ($namespace as $nsname => $nsvalue) {
                if (in_array($nsname, array('personal', 'other', 'shared')) && !empty($nsvalue)) {
                    foreach ($nsvalue as $ns) {
                        if (strlen($ns[0]) && strpos($folder, $ns[0]) === 0) {
                            return $namespace = $nsname;
                        }
                    }
                }
            }
        }

        return 'personal';
    }

}
