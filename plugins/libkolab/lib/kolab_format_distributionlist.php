<?php


class kolab_format_distributionlist extends kolab_format
{
    public $CTYPE = 'application/vcard+xml';
    
    private $data;
    private $obj;

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
        return kolabformat::writeDistlist($this->obj);
    }

    public function set(&$object)
    {
        // set some automatic values if missing
        if (empty($object['uid']))
            $object['uid'] = self::generate_uid();

        // do the hard work of setting object values
        $this->obj->setUid($object['uid']);
        $this->obj->setName($object['name']);

        $members = new vectormember;
        foreach ($object['member'] as $member) {
            $m = new Member;
            $m->setName($member['name']);
            $m->setEmail($member['mailto']);
            $m->setUid($member['uid']);
            $members->push($m);
        }
        $this->obj->setMembers($members);
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
                'mailto' => $member['smtp-address'],
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
            if ($mailto = $member->email())
                $object['member'][] = array(
                    'mailto' => $mailto,
                    'name' => $member->name(),
                    'uid' => $member->uid(),
                );
        }

        $this->data = $object;
        return $this->data;
    }

}
