<?php

class RedisSessionHandler extends Workerman\Protocols\Http\Session\RedisSessionHandler
{
    public function updateTimestamp($sessionId)
    {
        $this->_redis->expire($sessionId, $this->_maxLifeTime);
    }
}
