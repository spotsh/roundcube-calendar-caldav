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

class kolab_format_event extends kolab_format_xcal
{
    public $CTYPEv2 = 'application/x-vnd.kolab.event';

    protected $objclass = 'Event';
    protected $read_func = 'readEvent';
    protected $write_func = 'writeEvent';


    /**
     * Clones into an instance of libcalendaring's extended EventCal class
     *
     * @return mixed EventCal object or false on failure
     */
    public function to_libcal()
    {
        static $error_logged = false;

        if (class_exists('kolabcalendaring')) {
            return new EventCal($this->obj);
        }
        else if (!$error_logged) {
            $error_logged = true;
            rcube::raise_error(array(
                'code' => 900, 'type' => 'php',
                'message' => "required kolabcalendaring module not found"
            ), true);
        }

        return false;
    }

    /**
     * Set event properties to the kolabformat object
     *
     * @param array  Event data as hash array
     */
    public function set(&$object)
    {
        $this->init();

        // set common xcal properties
        parent::set($object);

        // do the hard work of setting object values
        $this->obj->setStart(self::get_datetime($object['start'], null, $object['allday']));
        $this->obj->setEnd(self::get_datetime($object['end'], null, $object['allday']));
        $this->obj->setTransparency($object['free_busy'] == 'free');

        $status = kolabformat::StatusUndefined;
        if ($object['free_busy'] == 'tentative')
            $status = kolabformat::StatusTentative;
        if ($object['cancelled'])
            $status = kolabformat::StatusCancelled;
        $this->obj->setStatus($status);

        // save attachments
        $vattach = new vectorattachment;
        foreach ((array)$object['_attachments'] as $cid => $attr) {
            if (empty($attr))
                continue;
            $attach = new Attachment;
            $attach->setLabel((string)$attr['name']);
            $attach->setUri('cid:' . $cid, $attr['mimetype']);
            $vattach->push($attach);
        }
        $this->obj->setAttachments($vattach);

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
    }

    /**
     *
     */
    public function is_valid()
    {
        return $this->data || (is_object($this->obj) && $this->obj->isValid() && $this->obj->uid());
    }

    /**
     * Convert the Event object into a hash array data structure
     *
     * @return array  Event data as hash array
     */
    public function to_array()
    {
        // return cached result
        if (!empty($this->data))
            return $this->data;

        $this->init();

        // read common xcal props
        $object = parent::to_array();

        // read object properties
        $object += array(
            'end'         => self::php_datetime($this->obj->end()),
            'allday'      => $this->obj->start()->isDateOnly(),
            'free_busy'   => $this->obj->transparency() ? 'free' : 'busy',  // TODO: transparency is only boolean
            'attendees'   => array(),
        );

        // organizer is part of the attendees list in Roundcube
        if ($object['organizer']) {
            $object['organizer']['role'] = 'ORGANIZER';
            array_unshift($object['attendees'], $object['organizer']);
        }

        // status defines different event properties...
        $status = $this->obj->status();
        if ($status == kolabformat::StatusTentative)
          $object['free_busy'] = 'tentative';
        else if ($status == kolabformat::StatusCancelled)
          $objec['cancelled'] = true;

        // handle attachments
        $vattach = $this->obj->attachments();
        for ($i=0; $i < $vattach->size(); $i++) {
            $attach = $vattach->get($i);

            // skip cid: attachments which are mime message parts handled by kolab_storage_folder
            if (substr($attach->uri(), 0, 4) != 'cid:' && $attach->label()) {
                $name = $attach->label();
                $data = $attach->data();
                $object['_attachments'][$name] = array(
                    'name'     => $name,
                    'mimetype' => $attach->mimetype(),
                    'size'     => strlen($data),
                    'content'  => $data,
                );
            }
        }

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

        foreach ((array)$this->data['categories'] as $cat) {
            $tags[] = rcube_utils::normalize_string($cat);
        }

        if (!empty($this->data['alarms'])) {
            $tags[] = 'x-has-alarms';
        }

        return $tags;
    }

}
