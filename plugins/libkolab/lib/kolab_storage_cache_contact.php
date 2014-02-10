<?php

/**
 * Kolab storage cache class for contact objects
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2013, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_storage_cache_contact extends kolab_storage_cache
{
    protected $extra_cols = array('type','name','firstname','surname','email');
    protected $binary_items = array(
        'photo'          => '|<photo><uri>[^;]+;base64,([^<]+)</uri></photo>|i',
        'pgppublickey'   => '|<key><uri>date:application/pgp-keys;base64,([^<]+)</uri></key>|i',
        'pkcs7publickey' => '|<key><uri>date:application/pkcs7-mime;base64,([^<]+)</uri></key>|i',
    );

    /**
     * Helper method to convert the given Kolab object into a dataset to be written to cache
     *
     * @override
     */
    protected function _serialize($object)
    {
        $sql_data = parent::_serialize($object);
        $sql_data['type'] = $object['_type'];

        // columns for sorting
        $sql_data['name']      = $object['name'] . $object['prefix'];
        $sql_data['firstname'] = $object['firstname'] . $object['middlename'] . $object['surname'];
        $sql_data['surname']   = $object['surname']   . $object['firstname']  . $object['middlename'];
        $sql_data['email']     = is_array($object['email']) ? $object['email'][0] : $object['email'];

        if (is_array($sql_data['email'])) {
            $sql_data['email'] = $sql_data['email']['address'];
        }

        return $sql_data;
    }
}