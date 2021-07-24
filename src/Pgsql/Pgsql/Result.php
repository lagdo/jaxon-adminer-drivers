<?php

namespace Lagdo\Adminer\Drivers\Pgsql\Pgsql;

class Result {
    var $_result, $_offset = 0, $num_rows;

    function __construct($result) {
        $this->_result = $result;
        $this->num_rows = pg_num_rows($result);
    }

    function fetch_assoc() {
        return pg_fetch_assoc($this->_result);
    }

    function fetch_row() {
        return pg_fetch_row($this->_result);
    }

    function fetch_field() {
        $column = $this->_offset++;
        $return = new stdClass;
        if (function_exists('pg_field_table')) {
            $return->orgtable = pg_field_table($this->_result, $column);
        }
        $return->name = pg_field_name($this->_result, $column);
        $return->orgname = $return->name;
        $return->type = pg_field_type($this->_result, $column);
        $return->charsetnr = ($return->type == "bytea" ? 63 : 0); // 63 - binary
        return $return;
    }

    function __destruct() {
        pg_free_result($this->_result);
    }
}
