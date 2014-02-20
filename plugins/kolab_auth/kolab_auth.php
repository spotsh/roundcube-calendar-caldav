<?php

/**
 * Kolab Authentication (based on ldap_authentication plugin)
 *
 * Authenticates on LDAP server, finds canonized authentication ID for IMAP
 * and for new users creates identity based on LDAP information.
 *
 * Supports impersonate feature (login as another user). To use this feature
 * imap_auth_type/smtp_auth_type must be set to DIGEST-MD5 or PLAIN.
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011-2013, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_auth extends rcube_plugin
{
    static $ldap;
    private $username;
    private $data = array();

    public function init()
    {
        $rcmail = rcube::get_instance();

        $this->add_hook('authenticate', array($this, 'authenticate'));
        $this->add_hook('startup', array($this, 'startup'));
        $this->add_hook('user_create', array($this, 'user_create'));

        // Hook for password change
        $this->add_hook('password_ldap_bind', array($this, 'password_ldap_bind'));

        // Hooks related to "Login As" feature
        $this->add_hook('template_object_loginform', array($this, 'login_form'));
        $this->add_hook('storage_connect', array($this, 'imap_connect'));
        $this->add_hook('managesieve_connect', array($this, 'imap_connect'));
        $this->add_hook('smtp_connect', array($this, 'smtp_connect'));
        $this->add_hook('identity_form', array($this, 'identity_form'));

        // Hook to modify some configuration, e.g. ldap
        $this->add_hook('config_get', array($this, 'config_get'));

        // Hook to modify logging directory
        $this->add_hook('write_log', array($this, 'write_log'));
        $this->username = $_SESSION['username'];

        // Enable debug logs per-user, this enables logging only after
        // user has logged in
        if (!empty($_SESSION['username']) && $rcmail->config->get('kolab_auth_auditlog')) {
            $rcmail->config->set('debug_level', 1);
            $rcmail->config->set('devel_mode', true);
            $rcmail->config->set('smtp_log', true);
            $rcmail->config->set('log_logins', true);
            $rcmail->config->set('log_session', true);
            $rcmail->config->set('sql_debug', true);
            $rcmail->config->set('memcache_debug', true);
            $rcmail->config->set('imap_debug', true);
            $rcmail->config->set('ldap_debug', true);
            $rcmail->config->set('smtp_debug', true);
        }
    }

    public function startup($args)
    {
        $this->load_user_role_plugins_and_settings();

        return $args;
    }

    /**
     * Modify some configuration according to LDAP user record
     */
    public function config_get($args)
    {
        // Replaces ldap_vars (%dc, etc) in public kolab ldap addressbooks
        // config based on the users base_dn. (for multi domain support)
        if ($args['name'] == 'ldap_public' && !empty($args['result'])) {
            $this->load_config();

            $rcmail      = rcube::get_instance();
            $kolab_books = (array) $rcmail->config->get('kolab_auth_ldap_addressbooks');

            foreach ($args['result'] as $name => $config) {
                if (in_array($name, $kolab_books) || in_array('*', $kolab_books)) {
                    $args['result'][$name]['base_dn']        = self::parse_ldap_vars($config['base_dn']);
                    $args['result'][$name]['search_base_dn'] = self::parse_ldap_vars($config['search_base_dn']);
                    $args['result'][$name]['bind_dn']        = str_replace('%dn', $_SESSION['kolab_dn'], $config['bind_dn']);

                    if (!empty($config['groups'])) {
                        $args['result'][$name]['groups']['base_dn'] = self::parse_ldap_vars($config['groups']['base_dn']);
                    }
                }
            }
        }

        return $args;
    }

    /**
     * Modifies list of plugins and settings according to
     * specified LDAP roles
     */
    public function load_user_role_plugins_and_settings()
    {
        if (empty($_SESSION['user_roledns'])) {
            return;
        }

        $rcmail = rcube::get_instance();
        $this->load_config();


        // Example 'kolab_auth_role_plugins' =
        //
        //  Array(
        //      '<role_dn>' => Array('plugin1', 'plugin2'),
        //  );
        //
        // NOTE that <role_dn> may in fact be something like: 'cn=role,%dc'

        $role_plugins = $rcmail->config->get('kolab_auth_role_plugins');

        // Example $rcmail_config['kolab_auth_role_settings'] =
        //
        //  Array(
        //      '<role_dn>' => Array(
        //          '$setting' => Array(
        //              'mode' => '(override|merge)', (default: override)
        //              'value' => <>,
        //              'allow_override' => (true|false) (default: false)
        //          ),
        //      ),
        //  );
        //
        // NOTE that <role_dn> may in fact be something like: 'cn=role,%dc'

        $role_settings = $rcmail->config->get('kolab_auth_role_settings');

        if (!empty($role_plugins)) {
            foreach ($role_plugins as $role_dn => $plugins) {
                $role_dn = self::parse_ldap_vars($role_dn);
                if (!empty($role_plugins[$role_dn])) {
                    $role_plugins[$role_dn] = array_unique(array_merge((array)$role_plugins[$role_dn], $plugins));
                } else {
                    $role_plugins[$role_dn] = $plugins;
                }
            }
        }

        if (!empty($role_settings)) {
            foreach ($role_settings as $role_dn => $settings) {
                $role_dn = self::parse_ldap_vars($role_dn);
                if (!empty($role_settings[$role_dn])) {
                    $role_settings[$role_dn] = array_merge((array)$role_settings[$role_dn], $settings);
                } else {
                    $role_settings[$role_dn] = $settings;
                }
            }
        }

        foreach ($_SESSION['user_roledns'] as $role_dn) {
            if (!empty($role_settings[$role_dn]) && is_array($role_settings[$role_dn])) {
                foreach ($role_settings[$role_dn] as $setting_name => $setting) {
                    if (!isset($setting['mode'])) {
                        $setting['mode'] = 'override';
                    }

                    if ($setting['mode'] == "override") {
                        $rcmail->config->set($setting_name, $setting['value']);
                    } elseif ($setting['mode'] == "merge") {
                        $orig_setting = $rcmail->config->get($setting_name);

                        if (!empty($orig_setting)) {
                            if (is_array($orig_setting)) {
                                $rcmail->config->set($setting_name, array_merge($orig_setting, $setting['value']));
                            }
                        } else {
                            $rcmail->config->set($setting_name, $setting['value']);
                        }
                    }

                    $dont_override = (array) $rcmail->config->get('dont_override');

                    if (empty($setting['allow_override'])) {
                        $rcmail->config->set('dont_override', array_merge($dont_override, array($setting_name)));
                    }
                    else {
                        if (in_array($setting_name, $dont_override)) {
                            $_dont_override = array();
                            foreach ($dont_override as $_setting) {
                                if ($_setting != $setting_name) {
                                    $_dont_override[] = $_setting;
                                }
                            }
                            $rcmail->config->set('dont_override', $_dont_override);
                        }
                    }

                    if ($setting_name == 'skin') {
                        if ($rcmail->output->type == 'html') {
                            $rcmail->output->set_skin($setting['value']);
                            $rcmail->output->set_env('skin', $setting['value']);
                        }
                    }
                }
            }

            if (!empty($role_plugins[$role_dn])) {
                foreach ((array)$role_plugins[$role_dn] as $plugin) {
                    $this->require_plugin($plugin);
                }
            }
        }
    }

    public function write_log($args)
    {
        $rcmail = rcube::get_instance();

        if (!$rcmail->config->get('kolab_auth_auditlog', false)) {
            return $args;
        }

        // log_driver == 'file' is assumed here
        $log_dir  = $rcmail->config->get('log_dir', RCUBE_INSTALL_PATH . 'logs');

        // Append original username + target username for audit-logging
        if ($rcmail->config->get('kolab_auth_auditlog') && !empty($_SESSION['kolab_auth_admin'])) {
            $args['dir'] = $log_dir . '/' . strtolower($_SESSION['kolab_auth_admin']) . '/' . strtolower($this->username);

            // Attempt to create the directory
            if (!is_dir($args['dir'])) {
                @mkdir($args['dir'], 0750, true);
            }
        }
        // Define the user log directory if a username is provided
        else if ($rcmail->config->get('per_user_logging') && !empty($this->username)) {
            $user_log_dir = $log_dir . '/' . strtolower($this->username);
            if (is_writable($user_log_dir)) {
                $args['dir'] = $user_log_dir;
            }
            else if ($args['name'] != 'errors') {
                $args['abort'] = true;  // don't log if unauthenticed
            }
        }

        return $args;
    }

    /**
     * Sets defaults for new user.
     */
    public function user_create($args)
    {
        if (!empty($this->data['user_email'])) {
            // addresses list is supported
            if (array_key_exists('email_list', $args)) {
                $email_list = array_unique($this->data['user_email']);

                // add organization to the list
                if (!empty($this->data['user_organization'])) {
                    foreach ($email_list as $idx => $email) {
                        $email_list[$idx] = array(
                            'organization' => $this->data['user_organization'],
                            'email'        => $email,
                        );
                    }
                }

                $args['email_list'] = $email_list;
            }
            else {
                $args['user_email'] = $this->data['user_email'][0];
            }
        }

        if (!empty($this->data['user_name'])) {
            $args['user_name'] = $this->data['user_name'];
        }

        return $args;
    }

    /**
     * Modifies login form adding additional "Login As" field
     */
    public function login_form($args)
    {
        $this->load_config();
        $this->add_texts('localization/');

        $rcmail      = rcube::get_instance();
        $admin_login = $rcmail->config->get('kolab_auth_admin_login');
        $group       = $rcmail->config->get('kolab_auth_group');
        $role_attr   = $rcmail->config->get('kolab_auth_role');

        // Show "Login As" input
        if (empty($admin_login) || (empty($group) && empty($role_attr))) {
            return $args;
        }

        $input = new html_inputfield(array('name' => '_loginas', 'id' => 'rcmloginas',
            'type' => 'text', 'autocomplete' => 'off'));
        $row = html::tag('tr', null,
            html::tag('td', 'title', html::label('rcmloginas', Q($this->gettext('loginas'))))
            . html::tag('td', 'input', $input->show(trim(rcube_utils::get_input_value('_loginas', rcube_utils::INPUT_POST))))
        );
        $args['content'] = preg_replace('/<\/tbody>/i', $row . '</tbody>', $args['content']);

        return $args;
    }

    /**
     * Find user credentials In LDAP.
     */
    public function authenticate($args)
    {
        // get username and host
        $host    = $args['host'];
        $user    = $args['user'];
        $pass    = $args['pass'];
        $loginas = trim(rcube_utils::get_input_value('_loginas', rcube_utils::INPUT_POST));

        if (empty($user) || empty($pass)) {
            $args['abort'] = true;
            return $args;
        }

        // temporarily set the current username to the one submitted
        $this->username = $user;

        $ldap = self::ldap();
        if (!$ldap || !$ldap->ready) {
            $args['abort'] = true;
            $args['kolab_ldap_error'] = true;
            $message = sprintf(
                    'Login failure for user %s from %s in session %s (error %s)',
                    $user,
                    rcube_utils::remote_ip(),
                    session_id(),
                    "LDAP not ready"
                );

            rcube::write_log('userlogins', $message);

            return $args;
        }

        // Find user record in LDAP
        $record = $ldap->get_user_record($user, $host);

        if (empty($record)) {
            $args['abort'] = true;
            $message = sprintf(
                    'Login failure for user %s from %s in session %s (error %s)',
                    $user,
                    rcube_utils::remote_ip(),
                    session_id(),
                    "No user record found"
                );

            rcube::write_log('userlogins', $message);

            return $args;
        }

        $rcmail      = rcube::get_instance();
        $admin_login = $rcmail->config->get('kolab_auth_admin_login');
        $admin_pass  = $rcmail->config->get('kolab_auth_admin_password');
        $login_attr  = $rcmail->config->get('kolab_auth_login');
        $name_attr   = $rcmail->config->get('kolab_auth_name');
        $email_attr  = $rcmail->config->get('kolab_auth_email');
        $org_attr    = $rcmail->config->get('kolab_auth_organization');
        $role_attr   = $rcmail->config->get('kolab_auth_role');
        $imap_attr   = $rcmail->config->get('kolab_auth_mailhost');

        if (!empty($role_attr) && !empty($record[$role_attr])) {
            $_SESSION['user_roledns'] = (array)($record[$role_attr]);
        }

        if (!empty($imap_attr) && !empty($record[$imap_attr])) {
            $default_host = $rcmail->config->get('default_host');
            if (!empty($default_host)) {
                rcube::write_log("errors", "Both default host and kolab_auth_mailhost set. Incompatible.");
            } else {
                $args['host'] = "tls://" . $record[$imap_attr];
            }
        }

        // Login As...
        if (!empty($loginas) && $admin_login) {
            // Authenticate to LDAP
            $result = $ldap->bind($record['dn'], $pass);

            if (!$result) {
                $args['abort'] = true;
                $message = sprintf(
                        'Login failure for user %s from %s in session %s (error %s)',
                        $user,
                        rcube_utils::remote_ip(),
                        session_id(),
                        "Unable to bind with '" . $record['dn'] . "'"
                    );

                rcube::write_log('userlogins', $message);

                return $args;
            }

            // check if the original user has/belongs to administrative role/group
            $isadmin = false;
            $group   = $rcmail->config->get('kolab_auth_group');
            $role_dn = $rcmail->config->get('kolab_auth_role_value');

            // check role attribute
            if (!empty($role_attr) && !empty($role_dn) && !empty($record[$role_attr])) {
                $role_dn = $ldap->parse_vars($role_dn, $user, $host);
                if (in_array($role_dn, (array)$record[$role_attr])) {
                    $isadmin = true;
                }
            }

            // check group
            if (!$isadmin && !empty($group)) {
                $groups = $ldap->get_user_groups($record['dn'], $user, $host);
                if (in_array($group, $groups)) {
                    $isadmin = true;
                }
            }

            // Save original user login for log (see below)
            if ($login_attr) {
                $origname = is_array($record[$login_attr]) ? $record[$login_attr][0] : $record[$login_attr];
            }
            else {
                $origname = $user;
            }

            $record = null;

            // user has the privilage, get "login as" user credentials
            if ($isadmin) {
                $record = $ldap->get_user_record($loginas, $host);
            }

            if (empty($record)) {
                $args['abort'] = true;
                $message = sprintf(
                        'Login failure for user %s (as user %s) from %s in session %s (error %s)',
                        $user,
                        $loginas,
                        rcube_utils::remote_ip(),
                        session_id(),
                        "No user record found for '" . $loginas . "'"
                    );

                rcube::write_log('userlogins', $message);

                return $args;
            }

            $args['user'] = $this->username = $loginas;

            // Mark session to use SASL proxy for IMAP authentication
            $_SESSION['kolab_auth_admin']    = strtolower($origname);
            $_SESSION['kolab_auth_login']    = $rcmail->encrypt($admin_login);
            $_SESSION['kolab_auth_password'] = $rcmail->encrypt($admin_pass);
        }

        // Store UID and DN of logged user in session for use by other plugins
        $_SESSION['kolab_uid'] = is_array($record['uid']) ? $record['uid'][0] : $record['uid'];
        $_SESSION['kolab_dn']  = $record['dn'];

        // Store LDAP replacement variables used for current user
        // This improves performance of load_user_role_plugins_and_settings()
        // which is executed on every request (via startup hook) and where
        // we don't like to use LDAP (connection + bind + search)
        $_SESSION['kolab_auth_vars'] = $ldap->get_parse_vars();

        // Set user login
        if ($login_attr) {
            $this->data['user_login'] = is_array($record[$login_attr]) ? $record[$login_attr][0] : $record[$login_attr];
        }
        if ($this->data['user_login']) {
            $args['user'] = $this->username = $this->data['user_login'];
        }

        // User name for identity (first log in)
        foreach ((array)$name_attr as $field) {
            $name = is_array($record[$field]) ? $record[$field][0] : $record[$field];
            if (!empty($name)) {
                $this->data['user_name'] = $name;
                break;
            }
        }
        // User email(s) for identity (first log in)
        foreach ((array)$email_attr as $field) {
            $email = is_array($record[$field]) ? array_filter($record[$field]) : $record[$field];
            if (!empty($email)) {
                $this->data['user_email'] = array_merge((array)$this->data['user_email'], (array)$email);
            }
        }
        // Organization name for identity (first log in)
        foreach ((array)$org_attr as $field) {
            $organization = is_array($record[$field]) ? $record[$field][0] : $record[$field];
            if (!empty($organization)) {
                $this->data['user_organization'] = $organization;
                break;
            }
        }

        // Log "Login As" usage
        if (!empty($origname)) {
            rcube::write_log('userlogins', sprintf('Admin login for %s by %s from %s',
                $args['user'], $origname, rcube_utils::remote_ip()));
        }

        // load per-user settings/plugins
        $this->load_user_role_plugins_and_settings();

        return $args;
    }

    /**
     * Set user DN for password change (password plugin with ldap_simple driver)
     */
    public function password_ldap_bind($args)
    {
        $args['user_dn'] = $_SESSION['kolab_dn'];

        $rcmail = rcube::get_instance();

        $rcmail->config->set('password_ldap_method', 'user');

        return $args;
    }

    /**
     * Sets SASL Proxy login/password for IMAP and Managesieve auth
     */
    public function imap_connect($args)
    {
        if (!empty($_SESSION['kolab_auth_admin'])) {
            $rcmail      = rcube::get_instance();
            $admin_login = $rcmail->decrypt($_SESSION['kolab_auth_login']);
            $admin_pass  = $rcmail->decrypt($_SESSION['kolab_auth_password']);

            $args['auth_cid'] = $admin_login;
            $args['auth_pw']  = $admin_pass;
        }

        return $args;
    }

    /**
     * Sets SASL Proxy login/password for SMTP auth
     */
    public function smtp_connect($args)
    {
        if (!empty($_SESSION['kolab_auth_admin'])) {
            $rcmail      = rcube::get_instance();
            $admin_login = $rcmail->decrypt($_SESSION['kolab_auth_login']);
            $admin_pass  = $rcmail->decrypt($_SESSION['kolab_auth_password']);

            $args['options']['smtp_auth_cid'] = $admin_login;
            $args['options']['smtp_auth_pw']  = $admin_pass;
        }

        return $args;
    }

    /**
     * Hook to replace the plain text input field for email address by a drop-down list
     * with all email addresses (including aliases) from this user's LDAP record.
     */
    public function identity_form($args)
    {
        $rcmail      = rcube::get_instance();
        $ident_level = intval($rcmail->config->get('identities_level', 0));

        // do nothing if email address modification is disabled
        if ($ident_level == 1 || $ident_level == 3) {
            return $args;
        }

        $ldap = self::ldap();
        if (!$ldap || !$ldap->ready || empty($_SESSION['kolab_dn'])) {
            return $args;
        }

        $emails      = array();
        $user_record = $ldap->get_record($_SESSION['kolab_dn']);

        foreach ((array)$rcmail->config->get('kolab_auth_email', array()) as $col) {
            $values = rcube_addressbook::get_col_values($col, $user_record, true);
            if (!empty($values))
                $emails = array_merge($emails, array_filter($values));
        }

        // kolab_delegation might want to modify this addresses list
        $plugin = $rcmail->plugins->exec_hook('kolab_auth_emails', array('emails' => $emails));
        $emails = $plugin['emails'];

        if (!empty($emails)) {
            $args['form']['addressing']['content']['email'] = array(
                'type' => 'select',
                'options' => array_combine($emails, $emails),
            );
        }

        return $args;
    }

    /**
     * Initializes LDAP object and connects to LDAP server
     */
    public static function ldap()
    {
        if (self::$ldap) {
            return self::$ldap;
        }

        $rcmail = rcube::get_instance();

        // $this->load_config();
        // we're in static method, load config manually
        $fpath = $rcmail->plugins->dir . '/kolab_auth/config.inc.php';
        if (is_file($fpath) && !$rcmail->config->load_from_file($fpath)) {
            rcube::raise_error(array(
                'code' => 527, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Failed to load config from $fpath"), true, false);
        }

        $addressbook = $rcmail->config->get('kolab_auth_addressbook');

        if (!is_array($addressbook)) {
            $ldap_config = (array)$rcmail->config->get('ldap_public');
            $addressbook = $ldap_config[$addressbook];
        }

        if (empty($addressbook)) {
            return null;
        }

        require_once __DIR__ . '/kolab_auth_ldap.php';

        self::$ldap = new kolab_auth_ldap($addressbook);

        return self::$ldap;
    }

    /**
     * Parses LDAP DN string with replacing supported variables.
     * See kolab_auth_ldap::parse_vars()
     *
     * @param string $str LDAP DN string
     *
     * @return string Parsed DN string
     */
    public static function parse_ldap_vars($str)
    {
        if (!empty($_SESSION['kolab_auth_vars'])) {
            $str = strtr($str, $_SESSION['kolab_auth_vars']);
        }

        return $str;
    }
}
