<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_erdiagram;

/**
 * ER Diagram generator
 *
 * @package     tool_erdiagram
 * @author      Marcus Green
 * @copyright   Catalyst IT 2023
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagram {

    /**
     * Extract data from the xml file and convert it to
     * mermaid er diagram markdown.
     * https://mermaid.js.org/config/Tutorials.html
     *
     * @param  string $installxml //path to dbmxl file i.e. mod/label/db/install.xml
     * @param  array $options //array containing output option flags
     * @return string $output //mermaid markdown @TODO change variable name
     */
    public function process_file (string $installxml, string $path, array $options) {

        // TODO need to properly map from a path to a component.
        // This only works for /mod/foobar/ but not other plugin types.
        $prefix = '';
        if (substr($path, 0, 4) === 'mod/') {
            $prefix = substr($path, 4) . '_';
        }

        $output = <<<EOF
digraph component {

    fontname="Helvetica,Arial,sans-serif"
    nodesep=0.4
    node [
        shape=record,
        fontsize=9,
        fontname="Helvetica",
    ];
    edge [
        fontname="Helvetica,Arial,sans-serif",
        minlen=3
    ]
    graph [
        rankdir=LR,
        overlap=false,
        splines=true,
    ];

    comment="Now all of the component tables"

EOF;

        $xmldbfile = new \xmldb_file($installxml);
        $xmldbfile->loadXMLStructure();

        $xmldbstructure = $xmldbfile->getStructure();
        $tables = $xmldbstructure->getTables();

        $tablelinks = []; // Which fields in tables have explicit references?
        $externaltables = [];
        $componenttables = [];
        foreach ($tables as $table) {
            $componenttables[] = $table->getName();
        }
        $componenttablenodes = '';

        foreach ($tables as $table) {
            $tablename = $table->getName();
            $componenttablenodes .= "        \"$tablename\";\n";

            // Chunk for each table.
            $fields = '';
            foreach ($table->getFields() as $field) {
                if ($options['fieldnames']) {
                    $fieldtype = $this->get_field_type($field->getType());
                    $fieldname = $field->getName();
                    $fields .=
                        sprintf("            <tr><td %-30s align=\"left\">%-10s<td %-31s align=\"left\">%-26s</tr>\n",
                        "port=\"in$fieldname\"",
                        $fieldtype . '</td>',
                        "port=\"out$fieldname\"",
                        $fieldname . '</td>');

                }
            }
             $output .= <<<EOF

    $tablename [
        shape=none,
        margin=0,
        style=filled,
        color="#333333",
        fillcolor=white,
        label=<
        <table border="0" cellborder="1" cellspacing="0" cellpadding="2">
            <tr><td bgcolor="lightblue" colspan="2">$tablename</td></tr>
$fields
        </table>>
    ];

EOF;
            // Show references between tables.
            $foreignkeys = $this->get_foreign_keys($table);
            foreach ($foreignkeys as $fkey) {
                $reftable = $fkey->getReftable();
                $fields = $fkey->getFields();
                $reffields = $fkey->getReffields();
                if (!empty($reffields) && count($reffields) > 0) {
                    $tablelinks[] = "$tablename:{$fields[0]}";

                    if (in_array($reftable, $componenttables) ) {
                        // Show a column link if the referenced table is also in the diagram.
                        if ($options['fieldnames']) {
                            if ($tablename == $reftable) {
                                // Tune the rendering of self referencing links.
                                $output .= "    $tablename:in{$fields[0]}:w -> $reftable:in{$reffields[0]}:w [minlen=1];\n";
                            } else {
                                $output .= "    $tablename:out{$fields[0]} -> $reftable:in{$reffields[0]};\n";
                            }
                        } else {
                            $output .= "    $tablename -> $reftable;\n";
                        }
                    } else {
                        // Otherwise just link to an external table with no columns.
                        $externaltables[$reftable] = 1;
                        if ($options['fieldnames']) {
                            $output .= "    $tablename:out{$fields[0]} -> $reftable;\n";
                        } else {
                            $output .= "    $tablename -> $reftable;\n";
                        }
                    }
                }
            }

            // Now lets detect fields which look like they should have links but do not.
            // These columns must:
            // * Be an int column
            // * Have a name which matches another table exactly
            // * Or match another table with the 'id' suffix
            foreach ($table->getFields() as $field) {
                $fieldtype = $this->get_field_type($field->getType());
                $fieldname = $field->getName();
                $reftable = '';
                if (!is_array($fields)) {
                    continue;
                }
                if (in_array("$tablename:{$fields[0]}", $tablelinks)) {
                    // This column has an explicit reference already.
                    continue;
                }

                if (!empty($componenttables[$fieldname])) {
                    // Match column 'book' -> table 'book:id'.
                    $reftable = $fieldname;

                } elseif (!empty($componenttables[$prefix . '_' . $fieldname])) {
                    // Match column 'discussion' -> table 'forum_discussion:id'.
                    $reftable = $prefix . '_' . $fieldname;

                } elseif ($fieldname !== 'id' && substr($fieldname, -2) == 'id') {
                    // Match column 'bookid' -> table 'book:id'.
                    if (in_array(substr($fieldname, 0, -2), $componenttables)) {
                        $reftable = substr($fieldname, 0, -2);
                    }

                    // Match column 'bookid' -> table 'books:id'.
                    if (in_array(substr($fieldname, 0, -2) . 's', $componenttables)) {
                        $reftable = substr($fieldname, 0, -2) . 's';
                    }

                    // Match column 'postid' -> table 'forum_posts:id'.
                    if (in_array($prefix . substr($fieldname, 0, -2) . 's', $componenttables)) {
                        $reftable = $prefix . substr($fieldname, 0, -2) . 's';
                    }
                }

                if ($reftable) {
                    $output .= "    $tablename:out$fieldname -> $reftable:inid[label=\"implied\", style=\"dashed\", color=\"#666666\" fontsize=9];\n";
                }
            }
        }

        $output .= <<<EOF

    subgraph cluster_component {
        label="Component tables";
        style=filled;
		color="#eeeeee";
$componenttablenodes
    }

EOF;

        // Show external tables we discovered while linking.
        if ($externaltables) {

            $output .= <<<EOF

    comment="Now all of the external tables"

EOF;

            foreach ($externaltables as $table => $val) {
                $output .= <<<EOF
    $table [
        shape=none,
        margin=0,
        style=filled,
        color="#333333",
        fillcolor=white,

        label=<
        <table border="0" cellborder="1" cellspacing="0" cellpadding="3">
            <tr><td port="$table" bgcolor="orange" colspan="2">$table</td></tr>
        </table>>
    ]

EOF;
            }
            $exttables = '';
            foreach ($externaltables as $table => $val) {
                $exttables .= "        \"$table\";\n";
            }
            $output .= <<<EOF
    subgraph cluster_core_tables {
        label="Core tables";
        style=filled;
        color="#ffdd00";
$exttables
    }

EOF;
        }

        $output .= "}\n";
        return $output;
    }

    /**
     * Any key that is not a primary key is assumed to be
     * a PK/FK relationship.
     *
     * @param xmldb_table $table
     * @return array
     */
    private function get_foreign_keys(\xmldb_table $table) {
        $keys = $table->getKeys();
        $foreignkeys = [];
        foreach ($keys as $key) {
            if ($key->getName() !== "primary") {
                $foreignkeys[] = $key;
            }
        }
        return $foreignkeys;
    }

    /**
     * Inspired by the function getTypeSQL found at
     * lib/ddl/sqlite_sql_generator.php
     * The "correct" datatypes may depend on what database
     * you are familiar with
     *
     * @param int $fieldtype Constant of field types
     * @return string type
     */
    private function get_field_type($fieldtype) {

        switch ($fieldtype) {
            case XMLDB_TYPE_INTEGER:
                $typename = 'int';
                break;
            case XMLDB_TYPE_NUMBER:
                $typename = 'number';
                break;
            case XMLDB_TYPE_FLOAT:
                $typename = 'float';
                break;
            case XMLDB_TYPE_CHAR:
                $typename = 'varchar';
                break;
            case XMLDB_TYPE_BINARY:
                $typename = 'blob';
                break;
            case XMLDB_TYPE_DATETIME:
                $typename = 'datetime';
            default:
            case XMLDB_TYPE_TEXT:
                $typename = 'text';
                break;
        }
        return $typename;
    }

}

