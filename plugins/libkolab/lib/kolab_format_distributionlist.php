<?php

/**
 * Kolab Distribution List model class
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

class kolab_format_distributionlist extends kolab_format
{
    public $CTYPE = 'application/vcard+xml';

    function __construct()
    {
        $this->obj = new DistList;
    }

    /**
     * Load Kolab object data from the given XML block
     *
     * @param string XML data
     */
    public function load($xml)
    {
        $this->obj = kolabformat::readDistlist($xml, false);
    }

    /**
     * Write object data to XML format
     *
     * @return string XML data
     */
    public function write()
    {
        $xml = kolabformat::writeDistlist($this->obj);
        parent::update_uid();
        return $xml;
    }

    public function set(&$object)
    {
        // set some automatic values if missing
        if (!empty($object['uid']))
            $this->obj->setUid($object['uid']);

        $this->obj->setName($object['name']);

        $seen = array();
        $members = new vectorcontactref;
        foreach ($object['member'] as $member) {
            if ($member['uid'])
                $m = new ContactReference(ContactReference::UidReference, $member['uid']);
            else if ($member['email'])
                $m = new ContactReference(ContactReference::EmailReference, $member['email']);
            else
                continue;

            $m->setName($member['name']);
            $members->push($m);
            $seen[$member['email']]++;
        }

        $this->obj->setMembers($members);

        // cache this data
        unset($object['_formatobj']);
        $this->data = $object;
    }

    public function is_valid()
    {
        return $this->data || (is_object($this->obj) && $this->obj->isValid());
    }

    /**
     * Load data from old Kolab2 format
     */
    public function fromkolab2($record)
    {
        $object = array(
            'uid'     => $record['uid'],
            'changed' => $record['last-modification-date'],
            'name'    => $record['last-name'],
            'member'  => array(),
        );

        foreach ($record['member'] as $member) {
            $object['member'][] = array(
                'email' => $member['smtp-address'],
                'name' => $member['display-name'],
                'uid' => $member['uid'],
            );
        }

        $this->data = $object;
    }

    /**
     * Convert the Distlist object into a hash array data structure
     *
     * @return array  Distribution list data as hash array
     */
    public function to_array()
    {
        // return cached result
        if (!empty($this->data))
            return $this->data;

        // read object properties
        $object = array(
            'uid'       => $this->obj->uid(),
#           'changed'   => $this->obj->lastModified(),
            'name'      => $this->obj->name(),
            'member'    => array(),
        );

        $members = $this->obj->members();
        for ($i=0; $i < $members->size(); $i++) {
            $member = $members->get($i);
#            if ($member->type() == ContactReference::UidReference && ($uid = $member->uid()))
                $object['member'][] = array(
                    'uid' => $member->uid(),
                    'email' => $member->email(),
                    'name' => $member->name(),
                );
        }

        $this->data = $object;
        return $this->data;
    }

}
