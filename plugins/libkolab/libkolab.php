<?php

/**
 * Kolab core library
 *
 * Plugin to setup a basic environment for the interaction with a Kolab server.
 * Other Kolab-related plugins will depend on it and can use the library classes
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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

class libkolab extends rcube_plugin
{
    static $http_requests = array();

    /**
     * Required startup method of a Roundcube plugin
     */
    public function init()
    {
        // load local config
        $this->load_config();

        $this->add_hook('storage_init', array($this, 'storage_init'));

        // extend include path to load bundled lib classes
        $include_path = $this->home . '/lib' . PATH_SEPARATOR . ini_get('include_path');
        set_include_path($include_path);

        $rcmail = rcube::get_instance();
        try {
            kolab_format::$timezone = new DateTimeZone($rcmail->config->get('timezone', 'GMT'));
        }
        catch (Exception $e) {
            rcube::raise_error($e, true);
            kolab_format::$timezone = new DateTimeZone('GMT');
        }
    }

    /**
     * Hook into IMAP FETCH HEADER.FIELDS command and request Kolab-specific headers
     */
    function storage_init($p)
    {
        $p['fetch_headers'] = trim($p['fetch_headers'] .' X-KOLAB-TYPE X-KOLAB-MIME-VERSION');
        return $p;
    }

    /**
     * Wrapper function to load and initalize the HTTP_Request2 Object
     *
     * @param string|Net_Url2 Request URL
     * @param string          Request method ('OPTIONS','GET','HEAD','POST','PUT','DELETE','TRACE','CONNECT')
     * @param array           Configuration for this Request instance, that will be merged
     *                        with default configuration
     *
     * @return HTTP_Request2 Request object
     */
    public static function http_request($url = '', $method = 'GET', $config = array())
    {
        $rcube       = rcube::get_instance();
        $http_config = (array) $rcube->config->get('kolab_http_request');

        // deprecated configuration options
        if (empty($$http_config)) {
            foreach (array('ssl_verify_peer', 'ssl_verify_host') as $option) {
                $value = $rcube->config->get('kolab_' . $option, true);
                if (is_bool($value)) {
                    $http_config[$option] = $value;
                }
            }
        }

        if (!empty($config)) {
            $http_config = array_merge($http_config, $config);
        }

        $key = md5(serialize($http_config));

        if (!($request = self::$http_requests[$key])) {
            // load HTTP_Request2
            require_once 'HTTP/Request2.php';

            try {
                $request = new HTTP_Request2();
                $request->setConfig($http_config);
            }
            catch (Exception $e) {
                rcube::raise_error($e, true, true);
            }

            // proxy User-Agent string
            $request->setHeader('user-agent', $_SERVER['HTTP_USER_AGENT']);

            self::$http_requests[$key] = $request;
        }

        // cleanup
        try {
            $request->setBody('');
            $request->setUrl($url);
            $request->setMethod($method);
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, true);
        }

        return $request;
    }
}
