<?php

/**
 * Kolab Configuration data model class
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

class kolab_format_configuration extends kolab_format
{
    public $CTYPE = 'application/x-vnd.kolab.configuration';

    protected $read_func = 'kolabformat::readConfiguration';
    protected $write_func = 'kolabformat::writeConfiguration';


    function __construct($xmldata = null)
    {
        $this->obj = new Configuration;
        $this->xmldata = $xmldata;
    }

    /**
     * Set properties to the kolabformat object
     *
     * @param array  Object data as hash array
     */
    public function set(&$object)
    {
        $this->init();

        // set some automatic values if missing
#        if (!empty($object['uid']))
#            $this->obj->setUid($object['uid']);

        // TODO: set object propeties

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
    }

    /**
     *
     */
    public function is_valid()
    {
        return $this->data || (is_object($this->obj)/* && $this->obj->isValid()*/);
    }

    /**
     * Load data from old Kolab2 format
     */
    public function fromkolab2($record)
    {
        $object = array(
            'uid'     => $record['uid'],
            'changed' => $record['last-modification-date'],
        );

        $this->data = $object + $record;
    }

    /**
     * Convert the Configuration object into a hash array data structure
     *
     * @return array  Config object data as hash array
     */
    public function to_array()
    {
        // return cached result
        if (!empty($this->data))
            return $this->data;

        $this->init();

        // read object properties
        $object = array(
#            'uid'       => $this->obj->uid(),
#            'changed'   => $this->obj->lastModified(),
        );


        // TODO: read object properties

        $this->data = $object;
        return $this->data;
    }

    /**
     * Callback for kolab_storage_cache to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags()
    {
        $tags = array();

        if ($this->data['type'] == 'dictionary')
            $tags = array($this->data['language']);

        return $tags;
    }

}
