<?php

class Session extends Workerman\Protocols\Http\Session
{
    public static function updateTimestamp($sessionId)
    {
        if (!static::$_handler) {
            static::initHandler();
        }
        static::$_handler->updateTimestamp($sessionId);
    }
}
