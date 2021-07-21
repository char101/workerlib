<?php

#[Attribute]
class Route
{
    public $paths;

    public function __construct()
    {
        $this->paths = func_get_args();
    }
}
