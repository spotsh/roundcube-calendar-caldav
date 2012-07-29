<?php

/**
 * Kolab Task (ToDo) model class
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

class kolab_format_task extends kolab_format_xcal
{
    protected $read_func = 'kolabformat::readTodo';
    protected $write_func = 'kolabformat::writeTodo';


    function __construct($xmldata = null)
    {
        $this->obj = new Todo;
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

        // set common xcal properties
        parent::set($object);

        $this->obj->setPercentComplete(intval($object['complete']));

        if (isset($object['start']))
            $this->obj->setStart(self::get_datetime($object['start'], null, $object['start']->_dateonly));

        $this->obj->setDue(self::get_datetime($object['due'], null, $object['due']->_dateonly));

        $related = new vectors;
        if (!empty($object['parent_id']))
            $related->push($object['parent_id']);
        $this->obj->setRelatedTo($related);

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
     * @return array  Config object data as hash array
     */
    public function to_array()
    {
        // return cached result
        if (!empty($this->data))
            return $this->data;

        $this->init();

        // read common xcal props
        $object = parent::to_array();

        $object['complete'] = intval($this->obj->percentComplete());

        // if due date is set
        if ($due = $this->obj->due())
            $object['due'] = self::php_datetime($due);

        // related-to points to parent taks; we only support one relation
        $related = self::vector2array($this->obj->relatedTo());
        if (count($related))
            $object['parent_id'] = $related[0];

        // TODO: map more properties

        $this->data = $object;
        return $this->data;
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

        // TODO: implement this

        $this->data = $object;
    }

    /**
     * Callback for kolab_storage_cache to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags()
    {
        $tags = array();

        if ($this->data['status'] == 'COMPLETED' || $this->data['complete'] == 100)
            $tags[] = 'x-complete';

        if ($this->data['priority'] == 1)
            $tags[] = 'x-flagged';

        if (!empty($this->data['alarms']))
            $tags[] = 'x-has-alarms';

        return $tags;
    }
}
