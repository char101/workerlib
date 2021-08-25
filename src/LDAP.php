<?php

class LDAP
{
    protected $conn;
    private static $instance;

    protected function __construct()
    {
        $this->conn = ldap_connect(Config::get('ldap.uri'));
        if (!$this->conn) {
            throw new Exception_LDAP('Cannot connect to ldap server');
        }
        ldap_set_option($this->conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    }

    public function authenticate($username, $password, $attributes = [], $bind = true)
    {
        $conn = $this->conn;

        try {
            $sr = ldap_search($conn, Config::get('ldap.base'), 'uid='.$username, $attributes);
        } catch (ErrorException $e) {
            // ErrorException: ldap_search(): Search: No such object
            $sr = null;
        }
        if (!$sr) {
            throw new Exception_LDAP('Username tidak ditemukan');
        }

        $entry = ldap_first_entry($conn, $sr);
        if (!$entry) {
            throw new Exception_LDAP('Username tidak ditemukan');
        }

        $dn = ldap_get_dn($conn, $entry);

        if ($bind) {
            try {
                if (!ldap_bind($conn, $dn, $password)) {
                    throw new Exception_LDAP('Password salah');
                }
            } catch (ErrorException $e) {
                throw new Exception_LDAP('Password salah');
            }
        }

        $values = [];
        foreach (ldap_get_attributes($conn, $entry) as $attr => $val) {
            if (is_string($attr) && $attr !== 'count') {
                if ($val['count'] === 1) {
                    $values[$attr] = $val[0];
                } else {
                    unset($val['count']);
                    $values[$attr] = $val;
                }
            }
        }

        return $values;
    }

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new static();
        }
        return self::$instance;
    }
}
