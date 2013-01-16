<?php

/**
 * Kolab format model class wrapping libkolabxml bindings
 *
 * Abstract base class for different Kolab groupware objects read from/written
 * to the new Kolab 3 format using the PHP bindings of libkolabxml.
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

abstract class kolab_format
{
    public static $timezone;

    public /*abstract*/ $CTYPE;
    public /*abstract*/ $CTYPEv2;

    protected /*abstract*/ $objclass;
    protected /*abstract*/ $read_func;
    protected /*abstract*/ $write_func;

    protected $obj;
    protected $data;
    protected $xmldata;
    protected $xmlobject;
    protected $loaded = false;
    protected $version = 3.0;

    const KTYPE_PREFIX = 'application/x-vnd.kolab.';
    const PRODUCT_ID = 'Roundcube-libkolab-0.9';

    /**
     * Factory method to instantiate a kolab_format object of the given type and version
     *
     * @param string Object type to instantiate
     * @param float  Format version
     * @param string Cached xml data to initialize with
     * @return object kolab_format
     */
    public static function factory($type, $version = 3.0, $xmldata = null)
    {
        if (!isset(self::$timezone))
            self::$timezone = new DateTimeZone('UTC');

        if (!self::supports($version))
            return PEAR::raiseError("No support for Kolab format version " . $version);

        $type = preg_replace('/configuration\.[a-z.]+$/', 'configuration', $type);
        $suffix = preg_replace('/[^a-z]+/', '', $type);
        $classname = 'kolab_format_' . $suffix;
        if (class_exists($classname))
            return new $classname($xmldata, $version);

        return PEAR::raiseError("Failed to load Kolab Format wrapper for type " . $type);
    }

    /**
     * Determine support for the given format version
     *
     * @param float Format version to check
     * @return boolean True if supported, False otherwise
     */
    public static function supports($version)
    {
        if ($version == 2.0)
            return class_exists('kolabobject');
        // default is version 3
        return class_exists('kolabformat');
    }

    /**
     * Convert the given date/time value into a cDateTime object
     *
     * @param mixed         Date/Time value either as unix timestamp, date string or PHP DateTime object
     * @param DateTimeZone  The timezone the date/time is in. Use global default if Null, local time if False
     * @param boolean       True of the given date has no time component
     * @return object       The libkolabxml date/time object
     */
    public static function get_datetime($datetime, $tz = null, $dateonly = false)
    {
        // use timezone information from datetime of global setting
        if (!$tz && $tz !== false) {
            if ($datetime instanceof DateTime)
                $tz = $datetime->getTimezone();
            if (!$tz)
                $tz = self::$timezone;
        }
        $result = new cDateTime();

        // got a unix timestamp (in UTC)
        if (is_numeric($datetime)) {
            $datetime = new DateTime('@'.$datetime, new DateTimeZone('UTC'));
            if ($tz) $datetime->setTimezone($tz);
        }
        else if (is_string($datetime) && strlen($datetime))
            $datetime = new DateTime($datetime, $tz ?: null);

        if ($datetime instanceof DateTime) {
            $result->setDate($datetime->format('Y'), $datetime->format('n'), $datetime->format('j'));

            if (!$dateonly)
                $result->setTime($datetime->format('G'), $datetime->format('i'), $datetime->format('s'));

            if ($tz && $tz->getName() == 'UTC')
                $result->setUTC(true);
            else if ($tz !== false)
                $result->setTimezone($tz->getName());
        }

        return $result;
    }

    /**
     * Convert the given cDateTime into a PHP DateTime object
     *
     * @param object cDateTime  The libkolabxml datetime object
     * @return object DateTime  PHP datetime instance
     */
    public static function php_datetime($cdt)
    {
        if (!is_object($cdt) || !$cdt->isValid())
            return null;

        $d = new DateTime;
        $d->setTimezone(self::$timezone);

        try {
            if ($tzs = $cdt->timezone()) {
                $tz = new DateTimeZone($tzs);
                $d->setTimezone($tz);
            }
            else if ($cdt->isUTC()) {
                $d->setTimezone(new DateTimeZone('UTC'));
            }
        }
        catch (Exception $e) { }

        $d->setDate($cdt->year(), $cdt->month(), $cdt->day());

        if ($cdt->isDateOnly()) {
            $d->_dateonly = true;
            $d->setTime(12, 0, 0);  // set time to noon to avoid timezone troubles
        }
        else {
            $d->setTime($cdt->hour(), $cdt->minute(), $cdt->second());
        }

        return $d;
    }

    /**
     * Convert a libkolabxml vector to a PHP array
     *
     * @param object vector Object
     * @return array Indexed array contaning vector elements
     */
    public static function vector2array($vec, $max = PHP_INT_MAX)
    {
        $arr = array();
        for ($i=0; $i < $vec->size() && $i < $max; $i++)
            $arr[] = $vec->get($i);
        return $arr;
    }

    /**
     * Build a libkolabxml vector (string) from a PHP array
     *
     * @param array Array with vector elements
     * @return object vectors
     */
    public static function array2vector($arr)
    {
        $vec = new vectors;
        foreach ((array)$arr as $val) {
            if (strlen($val))
                $vec->push($val);
        }
        return $vec;
    }

    /**
     * Parse the X-Kolab-Type header from MIME messages and return the object type in short form
     *
     * @param string X-Kolab-Type header value
     * @return string Kolab object type (contact,event,task,note,etc.)
     */
    public static function mime2object_type($x_kolab_type)
    {
        return preg_replace('/dictionary.[a-z.]+$/', 'dictionary', substr($x_kolab_type, strlen(self::KTYPE_PREFIX)));
    }


    /**
     * Default constructor of all kolab_format_* objects
     */
    public function __construct($xmldata = null, $version = null)
    {
        $this->obj = new $this->objclass;
        $this->xmldata = $xmldata;

        if ($version)
            $this->version = $version;

        // use libkolab module if available
        if (class_exists('kolabobject'))
            $this->xmlobject = new XMLObject();
    }

    /**
     * Check for format errors after calling kolabformat::write*()
     *
     * @return boolean True if there were errors, False if OK
     */
    protected function format_errors()
    {
        $ret = $log = false;
        switch (kolabformat::error()) {
            case kolabformat::NoError:
                $ret = false;
                break;
            case kolabformat::Warning:
                $ret = false;
                $log = "Warning";
                break;
            default:
                $ret = true;
                $log = "Error";
        }

        if ($log) {
            rcube::raise_error(array(
                'code' => 660,
                'type' => 'php',
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => "kolabformat $log: " . kolabformat::errorMessage(),
            ), true);
        }

        return $ret;
    }

    /**
     * Save the last generated UID to the object properties.
     * Should be called after kolabformat::writeXXXX();
     */
    protected function update_uid()
    {
        // get generated UID
        if (!$this->data['uid']) {
            $this->data['uid'] = $this->xmlobject ? $this->xmlobject->getSerializedUID() : kolabformat::getSerializedUID();
            $this->obj->setUid($this->data['uid']);
        }
    }

    /**
     * Initialize libkolabxml object with cached xml data
     */
    protected function init()
    {
        if (!$this->loaded) {
            if ($this->xmldata) {
                $this->load($this->xmldata);
                $this->xmldata = null;
            }
            $this->loaded = true;
        }
    }

    /**
     * Get constant value for libkolab's version parameter
     *
     * @param float Version value to convert
     * @return int Constant value of either kolabobject::KolabV2 or kolabobject::KolabV3 or false if kolabobject module isn't available
     */
    protected function libversion($v = null)
    {
        if (class_exists('kolabobject')) {
            $version = $v ?: $this->version;
            if ($version <= 2.0)
                return kolabobject::KolabV2;
            else
                return kolabobject::KolabV3;
        }

        return false;
    }

    /**
     * Determine the correct libkolab(xml) wrapper function for the given call
     * depending on the available PHP modules
     */
    protected function libfunc($func)
    {
        if (is_array($func) || strpos($func, '::'))
            return $func;
        else if (class_exists('kolabobject'))
            return array($this->xmlobject, $func);
        else
            return 'kolabformat::' . $func;
    }

    /**
     * Direct getter for object properties
     */
    public function __get($var)
    {
        return $this->data[$var];
    }

    /**
     * Load Kolab object data from the given XML block
     *
     * @param string XML data
     * @return boolean True on success, False on failure
     */
    public function load($xml)
    {
        $read_func = $this->libfunc($this->read_func);

        if (is_array($read_func))
            $r = call_user_func($read_func, $xml, $this->libversion());
        else
            $r = call_user_func($read_func, $xml, false);

        if (is_resource($r))
            $this->obj = new $this->objclass($r);
        else if (is_a($r, $this->objclass))
            $this->obj = $r;

        $this->loaded = !$this->format_errors();
    }

    /**
     * Write object data to XML format
     *
     * @param float Format version to write
     * @return string XML data
     */
    public function write($version = null)
    {
        $this->init();
        $write_func = $this->libfunc($this->write_func);
        if (is_array($write_func))
            $this->xmldata = call_user_func($write_func, $this->obj, $this->libversion($version), self::PRODUCT_ID);
        else
            $this->xmldata = call_user_func($write_func, $this->obj, self::PRODUCT_ID);

        if (!$this->format_errors())
            $this->update_uid();
        else
            $this->xmldata = null;

        return $this->xmldata;
    }

    /**
     * Set properties to the kolabformat object
     *
     * @param array  Object data as hash array
     */
    abstract public function set(&$object);

    /**
     *
     */
    abstract public function is_valid();

    /**
     * Convert the Kolab object into a hash array data structure
     *
     * @param array Additional data for merge
     *
     * @return array  Kolab object data as hash array
     */
    abstract public function to_array($data = array());

    /**
     * Callback for kolab_storage_cache to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags()
    {
        return array();
    }

    /**
     * Callback for kolab_storage_cache to get words to index for fulltext search
     *
     * @return array List of words to save in cache
     */
    public function get_words()
    {
        return array();
    }
}
