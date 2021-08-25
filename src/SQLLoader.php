<?php

class SQLLoader
{
    private $file;
    private $db;
    private $sqls = [];

    private function __construct($file)
    {
        if (!is_file($file)) {
            throw new Exception($file.' does not exist');
        }
        $this->file = $file;
        $this->db   = DB::instance();
        $this->load();
    }

    public function __call($name, $args)
    {
        if (!array_key_exists($name, $this->sqls)) {
            throw new Exception('SQL '.$name.' does not exist in '.$this->file);
        }
        if (DEVELOPMENT) {
            $this->reload();
        }
        list($func, $sql) = $this->sqls[$name];
        // process substitutions SELECT col FROM TABLE WHERE cond1 {AND $cond2}
        // $sql->query(cond2: 'col2 = :col2', col2: 'value');
        if (strpos($sql, '$') !== false) {
            $sql = preg_replace_callback('/\{([^}]*)\$(\w+)([^}]*?)\}/', function ($matches) use ($args) {
                $key = $matches[2];
                if (isset($args[$key])) {
                    $value = $args[$key];
                    if (is_array($value)) {
                        $value = $this->db->list($value);
                    }
                    return $matches[1].$value.$matches[3];
                }
                return '';
            }, $sql);
        }
        return call_user_func_array([$this->db, $func], [$sql, $args]);
    }

    public function db($db = null)
    {
        if ($db === null) {
            return $this->db;
        }
        $this->db = DB::instance($db);
    }

    public static function instance($file)
    {
        $file = substr(realpath($file), strlen(APP_DIR) + 1);
        if (!isset(self::$instances[$file])) {
            self::$instances[$file] = new static($file);
        }
        return self::$instances[$file];
    }

    private function load()
    {
        $this->lastModification = filemtime($this->file);
        $fh                     = fopen($this->file);
        $name                   = null;
        $type                   = null;
        $lines                  = [];
        while (true) {
            $line = fgets($fh);
            if ($line === false) {
                break;
            }
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if (substr($line, 0, 2) === '--') {
                if ($line[2] === ':') {
                    // sql identifier
                    if ($name) {
                        $this->sqls[$name] = [$type, implode('\n', $lines)];
                        $this->lines       = [];
                    }
                    $name = trim(substr($line, 3));
                    if (strpos($name, ':') !== false) {
                        list($name, $type) = explode(':', $name);
                        $type              = trim($type);
                    } else {
                        $type = 'execute';
                    }
                    $name = trim($name);
                } else {
                    // a comment
                    continue;
                }
            } else {
                $lines[] = $line;
            }
        }
        if ($name) {
            $this->sqls[$name] = [$type, implode('\n', $lines)];
            $this->lines       = [];
        }
        fclose($fh);
    }

    private function reload()
    {
        clearstatcache(true, $this->file);
        if (filemtime($this->file) > $this->lastModification) {
            $this->sqls = [];
            $this->load();
        }
    }
}
