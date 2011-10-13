<?php

/**
 * Kolab XML handler for configuration (KEP:9).
 *
 * Copyright (C) 2011 Kolab Systems AG
 *
 * @author  Aleksander Machniak <machniak@kolabsys.com>
 */
class Horde_Kolab_Format_XML_configuration extends Horde_Kolab_Format_XML {
    /**
     * Specific data fields for the configuration object
     *
     * @var Kolab
     */
    var $_fields_specific;

    var $_root_version = 2.1;

    /**
     * Constructor
     */
    function Horde_Kolab_Format_XML_configuration($params = array())
    {
        $this->_root_name = 'configuration';

        // Specific configuration fields, in kolab format specification order
        $this->_fields_specific = array(
            'application' => array (
                'type'    => HORDE_KOLAB_XML_TYPE_STRING,
                'value'   => HORDE_KOLAB_XML_VALUE_MAYBE_MISSING,
            ),
            'type' => array(
                'type'    => HORDE_KOLAB_XML_TYPE_STRING,
                'value'   => HORDE_KOLAB_XML_VALUE_NOT_EMPTY,
            ),
        );

        // Dictionary fields
        if (!empty($params['subtype']) && preg_match('/^dictionary.*/', $params['subtype'])) {
            $this->_fields_specific = array_merge($this->_fields_specific, array(
                'language' => array (
                    'type'    => HORDE_KOLAB_XML_TYPE_STRING,
                    'value'   => HORDE_KOLAB_XML_VALUE_NOT_EMPTY,
                ),
                'e' => array(
                    'type'    => HORDE_KOLAB_XML_TYPE_MULTIPLE,
                    'value'   => HORDE_KOLAB_XML_VALUE_NOT_EMPTY,
                    'array'   => array(
                        'type' => HORDE_KOLAB_XML_TYPE_STRING,
                        'value' => HORDE_KOLAB_XML_VALUE_NOT_EMPTY,
                    ),
                ),
            ));
        }

        parent::Horde_Kolab_Format_XML($params);

        unset($this->_fields_basic['body']);
        unset($this->_fields_basic['categories']);
        unset($this->_fields_basic['sensitivity']);
    }
}
