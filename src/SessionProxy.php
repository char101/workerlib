<?php

class SessionProxy implements ArrayAccess
{
    public function __construct(private $session)
    {
    }

    public function __call($name, $args)
    {
        return call_user_func_array([$this->session, $name], $args);
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
        if ($this->session->exists($key)) {
            $this->session->delete($key);
        }
    }

    public function offsetSet($key, $value)
    {
        $this->session->set($key, $value);
    }
}
