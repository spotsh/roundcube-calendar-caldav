<?php


class kolab_format_contact extends kolab_format
{
    public $CTYPE = 'application/vcard+xml';
    
    private $data;
    private $obj;

    // old Kolab 2 format field map
    private $kolab2_fieldmap = array(
      // kolab       => roundcube
      'full-name'    => 'name',
      'given-name'   => 'firstname',
      'middle-names' => 'middlename',
      'last-name'    => 'surname',
      'prefix'       => 'prefix',
      'suffix'       => 'suffix',
      'nick-name'    => 'nickname',
      'organization' => 'organization',
      'department'   => 'department',
      'job-title'    => 'jobtitle',
      'initials'     => 'initials',
      'birthday'     => 'birthday',
      'anniversary'  => 'anniversary',
      'phone'        => 'phone',
      'im-address'   => 'im',
      'web-page'     => 'website',
      'office-location' => 'officelocation',
      'profession'   => 'profession',
      'manager-name' => 'manager',
      'assistant'    => 'assistant',
      'spouse-name'  => 'spouse',
      'children'     => 'children',
      'body'         => 'notes',
      'pgp-publickey' => 'pgppublickey',
      'free-busy-url' => 'freebusyurl',
      'gender'       => 'gender',
    );

    function __construct()
    {
        $this->obj = new Contact;
    }

    /**
     * Load Contact object data from the given XML block
     *
     * @param string XML data
     */
    public function load($xml)
    {
        $this->obj = kolabformat::readContact($xml, false);
    }

    /**
     * Write Contact object data to XML format
     *
     * @return string XML data
     */
    public function write()
    {
        return kolabformat::writeContact($this->obj);
    }

    /**
     * Set contact properties to the kolabformat object
     *
     * @param array  Contact data as hash array
     */
    public function set(&$object)
    {
        // set some automatic values if missing
        if (empty($object['uid']))
            $object['uid'] = self::generate_uid();

        if (false && !$this->obj->created()) {
            if (!empty($object['created']))
                $object['created'] = new DateTime('now', self::$timezone);
            $this->obj->setCreated(self::getDateTime($object['created']));
        }

        // do the hard work of setting object values
        $this->obj->setUid($object['uid']);

        $nc = new NameComponents;
        // surname
        $sn = new vectors();
        $sn->push($object['surname']);
        $nc->setSurnames($sn);
        // firstname
        $gn = new vectors();
        $gn->push($object['firstname']);
        $nc->setGiven($gn);
        // middle name
        $mn = new vectors();
        if ($object['middlename'])
            $mn->push($object['middlename']);
        $nc->setAdditional($mn);
        // prefix
        $px = new vectors();
        if ($object['prefix'])
            $px->push($object['prefix']);
        $nc->setPrefixes($px);
        // suffix
        $sx = new vectors();
        if ($object['suffix'])
            $sx->push($object['suffix']);
        $nc->setSuffixes($sx);

        $this->obj->setNameComponents($nc);
        $this->obj->setName($object['name']);

        // email addresses
        $emails = new vectors;
        foreach ($object['email'] as $em)
            $emails->push($em);
        $this->obj->setEmailAddresses($emails);

        // addresses
        $adrs = new vectoraddress;
        foreach ($object['address'] as $address) {
            $adr = new Address;
            $adr->setTypes($address['type'] == 'work' ? Address::Work : Address::Home);
            if ($address['street'])
                $adr->setStreet($address['street']);
            if ($address['locality'])
                $adr->setLocality($address['locality']);
            if ($address['code'])
                $adr->setCode($address['code']);
            if ($address['region'])
                $adr->setRegion($address['region']);
            if ($address['country'])
                $adr->setCountry($address['country']);

            $adrs->push($adr);
        }
        $this->obj->setAddresses($adrs);

        // cache this data
        $this->data = $object;
    }

    /**
     *
     */
    public function is_valid()
    {
        return $this->data || (is_object($this->obj) && true /*$this->obj->isValid()*/);
    }


    /**
     * Convert the Contact object into a hash array data structure
     *
     * @return array  Contact data as hash array
     */
    public function to_array()
    {
        // return cached result
        if (!empty($this->data))
            return $this->data;

        // TODO: read object properties into local data object
        $object = array(
            'uid'       => $this->obj->uid(),
            # 'changed'   => $this->obj->lastModified(),
            'name'      => $this->obj->name(),
        );

        $nc = $this->obj->nameComponents();
        $object['surname']    = join(' ', self::vector2array($nc->surnames()));
        $object['firstname']  = join(' ', self::vector2array($nc->given()));
        $object['middlename'] = join(' ', self::vector2array($nc->additional()));
        $object['prefix']     = join(' ', self::vector2array($nc->prefixes()));
        $object['suffix']     = join(' ', self::vector2array($nc->suffixes()));

        $object['email'] = self::vector2array($this->obj->emailAddresses());

        $addresses = $this->obj->addresses();
        for ($i=0; $i < $addresses->size(); $i++) {
            $adr = $addresses->get($i);
            $object['address'][] = array(
                'type'     => $adr->types() == Address::Work ? 'work' : 'home',
                'street'   => $adr->street(),
                'code'     => $adr->code(),
                'locality' => $adr->locality(),
                'region'   => $adr->region(),
                'country'  => $adr->country()
            );
        }

        $this->data = $object;
        return $this->data;
    }

    /**
     * Load data from old Kolab2 format
     */
    public function fromkolab2($record)
    {
        $object = array(
          'uid' => $record['uid'],
          'email' => array(),
          'phone' => array(),
        );

        foreach ($this->kolab2_fieldmap as $kolab => $rcube) {
          if (is_array($record[$kolab]) || strlen($record[$kolab]))
            $object[$rcube] = $record[$kolab];
        }

        if (isset($record['gender']))
            $object['gender'] = $this->gender_map[$record['gender']];

        foreach ((array)$record['email'] as $i => $email)
            $object['email'][] = $email['smtp-address'];

        if (!$record['email'] && $record['emails'])
            $object['email'] = preg_split('/,\s*/', $record['emails']);

        if (is_array($record['address'])) {
            foreach ($record['address'] as $i => $adr) {
                $object['address'][] = array(
                    'type' => $adr['type'],
                    'street' => $adr['street'],
                    'locality' => $adr['locality'],
                    'code' => $adr['postal-code'],
                    'region' => $adr['region'],
                    'country' => $adr['country'],
                );
            }
        }

        // photo is stored as separate attachment
        if ($record['picture'] && ($att = $record['_attachments'][$record['picture']])) {
            $object['photo'] = $att['content'] ? $att['content'] : $this->contactstorage->getAttachment($att['key']);
        }

        // remove empty fields
        $this->data = array_filter($object);
    }
}
