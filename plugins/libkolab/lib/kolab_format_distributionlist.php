<?php


class kolab_format_distributionlist extends kolab_format
{
    public $CTYPE = 'application/vcard+xml';
    
    private $data;
    private $obj;

    function __construct()
    {
        $obj = new DistList;
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
        // TODO: do the hard work of setting object values
    }

    public function is_valid()
    {
        return $this->data || (is_object($this->obj) && true /*$this->obj->isValid()*/);
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
            $adr = self::decode_member($members->get($i));
            if ($adr[0]['mailto'])
                $object['member'][] = array(
                    'mailto' => $adr[0]['mailto'],
                    'name' => $adr[0]['name'],
                    'uid' => '????',
                );
        }

        return $this->data;
    }

    /**
     * Compose a valid Mailto URL according to RFC 822
     *
     * @param string E-mail address
     * @param string Person name
     * @return string Formatted string
     */
    public static function format_member($email, $name = '')
    {
        // let Roundcube internals do the job
        return 'mailto:' . format_email_recipient($email, $name);
    }

    /**
     * Split a mailto: url into a structured member component
     *
     * @param string RFC 822 mailto: string
     * @return array Hash array with member properties
     */
    public static function decode_member($str)
    {
        $adr = rcube_mime::decode_address_list(preg_replace('/^mailto:/', '', $str));
        return $adr[0];
    }
}
