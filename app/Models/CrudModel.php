<?php

namespace App\Models;

use CodeIgniter\Database\ConnectionInterface;

class CrudModel
{

    protected $db;

    public function __construct(ConnectionInterface &$db)
    {
        $this->db = &$db;
    }

    public function schema($table)
    {
        $query = "SHOW COLUMNS FROM $table";
        $result = $this->db->query($query)->getResult();
        return $result;
    }

    function get_primary_key_field_name($table)
    {
        $query = "SHOW KEYS FROM $table WHERE Key_name = 'PRIMARY'";
        return $this->db->query($query)->getRow()->Column_name;
    }

    //Get one item
    public function getItem($table, $field, $id)
    {
        return $this->db->table($table)
            ->where([$field => $id])
            ->get()
            ->getRow();
    }

    //Get total rows per request
    public function countTotalRows($table, $where = null, $request, $schema)
    {
        $count_query = "SELECT COUNT(*) as total FROM " . $table;
        if ($where) {
            $count_query .= " WHERE (";
            $i = 0;
            foreach ($where as $key => $value) {
                if ($i > 0)
                    $count_query .= " AND ";

                //Check if operator is different from = (equal sign)
                $operator_arr = explode(" ", $key);
                if (isset($operator_arr[1]))
                    $operator = $operator_arr[1];
                else
                    $operator = "=";

                $count_query .= " `$operator_arr[0]`$operator" . $this->db->escape($value) . " ";
                $i++;
            }
            $count_query .= ")";
        }

        if ($table_search = $request->getPost('table_search')) {
            $allEmpty = true;
            $tempQuery = '';

            if ($where)
                $tempQuery .= " AND ";
            else
                $tempQuery .= " WHERE ";
            $tempQuery .= " ( ";
            $i = 0;
            foreach ($schema as $column) {
                if (trim($request->getPost($column->Field)) == '')
                    continue;

                $allEmpty = false;
                if ($i > 0)
                    $tempQuery .= " OR ";


                $tempQuery .= " " . $table_search
                    . "." . $column->Field
                    . " LIKE '%"
                    . trim($this->db->escapeLikeString($request->getPost($column->Field)))
                    . "%' ESCAPE '!'";

                $i++;
            }
            $tempQuery .= ")";
            if (!$allEmpty)
                $count_query .= $tempQuery;
        }

        return $this->db->query($count_query)->getRow()->total;
    }


    public function getItems($table, $where = null, $request, $schema, $fields, $order, $offset, $per_page)
    {
        $result_query = "SELECT * FROM " . $table;



        //Check for relation fields
        foreach ($fields as $key => $rel_field) {
            if (isset($rel_field['relation'])) {
                $rfield = $rel_field['relation'];
                $result_query .= " LEFT JOIN  " . $rfield['table'] . " ON " . $table . '.' . $key . "=" . $rfield['table'] . "." . $rfield['primary_key'] . "  ";
                //$this->db->join($rfield['table'], $table.'.'.$key.'='.$rfield['table'].'.'.$rfield['primary_key'], 'left');
            }
        }

        if ($where) {
            $result_query .= " WHERE (";
            $i = 0;
            foreach ($where as $key => $value) {
                if ($i > 0)
                    $result_query .= " AND ";

                //Check if operator is different from = (equal sign)
                $operator_arr = explode(" ", $key);
                if (isset($operator_arr[1]))
                    $operator = $operator_arr[1];
                else
                    $operator = "=";

                $result_query .= "  `$operator_arr[0]`$operator'$value' ";

                //$this->db->where($key, $value);
                $i++;
            }
            $result_query .= ")";
        }
        if ($request->getPost('table_search')) {
            $allEmpty = true;
            $tempQuery = '';
            $i = 0;
            if ($where)
                $tempQuery .= " AND ";
            else
                $tempQuery .= " WHERE ";

            $tempQuery .= " ( ";

            foreach ($schema as $column) {

                if ($request->getPost($column->Field) == '')
                    continue;

                $allEmpty = false;

                $col_search = $column->Field;
                if (isset($fields[$column->Field]['relation'])) {
                    //check if display is an array of columns
                    if (is_array($fields[$column->Field]['relation']['display'])) {
                        $col_search = $fields[$column->Field]['relation']['display'][0];
                    } else {
                        $col_search = $fields[$column->Field]['relation']['display'];
                    }
                    $table_search = $fields[$column->Field]['relation']['table'];
                } else {
                    $table_search = $table;
                }
                if ($i > 0)
                    $tempQuery .= " AND ";

                $tempQuery .= " " . $table_search . "." . $col_search
                    . " LIKE '%"
                    . trim($this->db->escapeLikeString($request->getPost($column->Field)))
                    . "%' ESCAPE '!'";
                //$this->db->like($table_search.'.'.$col_search, $request->getPost($column->Field), 'both');
                $i++;
            }

            $tempQuery .= " ) ";
            if (!$allEmpty)
                $result_query .= $tempQuery;
        }

        if ($order) {
            $result_query .= " ORDER BY ";
            $i = 0;
            foreach ($order as $ord) {
                if ($i > 0)
                    $result_query .= ", ";
                $result_query .= $ord[0] . " " . $ord[1];
                //$this->db->order_by($ord[0], $ord[1]);
                $i++;
            }
        } else {
            //get primary_key field name
            $pk = $this->get_primary_key_field_name($table);
            $result_query .= " ORDER BY " . $pk . " DESC";
            //$this->db->order_by($pk, 'DESC');
        }

        $result_query = rtrim($result_query, ',');
        $result_query .= " LIMIT $offset, $per_page ";
        //$this->db->limit($per_page, $offset);
        $page_items = $this->db->query($result_query)->getResult();


        return $page_items;
    }
}
