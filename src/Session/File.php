<?php

class Session_File extends Workerman\Protocols\Http\Session\FileSessionHandler
{
    public static function init()
    {
        $sessionDir = TMP_DIR.'/sessions';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0700, true);
        }
        static::sessionSavePath($sessionDir);
    }

    public function updateTimestamp($sessionId)
    {
        $file = self::sessionFile($sessionId);
        clearstatcache(true, $file);
        if (file_exists($file)) {
            touch($file);
        }
    }
}
