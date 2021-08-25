<?php

class DB
{
    protected $conn;
    private static $instances = [];

    protected function __construct($name = 'default')
    {
        $config     = Config('db.'.$name);
        $this->conn = new PDO($config['dsn'], $config['username'] ?? null, $config['password'] ?? null, [
            PDO::ATTR_CASE               => PDO::CASE_LOWER,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_ORACLE_NULLS       => PDO::NULL_EMPTY_STRING,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function execute($sql, $params = null)
    {
        $stmt = $this->conn->prepare($sql);
        if ($params) {
            foreach ($params as $key => $value) {
                $stmt->bindValue(':'.$key, $value);
            }
        }
        $stmt->execute();
        return $stmt;
    }

    public function one($sql, $params = null)
    {
        return $this->execute($sql, $params)->fetchColumn();
    }

    public function row($sql, $params = null)
    {
        return $this->execute($sql, $params)->fetch();
    }

    public function all($sql, $params = null)
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    public function col($sql, $params = null)
    {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public function map($sql, $params = null)
    {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_GROUP);
    }

    public static function instance($name = 'default')
    {
        if (!isset(self::$instances[$name])) {
            self::$instances[$name] = new static($name);
        }
        return self::$instances[$name];
    }

    public function list($arr, $quote = false)
    {
        if ($quote) {
            $values = [];
            $conn   = $this->conn;
            foreach ($arr as $val) {
                $values[] = $conn->quote($val);
            }
            return implode(', ', $values);
        }
        return implode(', ', $arr);
    }

    public static function raw($text)
    {
        return new DB_Raw($text);
    }

    public function insert($table, $data, $suffix = '')
    {
        $columns      = [];
        $placeholders = [];
        $binds        = [];
        foreach ($data as $key => $value) {
            $columns[] = $key;
            if ($value instanceof DB_Raw) {
                $placeholders[] = $value->text;
            } else {
                $key            = ':'.$key;
                $placeholders[] = $key;
                $binds[$key]    = $value;
            }
        }
        $sql = 'INSERT INTO '.$table.' ('.implode(', ', $columns).') VALUES ('.implode(', ', $placeholders).')';
        if ($suffix) {
            $sql .= ' '.$suffix;
        }
        return $this->execute($sql, $binds);
    }

    public function delete($table, $conditions, $suffix = '')
    {
        $placeholders = [];
        $binds        = [];
        foreach ($conditions as $key => $value) {
            if ($value instanceof DB_Raw) {
                $placeholders[] = $key.' = '.$value->text;
            } else {
                $placeholders[]  = $key.' = :'.$key;
                $binds[':'.$key] = $value;
            }
        }
        $sql = 'DELETE FROM '.$table.' WHERE '.implode(' AND ', $placeholders);
        if ($suffix) {
            $sql .= ' '.$suffix;
        }
        return $this->execute($sql, $binds);
    }

    public function update($table, $data, $conditions, $suffix = '')
    {
        $placeholders = [];
        $binds        = [];
        foreach ($data as $key => $value) {
            if ($value instanceof DB_Raw) {
                $placeholders[] = $key.' = '.$value->text;
            } else {
                $placeholders[]    = $key.' = :s_'.$key;
                $binds[':s_'.$key] = $value;
            }
        }
        $conditionPlaceholders = [];
        foreach ($conditions as $key => $value) {
            if ($value instanceof DB_Raw) {
                $conditionPlaceholders[] = $key.' = '.$value->text;
            } else {
                $conditionPlaceholders[] = $key.' = :w_'.$key;
                $binds[':w_'.$key]       = $value;
            }
        }
        $sql = 'UPDATE '.$table.' SET '.implode(', ', $placeholders).' WHERE '.implode(' AND ', $conditionPlaceholders);
        if ($suffix) {
            $sql .= ' '.$suffix;
        }
        return $this->execute($sql, $binds);
    }
}
