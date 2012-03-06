<?php


class kolab_format_event extends kolab_format
{
    public $CTYPE = 'application/calendar+xml';
    
    private $data;
    private $obj;

    function __construct()
    {
        $obj = new Event;
    }

    public function load($xml)
    {
        $this->obj = kolabformat::readEvent($xml, false);
    }

    public function write()
    {
        return kolabformat::writeEvent($this->obj);
    }

    public function set(&$object)
    {
        // TODO: do the hard work of setting object values
    }

    public function is_valid()
    {
        return is_object($this->obj) && $this->obj->isValid();
    }

    public function fromkolab2($object)
    {
        $this->data = $object;
    }

    public function to_array()
    {
        // TODO: read object properties
        return $this->data;
    }
}
