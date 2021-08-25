<?php

class Config
{
    public static $config;

    public static function load()
    {
        self::$config = self::validate();
    }

    public static function get($path, $default = null)
    {
        $value      = self::$config;
        $hasDefault = count(func_get_args()) === 2;
        foreach (explode('.', $path) as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } elseif ($hasDefault) {
                return $default;
            } else {
                var_dump(self::$config);
                throw new Exception('Config key '.$path.' not found');
            }
        }
        return $value;
    }

    public static function has($path)
    {
        $value = self::$config;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }
        return true;
    }

    public static function validate()
    {
        $config = yaml_parse_file(APP_DIR.'/config.yaml');

        foreach ($config as $key => $values) {
            if (isset($values['host'], $values['port'])) {
                $host = $values['host'];
                $port = $values['port'];
                if (!empty($host)) {
                    if ($host[0] != '/') {
                        if (is_numeric($port)) {
                            self::checkPort($key, $host, $port);
                        }
                    } else {
                        if (!file_exists($host)) {
                            throw new Exception(sprintf('Cannot connect to %s via %s', $key, $host));
                        }
                    }
                }
            }
        }

        return $config;
    }

    public static function flattenKeys($values)
    {
        $it   = new RecursiveIteratorIterator(new RecursiveArrayIterator($values), RecursiveIteratorIterator::SELF_FIRST);
        $path = [];
        $keys = [];
        foreach ($it as $k => $v) {
            $depth        = $it->getDepth();
            $path[$depth] = $k;
            if (!is_array($v) || count($v) === 0 || array_keys($v) === 0) {
                $keys[implode('.', array_slice($path, 0, $depth + 1))] = true;
            }
        }
        return $keys;
    }

    private static function checkPort($host, $port)
    {
        $ret = fsockopen($host, $port, timeout: 1);
        if ($ret === false) {
            return false;
        }
        fclose($ret);
        return true;
    }
}
