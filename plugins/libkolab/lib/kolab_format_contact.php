<?php

/**
 * Kolab Contact model class
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

class kolab_format_contact extends kolab_format
{
    public $CTYPE = 'application/vcard+xml';

    public $phonetypes = array(
        'home'    => Telephone::Home,
        'work'    => Telephone::Work,
        'text'    => Telephone::Text,
        'main'    => Telephone::Voice,
        'homefax' => Telephone::Fax,
        'workfax' => Telephone::Fax,
        'mobile'  => Telephone::Cell,
        'video'   => Telephone::Video,
        'pager'   => Telephone::Pager,
        'car'     => Telephone::Car,
        'other'   => Telephone::Textphone,
    );

    public $addresstypes = array(
        'home' => Address::Home,
        'work' => Address::Work,
        'office' => 0,
    );

    private $gendermap = array(
        'female' => Contact::Female,
        'male'   => Contact::Male,
    );

    private $relatedmap = array(
        'manager'   => Related::Manager,
        'assistant' => Related::Assistant,
        'spouse'    => Related::Spouse,
        'children'  => Related::Child,
    );

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
      'birthday'     => 'birthday',
      'anniversary'  => 'anniversary',
      'phone'        => 'phone',
      'im-address'   => 'im',
      'web-page'     => 'website',
      'profession'   => 'profession',
      'manager-name' => 'manager',
      'assistant'    => 'assistant',
      'spouse-name'  => 'spouse',
      'children'     => 'children',
      'body'         => 'notes',
      'pgp-publickey' => 'pgppublickey',
      'free-busy-url' => 'freebusyurl',
      'picture'       => 'photo',
    );
    private $kolab2_phonetypes = array(
        'home1' => 'home',
        'business1' => 'work',
        'business2' => 'work',
        'businessfax' => 'workfax',
    );
    private $kolab2_addresstypes = array(
        'business' => 'work'
    );
    private $kolab2_gender = array(0 => 'male', 1 => 'female');


    /**
     * Default constructor
     */
    function __construct()
    {
        $this->obj = new Contact;

        // complete phone types
        $this->phonetypes['homefax'] |= Telephone::Home;
        $this->phonetypes['workfax'] |= Telephone::Work;
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
        $xml = kolabformat::writeContact($this->obj);
        parent::update_uid();
        return $xml;
    }

    /**
     * Set contact properties to the kolabformat object
     *
     * @param array  Contact data as hash array
     */
    public function set(&$object)
    {
        // set some automatic values if missing
        if (false && !$this->obj->created()) {
            if (!empty($object['created']))
                $object['created'] = new DateTime('now', self::$timezone);
            $this->obj->setCreated(self::get_datetime($object['created']));
        }

        if (!empty($object['uid']))
            $this->obj->setUid($object['uid']);

        // do the hard work of setting object values
        $nc = new NameComponents;
        $nc->setSurnames(self::array2vector($object['surname']));
        $nc->setGiven(self::array2vector($object['firstname']));
        $nc->setAdditional(self::array2vector($object['middlename']));
        $nc->setPrefixes(self::array2vector($object['prefix']));
        $nc->setSuffixes(self::array2vector($object['suffix']));
        $this->obj->setNameComponents($nc);
        $this->obj->setName($object['name']);

        if (isset($object['nickname']))
            $this->obj->setNickNames(self::array2vector($object['nickname']));
        if (isset($object['profession']))
            $this->obj->setTitles(self::array2vector($object['profession']));

        // organisation related properties (affiliation)
        $org = new Affiliation;
        $offices = new vectoraddress;
        if ($object['organization'])
            $org->setOrganisation($object['organization']);
        if ($object['department'])
            $org->setOrganisationalUnits(self::array2vector($object['department']));
        if ($object['jobtitle'])
            $org->setRoles(self::array2vector($object['jobtitle']));

        $rels = new vectorrelated;
        if ($object['manager']) {
            foreach ((array)$object['manager'] as $manager)
                $rels->push(new Related(Related::Text, $manager, Related::Manager));
        }
        if ($object['assistant']) {
            foreach ((array)$object['assistant'] as $assistant)
                $rels->push(new Related(Related::Text, $assistant, Related::Assistant));
        }
        $org->setRelateds($rels);

        // email, im, url
        $this->obj->setEmailAddresses(self::array2vector($object['email']));
        $this->obj->setIMaddresses(self::array2vector($object['im']));
        $this->obj->setUrls(self::array2vector($object['website']));

        // addresses
        $adrs = new vectoraddress;
        foreach ($object['address'] as $address) {
            $adr = new Address;
            $type = $this->addresstypes[$address['type']];
            if (isset($type))
                $adr->setTypes($type);
            else if ($address['type'])
                $adr->setLabel($address['type']);
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

            if ($address['type'] == 'office')
                $offices->push($adr);
            else
                $adrs->push($adr);
        }
        $this->obj->setAddresses($adrs);
        $org->setAddresses($offices);

        // add org affiliation after addresses are set
        $orgs = new vectoraffiliation;
        $orgs->push($org);
        $this->obj->setAffiliations($orgs);

        // telephones
        $tels = new vectortelephone;
        foreach ((array)$object['phone'] as $phone) {
            $tel = new Telephone;
            if (isset($this->phonetypes[$phone['type']]))
                $tel->setTypes($this->phonetypes[$phone['type']]);
            $tel->setNumber($phone['number']);
            $tels->push($tel);
        }
        $this->obj->setTelephones($tels);

        if (isset($object['gender']))
            $this->obj->setGender($this->gendermap[$object['gender']] ? $this->gendermap[$object['gender']] : Contact::NotSet);
        if (isset($object['notes']))
            $this->obj->setNote($object['notes']);
        if (isset($object['freebusyurl']))
            $this->obj->setFreeBusyUrl($object['freebusyurl']);
        if (isset($object['birthday']))
            $this->obj->setBDay(self::get_datetime($object['birthday'], null, true));
        if (isset($object['anniversary']))
            $this->obj->setAnniversary(self::get_datetime($object['anniversary'], null, true));

        if (!empty($object['photo'])) {
            if ($type = rc_image_content_type($object['photo']))
                $this->obj->setPhoto($object['photo'], $type);
        }
        else if (isset($object['photo'])) {
            $this->obj->setPhoto('','');
        }

        // spouse and children are relateds
        $rels = new vectorrelated;
        if ($object['spouse']) {
            $rels->push(new Related(Related::Text, $object['spouse'], Related::Spouse));
        }
        if ($object['children']) {
            foreach ((array)$object['children'] as $child)
                $rels->push(new Related(Related::Text, $child, Related::Child));
        }
        $this->obj->setRelateds($rels);

        if (isset($object['pgppublickey'])) {
            $crypto = new Crypto;
            $crypto->setPGPKey($object['pgppublickey']);
            $this->obj->setCrypto($crypto);
        }

        // TODO: handle language, gpslocation, etc.


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

        // read object properties into local data object
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
        $object['nickname']   = join(' ', self::vector2array($this->obj->nickNames()));
        $object['profession'] = join(' ', self::vector2array($this->obj->titles()));

        // organisation related properties (affiliation)
        $orgs = $this->obj->affiliations();
        if ($orgs->size()) {
            $org = $orgs->get(0);
            $object['organization']   = $org->organisation();
            $object['jobtitle']       = join(' ', self::vector2array($org->roles()));
            $object['department']     = join(' ', self::vector2array($org->organisationalUnits()));
            $this->read_relateds($org->relateds(), $object);
        }

        $object['email']   = self::vector2array($this->obj->emailAddresses());
        $object['im']      = self::vector2array($this->obj->imAddresses());
        $object['website'] = self::vector2array($this->obj->urls());

        // addresses
        $this->read_addresses($this->obj->addresses(), $object);
        if ($org && ($offices = $org->addresses()))
            $this->read_addresses($offices, $object, 'office');

        // telehones
        $tels = $this->obj->telephones();
        $teltypes = array_flip($this->phonetypes);
        for ($i=0; $i < $tels->size(); $i++) {
            $tel = $tels->get($i);
            $object['phone'][] = array('number' => $tel->number(), 'type' => $teltypes[$tel->types()]);
        }

        $object['notes'] = $this->obj->note();
        $object['freebusyurl'] = $this->obj->freeBusyUrl();

        if ($bday = self::php_datetime($this->obj->bDay()))
            $object['birthday'] = $bday->format('c');

        if ($anniversary = self::php_datetime($this->obj->anniversary()))
            $object['anniversary'] = $anniversary->format('c');

        $gendermap = array_flip($this->gendermap);
        if (($g = $this->obj->gender()) && $gendermap[$g])
            $object['gender'] = $gendermap[$g];

        if ($this->obj->photoMimetype())
            $object['photo'] = $this->obj->photo();

        // relateds -> spouse, children
        $this->read_relateds($this->obj->relateds(), $object);

        // crypto settings: currently only pgpkey is supported
        $crypto = $this->obj->crypto();
        if ($pgpkey = $crypto->pgpKey())
            $object['pgppublickey'] = $pgpkey;

        $this->data = $object;
        return $this->data;
    }

    /**
     * Load data from old Kolab2 format
     *
     * @param array Hash array with object properties
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
            $object['gender'] = $this->kolab2_gender[$record['gender']];

        foreach ((array)$record['email'] as $i => $email)
            $object['email'][] = $email['smtp-address'];

        if (!$record['email'] && $record['emails'])
            $object['email'] = preg_split('/,\s*/', $record['emails']);

        if (is_array($record['address'])) {
            foreach ($record['address'] as $i => $adr) {
                $object['address'][] = array(
                    'type' => $this->kolab2_addresstypes[$adr['type']] ? $this->kolab2_addresstypes[$adr['type']] : $adr['type'],
                    'street' => $adr['street'],
                    'locality' => $adr['locality'],
                    'code' => $adr['postal-code'],
                    'region' => $adr['region'],
                    'country' => $adr['country'],
                );
            }
        }

        // office location goes into an address block
        if ($record['office-location'])
            $object['address'][] = array('type' => 'office', 'locality' => $record['office-location']);

        // merge initials into nickname
        if ($record['initials'])
            $object['nickname'] = trim($object['nickname'] . ', ' . $record['initials'], ', ');

        // remove empty fields
        $this->data = array_filter($object);
    }

    /**
     * Helper method to copy contents of an Address vector to the contact data object
     */
    private function read_addresses($addresses, &$object, $type = null)
    {
        $adrtypes = array_flip($this->addresstypes);

        for ($i=0; $i < $addresses->size(); $i++) {
            $adr = $addresses->get($i);
            $object['address'][] = array(
                'type'     => $type ? $type : ($adrtypes[$adr->types()] ? $adrtypes[$adr->types()] : ''), /*$adr->label()),*/
                'street'   => $adr->street(),
                'code'     => $adr->code(),
                'locality' => $adr->locality(),
                'region'   => $adr->region(),
                'country'  => $adr->country()
            );
        }
    }

    /**
     * Helper method to map contents of a Related vector to the contact data object
     */
    private function read_relateds($rels, &$object)
    {
        $typemap = array_flip($this->relatedmap);

        for ($i=0; $i < $rels->size(); $i++) {
            $rel = $rels->get($i);
            if ($rel->type() != Related::Text)  // we can't handle UID relations yet
                continue;

            $types = $rel->relationTypes();
            foreach ($typemap as $t => $field) {
                if ($types & $t) {
                    $object[$field][] = $rel->text();
                    break;
                }
            }
        }
    }
}
