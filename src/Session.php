<?php

class Session extends Workerman\Protocols\Http\Session
{
    public function __construct($sessionId)
    {
        if ($sessionId[-1] === ';') {
            $sessionId = substr($sessionId, 0, -1);
        }
        parent::__construct($sessionId);
    }

    // Called by App after every request to renew session
    public static function updateTimestamp($sessionId)
    {
        if (!static::$_handler) {
            static::initHandler();
        }
        if ($sessionId[-1] === ';') {
            $sessionId = substr($sessionId, 0, -1);
        }
        static::$_handler->updateTimestamp($sessionId);
    }
}
