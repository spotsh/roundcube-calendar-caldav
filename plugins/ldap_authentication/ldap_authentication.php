<?php

/**
 * LDAP Authentication
 *
 * Authenticate on LDAP server, finds canonized authentication ID for IMAP
 * and for new users create identity based on LDAP information.
 *
 * @version 0.1
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011, Kolab Systems AG <contact@kolabsys.com>
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

class ldap_authentication extends rcube_plugin
{
    public $task = 'login';

    private $ldap;
    private $data = array();

	function init()
	{
		$this->add_hook('authenticate', array($this, 'authenticate'));
		$this->add_hook('user_create', array($this, 'user_create'));
	}

	function user_create($args)
	{
		if (!empty($this->data['user_email']))
    		$args['user_email'] = $this->data['user_email'];
		if (!empty($this->data['user_name']))
	    	$args['user_name'] = $this->data['user_name'];
		if (!empty($this->data['user_alias']))
	    	$args['user_alias'] = $this->data['user_alias'];

		return $args;
	}

    function authenticate($args)
    {
        if ($this->init_ldap()) {
            $rcmail = rcmail::get_instance();
            $filter = $rcmail->config->get('ldap_authentication_filter');
            $domain = $rcmail->config->get('username_domain');

            // get username and host
            $user = $args['user'];
            $host = rcube_parse_host($args['host']);

            if (!empty($domain) && strpos($user, '@') === false) {
                if (is_array($domain) && isset($domain[$args['host']]))
                    $user .= '@'.rcube_parse_host($domain[$host], $host);
                else if (is_string($domain))
                    $user .= '@'.rcube_parse_host($domain, $host);
            }

            // replace variables in filter
            list($u, $d) = explode('@', $user);
            $dc = 'dc='.strtr($d, array('.' => ',dc=')); // hierarchal domain string
            $replaces = array('%dc' => $dc, '%d' => $d, '%fu' => $user, '%u' => $u);

            $filter = strtr($filter, $replaces);

            // get record
            $this->ldap->set_filter($filter);
            $results = $this->ldap->list_records();

            if (count($results->records) == 1) {
                $record = $results->records[0];

                $login_attr = $rcmail->config->get('ldap_authentication_login');
                $alias_attr = $rcmail->config->get('ldap_authentication_alias');
                $name_attr  = $rcmail->config->get('ldap_authentication_name');

                if ($login_attr)
                    $this->data['user_login'] = is_array($record[$login_attr]) ? $record[$login_attr][0] : $record[$login_attr];
                if ($alias_attr)
                    $this->data['user_alias'] = is_array($record[$alias_attr]) ? $record[$alias_attr][0] : $record[$alias_attr];
                if ($name_attr)
                    $this->data['user_name'] = is_array($record[$name_attr]) ? $record[$name_attr][0] : $record[$name_attr];

                if ($this->data['user_login'])
                    $args['user'] = $this->data['user_login'];
            }
        }

        return $args;
    }

    private function init_ldap()
    {
        if ($this->ldap)
            return $this->ldap->ready;

        $this->load_config();
        $rcmail = rcmail::get_instance();

        $addressbook = $rcmail->config->get('ldap_authentication_addressbook');

        if (!is_array($addressbook)) {
            $ldap_config = (array)$rcmail->config->get('ldap_public');
            $addressbook = $ldap_config[$addressbook];
        }

        if (empty($addressbook)) {
            return false;
        }

        $this->ldap = new ldap_authentication_ldap_backend(
            $addressbook,
            $rcmail->config->get('ldap_debug'),
            $rcmail->config->mail_domain($_SESSION['imap_host'])
        );

        return $this->ldap->ready;
    }
}

class ldap_authentication_ldap_backend extends rcube_ldap
{
    function set_filter($filter)
    {
        if ($filter)
            $this->prop['filter'] = $filter;
    }
}
