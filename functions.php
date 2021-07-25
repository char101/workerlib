<?php

function splitCamelCase($name)
{
    return preg_split('/(?<=[A-Z])(?=[A-Z][a-z])|(?<=[^A-Z])(?=[A-Z])|(?<=[A-Za-z])(?=[^A-Za-z])/', $name);
}

function connectRedis($name, $db = null)
{
    $redis = new Redis();

    $config = Config::get($name);

    $redis->connect($config['host'], $config['port']);
    $redis->select($db !== null ? $db : $config['database']);
    $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

    if (isset($config['prefix'])) {
        $redis->setOption(Redis::OPT_PREFIX, $config['prefix']);
    }

    return $redis;
}

function runWithLock(string $lockPath, callable $callback, $skipSeconds = 0)
{
    if ($skipSeconds > 0 && file_exists($lockPath) && (time() - filemtime($lockPath)) < $skipSeconds) {
        return;
    }

    $fh = fopen($lockPath, 'c');
    if (!$fh) {
        throw new Exception('fopen failed');
    }
    $locked = false;

    try {
        $locked = flock($fh, LOCK_EX | LOCK_NB);
        if ($locked) {
            if (DEVELOPMENT) {
                echo sprintf("[%d] lock acquired for %s\n", getmypid(), $lockPath);
            }
            $callback();
            flock($fh, LOCK_UN);
            touch($lockPath); // should be called after unlock, otherwise it will remove the file
        } else {
            if (DEVELOPMENT) {
                echo sprintf("[%d] lock not acquired for %s\n", getmypid(), $lockPath);
            }
        }
    } finally {
        fclose($fh);
    }
}
