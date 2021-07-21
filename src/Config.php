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
        $value = self::$config;
        foreach (explode('.', $path) as $key) {
            if (array_key_exists($key, $value)) {
                $value = $value[$key];
            } else {
                return $default;
            }
        }
        return $value;
    }

    public static function has($path)
    {
        $value = self::$config;
        foreach (explode('.', $path) as $key) {
            if (!array_key_exists($value, $key)) {
                return false;
            }
            $value = $value[$key];
        }
        return true;
    }

    public static function validate()
    {
        $config = yaml_parse_file(APP_DIR.'/config.yaml');

        $allKeys = [];
        $envKeys = [];
        foreach ($config as $env => $values) {
            $keys = self::flattenKeys($values);
            $allKeys += $keys;
            $envKeys[$env] = $keys;
        }

        foreach ($envKeys as $env => $keys) {
            $diff = array_diff_key($allKeys, $keys);
            if ($diff) {
                throw new Exception(sprintf('Missing key(s) for environment "%s": %s', $env, implode(', ', array_keys($diff))));
            }
        }

        foreach ($config[ENV] as $key => $values) {
            if (isset($values['host'], $values['port'])) {
                $host = $values['host'];
                $port = $values['port'];
                if (!empty($host)) {
                    if ($host[0] != '/') {
                        checkPortOpen($key, $host, $port);
                    } else {
                        if (!file_exists($host)) {
                            throw new Exception(sprintf('Cannot connect to %s via %s', $key, $host));
                        }
                    }
                }
            }
        }

        return $config[ENV];
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
}
