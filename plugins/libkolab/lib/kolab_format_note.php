<?php

/**
 * Kolab Note model class
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

class kolab_format_note extends kolab_format
{
    public $CTYPE = 'application/x-vnd.kolab.note';
    public $CTYPEv2 = 'application/x-vnd.kolab.note';

    protected $objclass = 'Note';
    protected $read_func = 'readNote';
    protected $write_func = 'writeNote';


    /**
     * Set properties to the kolabformat object
     *
     * @param array  Object data as hash array
     */
    public function set(&$object)
    {
        // set common object properties
        parent::set($object);

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
        return $this->data || (is_object($this->obj) && $this->obj->isValid());
    }

    /**
     * Convert the Configuration object into a hash array data structure
     *
     * @param array Additional data for merge
     *
     * @return array  Config object data as hash array
     */
    public function to_array($data = array())
    {
        // return cached result
        if (!empty($this->data))
            return $this->data;

        // read common object props into local data object
        $object = parent::to_array($data);

        // TODO: read object properties

        $this->data = $object;
        return $this->data;
    }

}
