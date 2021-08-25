<?php

class RedisDB
{
    private static $instances = [];

    public static function instance($name)
    {
        $db = Config::get('redis.db.'.$name);

        if ($db === 0) {
            throw new Exception('Redis DB 0 is reserved for session');
        }

        if (!isset(self::instances[$db])) {
            $config = Config::get('redis');

            $redis = new Redis();
            $redis->connect($config['host'], $config['port']);

            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
            if (!empty($config['prefix'])) {
                $redis->setOption(Redis::OPT_PREFIX, $config['prefix']);
            }

            $redis->select($db);

            self::$instances[$db] = $redis;
        }

        return self::$instances[$db];
    }
}
