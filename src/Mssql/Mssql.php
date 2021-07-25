<?php
/**
* @author Jakub Cernohuby
* @author Vladimir Stastka
* @author Jakub Vrana
*/

namespace Lagdo\Adminer\Drivers\Mssql;

use Lagdo\Adminer\Drivers\ServerInterface;

class Mssql implements ServerInterface
{
    /**
     * @inheritDoc
     */
    public function getDriver()
    {
        return "mssql";
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return "MS SQL (beta)";
    }

    /**
     * Get a connection to the server, based on the config and available packages
     */
    protected function createConnection()
    {
        if(extension_loaded("sqlsrv"))
        {
            return new Sqlsrv\Connection();
        }
        if(extension_loaded("mssql"))
        {
            return new Mssql\Connection();
        }
        if(extension_loaded("pdo_dblib"))
        {
            return new Pdo\Connection();
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function connect()
    {
        global $adminer;
        $connection = $this->createConnection();
        $credentials = $adminer->credentials();
        if ($connection->connect($credentials[0], $credentials[1], $credentials[2])) {
            return $connection;
        }
        return $connection->error;
    }

    /**
     * @inheritDoc
     */
    public function idf_escape($idf)
    {
        return "[" . str_replace("]", "]]", $idf) . "]";
    }

    public function table($idf) {
        return ($_GET["ns"] != "" ? idf_escape($_GET["ns"]) . "." : "") . idf_escape($idf);
    }

    public function get_databases($flush) {
        return get_vals("SELECT name FROM sys.databases WHERE name NOT IN ('master', 'tempdb', 'model', 'msdb')");
    }

    public function limit($query, $where, $limit, $offset = 0, $separator = " ") {
        return ($limit !== null ? " TOP (" . ($limit + $offset) . ")" : "") . " $query$where"; // seek later
    }

    public function limit1($table, $query, $where, $separator = "\n") {
        return limit($query, $where, 1, 0, $separator);
    }

    public function db_collation($db, $collations) {
        global $connection;
        return $connection->result("SELECT collation_name FROM sys.databases WHERE name = " . q($db));
    }

    public function engines() {
        return array();
    }

    public function logged_user() {
        global $connection;
        return $connection->result("SELECT SUSER_NAME()");
    }

    public function tables_list() {
        return get_key_vals("SELECT name, type_desc FROM sys.all_objects WHERE schema_id = SCHEMA_ID(" . q(get_schema()) . ") AND type IN ('S', 'U', 'V') ORDER BY name");
    }

    public function count_tables($databases) {
        global $connection;
        $return = array();
        foreach ($databases as $db) {
            $connection->select_db($db);
            $return[$db] = $connection->result("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES");
        }
        return $return;
    }

    public function table_status($name = "", $fast = false) {
        $return = array();
        foreach (get_rows("SELECT ao.name AS Name, ao.type_desc AS Engine, (SELECT value FROM fn_listextendedproperty(default, 'SCHEMA', schema_name(schema_id), 'TABLE', ao.name, null, null)) AS Comment FROM sys.all_objects AS ao WHERE schema_id = SCHEMA_ID(" . q(get_schema()) . ") AND type IN ('S', 'U', 'V') " . ($name != "" ? "AND name = " . q($name) : "ORDER BY name")) as $row) {
            if ($name != "") {
                return $row;
            }
            $return[$row["Name"]] = $row;
        }
        return $return;
    }

    public function is_view($table_status) {
        return $table_status["Engine"] == "VIEW";
    }

    public function fk_support($table_status) {
        return true;
    }

    public function fields($table) {
        $comments = get_key_vals("SELECT objname, cast(value as varchar(max)) FROM fn_listextendedproperty('MS_DESCRIPTION', 'schema', " . q(get_schema()) . ", 'table', " . q($table) . ", 'column', NULL)");
        $return = array();
        foreach (get_rows("SELECT c.max_length, c.precision, c.scale, c.name, c.is_nullable, c.is_identity, c.collation_name, t.name type, CAST(d.definition as text) [default]
FROM sys.all_columns c
JOIN sys.all_objects o ON c.object_id = o.object_id
JOIN sys.types t ON c.user_type_id = t.user_type_id
LEFT JOIN sys.default_constraints d ON c.default_object_id = d.parent_column_id
WHERE o.schema_id = SCHEMA_ID(" . q(get_schema()) . ") AND o.type IN ('S', 'U', 'V') AND o.name = " . q($table)
        ) as $row) {
            $type = $row["type"];
            $length = (preg_match("~char|binary~", $type) ? $row["max_length"] : ($type == "decimal" ? "$row[precision],$row[scale]" : ""));
            $return[$row["name"]] = array(
                "field" => $row["name"],
                "full_type" => $type . ($length ? "($length)" : ""),
                "type" => $type,
                "length" => $length,
                "default" => $row["default"],
                "null" => $row["is_nullable"],
                "auto_increment" => $row["is_identity"],
                "collation" => $row["collation_name"],
                "privileges" => array("insert" => 1, "select" => 1, "update" => 1),
                "primary" => $row["is_identity"], //! or indexes.is_primary_key
                "comment" => $comments[$row["name"]],
            );
        }
        return $return;
    }

    public function indexes($table, $connection2 = null) {
        $return = array();
        // sp_statistics doesn't return information about primary key
        foreach (get_rows("SELECT i.name, key_ordinal, is_unique, is_primary_key, c.name AS column_name, is_descending_key
FROM sys.indexes i
INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
WHERE OBJECT_NAME(i.object_id) = " . q($table)
        , $connection2) as $row) {
            $name = $row["name"];
            $return[$name]["type"] = ($row["is_primary_key"] ? "PRIMARY" : ($row["is_unique"] ? "UNIQUE" : "INDEX"));
            $return[$name]["lengths"] = array();
            $return[$name]["columns"][$row["key_ordinal"]] = $row["column_name"];
            $return[$name]["descs"][$row["key_ordinal"]] = ($row["is_descending_key"] ? '1' : null);
        }
        return $return;
    }

    public function view($name) {
        global $connection;
        return array("select" => preg_replace('~^(?:[^[]|\[[^]]*])*\s+AS\s+~isU', '', $connection->result("SELECT VIEW_DEFINITION FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = " . q($name))));
    }

    public function collations() {
        $return = array();
        foreach (get_vals("SELECT name FROM fn_helpcollations()") as $collation) {
            $return[preg_replace('~_.*~', '', $collation)][] = $collation;
        }
        return $return;
    }

    public function information_schema($db) {
        return false;
    }

    public function error() {
        global $connection;
        return nl_br(h(preg_replace('~^(\[[^]]*])+~m', '', $connection->error)));
    }

    public function create_database($db, $collation) {
        return queries("CREATE DATABASE " . idf_escape($db) . (preg_match('~^[a-z0-9_]+$~i', $collation) ? " COLLATE $collation" : ""));
    }

    public function drop_databases($databases) {
        return queries("DROP DATABASE " . implode(", ", array_map('idf_escape', $databases)));
    }

    public function rename_database($name, $collation) {
        if (preg_match('~^[a-z0-9_]+$~i', $collation)) {
            queries("ALTER DATABASE " . idf_escape(DB) . " COLLATE $collation");
        }
        queries("ALTER DATABASE " . idf_escape(DB) . " MODIFY NAME = " . idf_escape($name));
        return true; //! false negative "The database name 'test2' has been set."
    }

    public function auto_increment() {
        return " IDENTITY" . ($_POST["Auto_increment"] != "" ? "(" . number($_POST["Auto_increment"]) . ",1)" : "") . " PRIMARY KEY";
    }

    public function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
        $alter = array();
        $comments = array();
        foreach ($fields as $field) {
            $column = idf_escape($field[0]);
            $val = $field[1];
            if (!$val) {
                $alter["DROP"][] = " COLUMN $column";
            } else {
                $val[1] = preg_replace("~( COLLATE )'(\\w+)'~", '\1\2', $val[1]);
                $comments[$field[0]] = $val[5];
                unset($val[5]);
                if ($field[0] == "") {
                    $alter["ADD"][] = "\n  " . implode("", $val) . ($table == "" ? substr($foreign[$val[0]], 16 + strlen($val[0])) : ""); // 16 - strlen("  FOREIGN KEY ()")
                } else {
                    unset($val[6]); //! identity can't be removed
                    if ($column != $val[0]) {
                        queries("EXEC sp_rename " . q(table($table) . ".$column") . ", " . q(idf_unescape($val[0])) . ", 'COLUMN'");
                    }
                    $alter["ALTER COLUMN " . implode("", $val)][] = "";
                }
            }
        }
        if ($table == "") {
            return queries("CREATE TABLE " . table($name) . " (" . implode(",", (array) $alter["ADD"]) . "\n)");
        }
        if ($table != $name) {
            queries("EXEC sp_rename " . q(table($table)) . ", " . q($name));
        }
        if ($foreign) {
            $alter[""] = $foreign;
        }
        foreach ($alter as $key => $val) {
            if (!queries("ALTER TABLE " . idf_escape($name) . " $key" . implode(",", $val))) {
                return false;
            }
        }
        foreach ($comments as $key => $val) {
            $comment = substr($val, 9); // 9 - strlen(" COMMENT ")
            queries("EXEC sp_dropextendedproperty @name = N'MS_Description', @level0type = N'Schema', @level0name = " . q(get_schema()) . ", @level1type = N'Table', @level1name = " . q($name) . ", @level2type = N'Column', @level2name = " . q($key));
            queries("EXEC sp_addextendedproperty @name = N'MS_Description', @value = " . $comment . ", @level0type = N'Schema', @level0name = " . q(get_schema()) . ", @level1type = N'Table', @level1name = " . q($name) . ", @level2type = N'Column', @level2name = " . q($key));
        }
        return true;
    }

    public function alter_indexes($table, $alter) {
        $index = array();
        $drop = array();
        foreach ($alter as $val) {
            if ($val[2] == "DROP") {
                if ($val[0] == "PRIMARY") { //! sometimes used also for UNIQUE
                    $drop[] = idf_escape($val[1]);
                } else {
                    $index[] = idf_escape($val[1]) . " ON " . table($table);
                }
            } elseif (!queries(($val[0] != "PRIMARY"
                ? "CREATE $val[0] " . ($val[0] != "INDEX" ? "INDEX " : "") . idf_escape($val[1] != "" ? $val[1] : uniqid($table . "_")) . " ON " . table($table)
                : "ALTER TABLE " . table($table) . " ADD PRIMARY KEY"
            ) . " (" . implode(", ", $val[2]) . ")")) {
                return false;
            }
        }
        return (!$index || queries("DROP INDEX " . implode(", ", $index)))
            && (!$drop || queries("ALTER TABLE " . table($table) . " DROP " . implode(", ", $drop)))
        ;
    }

    public function last_id() {
        global $connection;
        return $connection->result("SELECT SCOPE_IDENTITY()"); // @@IDENTITY can return trigger INSERT
    }

    public function explain($connection, $query) {
        $connection->query("SET SHOWPLAN_ALL ON");
        $return = $connection->query($query);
        $connection->query("SET SHOWPLAN_ALL OFF"); // connection is used also for indexes
        return $return;
    }

    public function found_rows($table_status, $where) {
    }

    public function foreign_keys($table) {
        $return = array();
        foreach (get_rows("EXEC sp_fkeys @fktable_name = " . q($table)) as $row) {
            $foreign_key = &$return[$row["FK_NAME"]];
            $foreign_key["db"] = $row["PKTABLE_QUALIFIER"];
            $foreign_key["table"] = $row["PKTABLE_NAME"];
            $foreign_key["source"][] = $row["FKCOLUMN_NAME"];
            $foreign_key["target"][] = $row["PKCOLUMN_NAME"];
        }
        return $return;
    }

    public function truncate_tables($tables) {
        return apply_queries("TRUNCATE TABLE", $tables);
    }

    public function drop_views($views) {
        return queries("DROP VIEW " . implode(", ", array_map('table', $views)));
    }

    public function drop_tables($tables) {
        return queries("DROP TABLE " . implode(", ", array_map('table', $tables)));
    }

    public function move_tables($tables, $views, $target) {
        return apply_queries("ALTER SCHEMA " . idf_escape($target) . " TRANSFER", array_merge($tables, $views));
    }

    public function trigger($name) {
        if ($name == "") {
            return array();
        }
        $rows = get_rows("SELECT s.name [Trigger],
CASE WHEN OBJECTPROPERTY(s.id, 'ExecIsInsertTrigger') = 1 THEN 'INSERT' WHEN OBJECTPROPERTY(s.id, 'ExecIsUpdateTrigger') = 1 THEN 'UPDATE' WHEN OBJECTPROPERTY(s.id, 'ExecIsDeleteTrigger') = 1 THEN 'DELETE' END [Event],
CASE WHEN OBJECTPROPERTY(s.id, 'ExecIsInsteadOfTrigger') = 1 THEN 'INSTEAD OF' ELSE 'AFTER' END [Timing],
c.text
FROM sysobjects s
JOIN syscomments c ON s.id = c.id
WHERE s.xtype = 'TR' AND s.name = " . q($name)
        ); // triggers are not schema-scoped
        $return = reset($rows);
        if ($return) {
            $return["Statement"] = preg_replace('~^.+\s+AS\s+~isU', '', $return["text"]); //! identifiers, comments
        }
        return $return;
    }

    public function triggers($table) {
        $return = array();
        foreach (get_rows("SELECT sys1.name,
CASE WHEN OBJECTPROPERTY(sys1.id, 'ExecIsInsertTrigger') = 1 THEN 'INSERT' WHEN OBJECTPROPERTY(sys1.id, 'ExecIsUpdateTrigger') = 1 THEN 'UPDATE' WHEN OBJECTPROPERTY(sys1.id, 'ExecIsDeleteTrigger') = 1 THEN 'DELETE' END [Event],
CASE WHEN OBJECTPROPERTY(sys1.id, 'ExecIsInsteadOfTrigger') = 1 THEN 'INSTEAD OF' ELSE 'AFTER' END [Timing]
FROM sysobjects sys1
JOIN sysobjects sys2 ON sys1.parent_obj = sys2.id
WHERE sys1.xtype = 'TR' AND sys2.name = " . q($table)
        ) as $row) { // triggers are not schema-scoped
            $return[$row["name"]] = array($row["Timing"], $row["Event"]);
        }
        return $return;
    }

    public function trigger_options() {
        return array(
            "Timing" => array("AFTER", "INSTEAD OF"),
            "Event" => array("INSERT", "UPDATE", "DELETE"),
            "Type" => array("AS"),
        );
    }

    public function schemas() {
        return get_vals("SELECT name FROM sys.schemas");
    }

    public function get_schema() {
        global $connection;
        if ($_GET["ns"] != "") {
            return $_GET["ns"];
        }
        return $connection->result("SELECT SCHEMA_NAME()");
    }

    public function set_schema($schema, $connection2 = null) {
        return true; // ALTER USER is permanent
    }

    public function use_sql($database) {
        return "USE " . idf_escape($database);
    }

    public function show_variables() {
        return array();
    }

    public function show_status() {
        return array();
    }

    public function convert_field($field) {
    }

    public function unconvert_field($field, $return) {
        return $return;
    }

    public function support($feature) {
        return preg_match('~^(comment|columns|database|drop_col|indexes|descidx|scheme|sql|table|trigger|view|view_trigger)$~', $feature); //! routine|
    }

    public function driver_config() {
        $types = array();
        $structured_types = array();
        foreach (array( //! use sys.types
            lang('Numbers') => array("tinyint" => 3, "smallint" => 5, "int" => 10, "bigint" => 20, "bit" => 1, "decimal" => 0, "real" => 12, "float" => 53, "smallmoney" => 10, "money" => 20),
            lang('Date and time') => array("date" => 10, "smalldatetime" => 19, "datetime" => 19, "datetime2" => 19, "time" => 8, "datetimeoffset" => 10),
            lang('Strings') => array("char" => 8000, "varchar" => 8000, "text" => 2147483647, "nchar" => 4000, "nvarchar" => 4000, "ntext" => 1073741823),
            lang('Binary') => array("binary" => 8000, "varbinary" => 8000, "image" => 2147483647),
        ) as $key => $val) {
            $types += $val;
            $structured_types[$key] = array_keys($val);
        }
        return array(
            'possible_drivers' => array("SQLSRV", "MSSQL", "PDO_DBLIB"),
            'jush' => "mssql",
            'types' => $types,
            'structured_types' => $structured_types,
            'unsigned' => array(),
            'operators' => array("=", "<", ">", "<=", ">=", "!=", "LIKE", "LIKE %%", "IN", "IS NULL", "NOT LIKE", "NOT IN", "IS NOT NULL"),
            'functions' => array("len", "lower", "round", "upper"),
            'grouping' => array("avg", "count", "count distinct", "max", "min", "sum"),
            'edit_functions' => array(
                array(
                    "date|time" => "getdate",
                ), array(
                    "int|decimal|real|float|money|datetime" => "+/-",
                    "char|text" => "+",
                )
            ),
        );
    }
}
