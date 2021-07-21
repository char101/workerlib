<?php

class SessionProxy implements ArrayAccess
{
    public function __construct(private $session)
    {
    }

    public function offsetGet($key)
    {
        return $this->session->get($key);
    }

    public function offsetExists($key)
    {
        return $this->session->exists($key);
    }

    public function offsetUnset($key)
    {
    }

    public function offsetSet($key, $value)
    {
    }
}
