<?php

/**
 * Kolab Event model class
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

class kolab_format_event extends kolab_format
{
    public $CTYPE = 'application/calendar+xml';

    function __construct()
    {
        $obj = new Event;
    }

    public function load($xml)
    {
        $this->obj = kolabformat::readEvent($xml, false);
    }

    public function write()
    {
        $xml = kolabformat::writeEvent($this->obj);
        parent::update_uid();
        return $xml;
    }

    public function set(&$object)
    {
        // TODO: do the hard work of setting object values
    }

    public function is_valid()
    {
        return is_object($this->obj) && $this->obj->isValid();
    }

    public function fromkolab2($object)
    {
        $this->data = $object;
    }

    public function to_array()
    {
        // TODO: read object properties
        return $this->data;
    }
}
