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

    protected /*abstract*/ $read_func;
    protected /*abstract*/ $write_func;

    protected $obj;
    protected $data;
    protected $xmldata;
    protected $loaded = false;

    /**
     * Factory method to instantiate a kolab_format object of the given type
     *
     * @param string Object type to instantiate
     * @param string Cached xml data to initialize with
     * @return object kolab_format
     */
    public static function factory($type, $xmldata = null)
    {
        if (!isset(self::$timezone))
            self::$timezone = new DateTimeZone('UTC');

        $type = preg_replace('/configuration\.[a-z.]+$/', 'configuration', $type);
        $suffix = preg_replace('/[^a-z]+/', '', $type);
        $classname = 'kolab_format_' . $suffix;
        if (class_exists($classname))
            return new $classname($xmldata);

        return PEAR::raiseError("Failed to load Kolab Format wrapper for type " . $type);
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
        if (!$tz && $tz !== false) $tz = self::$timezone;
        $result = new cDateTime();

        // got a unix timestamp (in UTC)
        if (is_numeric($datetime)) {
            $datetime = new DateTime('@'.$datetime, new DateTimeZone('UTC'));
            if ($tz) $datetime->setTimezone($tz);
        }
        else if (is_string($datetime) && strlen($datetime))
            $datetime = new DateTime($datetime, $tz ?: null);

        if (is_a($datetime, 'DateTime')) {
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
            raise_error(array(
                'code' => 660,
                'type' => 'php',
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => "kolabformat write $log: " . kolabformat::errorMessage(),
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
            $this->data['uid'] = kolabformat::getSerializedUID();
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
     */
    public function load($xml)
    {
        $this->obj = call_user_func($this->read_func, $xml, false);
        $this->loaded = !$this->format_errors();
    }

    /**
     * Write object data to XML format
     *
     * @return string XML data
     */
    public function write()
    {
        $this->init();
        $this->xmldata = call_user_func($this->write_func, $this->obj);

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
     * @return array  Kolab object data as hash array
     */
    abstract public function to_array();

    /**
     * Load object data from Kolab2 format
     *
     * @param array Hash array with object properties (produced by Horde Kolab_Format classes)
     */
    abstract public function fromkolab2($object);

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
