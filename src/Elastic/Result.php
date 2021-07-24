<?php

namespace Lagdo\Adminer\Drivers\Elastic;

class Result {
    var $num_rows, $_rows;

    function __construct($rows) {
        $this->num_rows = count($rows);
        $this->_rows = $rows;
        reset($this->_rows);
    }

    function fetch_assoc() {
        $return = current($this->_rows);
        next($this->_rows);
        return $return;
    }

    function fetch_row() {
        return array_values($this->fetch_assoc());
    }

}
