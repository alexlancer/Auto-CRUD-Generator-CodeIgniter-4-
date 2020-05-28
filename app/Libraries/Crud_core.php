<?php

namespace App\Libraries;

use App\Models\CrudModel;
use CodeIgniter\HTTP\RequestInterface;

class Crud_core
{
    protected $schema, // table schema
        $base = null, //prefix uri or parrent controller.
        $action = 'add',  //determine create or update // default is create (add)
        $table, //string
        $table_title, //string
        $form_title_add, //string
        $form_title_update, //string
        $form_submit, //string
        $form_submit_update, //string
        $fields = [], //array of field options: (type, required, label),
        $id,  //primary key value
        $id_field,  //primary key field
        $current_values, //will get current form values before updating
        $db, //db connection instance
        $model, //db connection instance
        $request;

    function __construct($params, RequestInterface $request)
    {
        $this->request = $request;
        $this->table = $table = $params['table'];
        $this->db = db_connect();
        $this->model = new CrudModel($this->db);

        $this->schema = $this->schema($table);
        $this->table_title = (isset($params['table_title']) ? $params['table_title'] : 'All items');
        $this->form_submit = (isset($params['form_submit']) ? $params['form_submit'] : 'Submit');
        $this->form_title_update = (isset($params['form_title_update']) ? $params['form_title_update'] : 'Update Item');
        $this->form_submit_update = (isset($params['form_submit_update']) ? $params['form_submit_update'] : 'Update');
        $this->form_title_add = (isset($params['form_title_add']) ? $params['form_title_add'] : 'Create Item');
        //Field options
        if (isset($params['fields']) && $params['fields']) {
            $this->fields = $params['fields'];
        }
        //Base uri
        if (isset($params['base']) && $params['base']) {
            $this->base = $params['base'];
        }
        //Show MySQL schema
        if (isset($params['dev']) && $params['dev']) {
            echo "<pre>";
            print_r($this->schema);
            echo "</pre>";
        }
    }



    function current_values($id)
    {
        $this->id_field = $this->get_primary_key_field_name();

        $this->current_values = $item = $this->model->getItem($this->table, $this->id_field, $id);
        if (!$item) {
            $this->flash('warning', 'The record does not exist');
            return false;
        }

        $this->id = $id;
        $this->action = 'edit';
        return $item;
    }

    function view($page_number, $per_page, $columns = null, $where = null, $order = null)
    {

        //$root_url = $this->base . '/' . $this->table;

        $total_rows = $this->model->countTotalRows($this->table, $where, $this->request, $this->schema);

        $offset = $per_page * ($page_number - 1);

        //Start of actual results query
        $items = $this->model->getItems($this->table, $where, $this->request, $this->schema, $this->fields, $order, $offset, $per_page);

        //Pagination
        $pager = service('pager');
        $pagination = $pager->makeLinks($page_number, $per_page, $total_rows, 'pagination');

        return $this->items_table($columns, $items, $pagination);
    }

    public function parent_form()
    {
    }

    /////////////////////////
    /////////////////////////
    ///////HELPERS/////////
    /////////////////////////
    /////////////////////////

    protected function items_table($columns = null, $items, $pagination)
    {
        helper('form');
        $fields = $this->fields;
        $table = '<div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">' . $this->table_title . '</h3>

                <div class="card-tools">
                  <a class="btn btn-primary btn-sm" href="' . $this->base . '/' . $this->table . '/add">' . $this->form_title_add . '</a>
                </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body table-responsive p-0">
                <table class="table table-hover text-nowrap" id="' . $this->table . '">';

        if ($columns) {

            foreach ($columns as $column) {
                if (is_array($column)) {

                    $label = $column['label'];
                } else {
                    $label = (isset($fields[$column]['label']) ? $fields[$column]['label'] : ucfirst(str_replace('_', ' ', $column)));
                }
                $table .= '<th>' . $label . '</th>';
            }
        } else {

            foreach ($this->schema as $item) {
                $label = (isset($fields[$item->Field]['label']) ? $fields[$item->Field]['label'] : ucfirst(str_replace('_', ' ', $item->Field)));
                $table .= '<th>' . $label . '</th>';
            }
        }

        $table .= '<th width="10%">Actions</th>';
        $table .= '</tr></thead><tbody><tr>' . form_open();


        //Search fields

        if ($columns) {

            foreach ($columns as $column) {
                //check date fields
                if (is_array($column)) {
                    $table .= '<td></td>';
                    continue;
                } elseif (isset($fields[$column]['type']) && strpos($fields[$column]['type'], 'date') !== FALSE) {
                    $field_type = 'date';
                } else {
                    //check this field type in schema
                    foreach ($this->schema as $field_types) {
                        if ($field_types->Field != $column)
                            continue;

                        if (strpos($field_types->Type, 'date') !== FALSE)
                            $field_type = 'date';
                        else
                            $field_type = 'text';
                    }
                }

                $label = (isset($fields[$column]['label']) ? $fields[$column]['label'] : ucfirst(str_replace('_', ' ', $column)));
                $table .= '<td><input type="' . $field_type . '" name="' . $column . '" class="form-control pull-right" value="' . set_value($column) . '" placeholder="' . $label . '"></td>';
            }
        } else {
            foreach ($this->schema as $item) {
                if (strpos($item->Type, 'date') !== FALSE)
                    $field_type = 'date';
                else
                    $field_type = 'text';

                $label = (isset($fields[$item->Field]['label']) ? $fields[$item->Field]['label'] : ucfirst(str_replace('_', ' ', $item->Field)));
                $table .= '<td><input type="' . $field_type . '" name="' . $item->Field . '" class="form-control pull-right" value="' . set_value($item->Field) . '" placeholder="' . $label . '"></td>';
            }
        }

        $table .= '<input type="hidden" name="table_search" class="form-control pull-right" value="' . $this->table . '">';
        $table .= '<td class="text-center"><input class="btn  btn-default" type="submit" value="Search"></td></tr></form>';


        // Result items

        foreach ($items as $item) {
            $table .= '<tr class="row_item" >';
            $fields = $this->fields;
            if ($columns) {
                foreach ($columns as $column) {
                    if (isset($fields[$column]['relation'])) {
                        $display_val = '';
                        if (is_array($fields[$column]['relation']['display'])) {

                            foreach ($fields[$column]['relation']['display'] as $rel_display) {
                                $display_val .= $item->{$rel_display} . ' ';
                            }
                            $display_val = trim($display_val);
                            //
                        } else
                            $display_val = $item->{$fields[$column]['relation']['display']};
                    } else
                        $display_val = $item->{$column};

                    $table .= '<td>' . $display_val . '</td>';
                }
            } else {
                foreach ($this->schema as $column) {
                    $col_name = $column->Field;
                    if (isset($fields[$col_name]['relation'])) {
                        $display_val = '';
                        if (is_array($fields[$col_name]['relation']['display'])) {

                            foreach ($fields[$col_name]['relation']['display'] as $rel_display) {
                                $display_val .= $item->{$rel_display} . ' ';
                            }
                            $display_val = trim($display_val);
                            //
                        } else
                            $display_val = $item->{$fields[$col_name]['relation']['display']};
                    } else
                        $display_val = $item->{$col_name};


                    $table .= '<td>' . $display_val . '</td>';
                }
            }
            $primary_key = $this->get_primary_key_field_name();
            $table .= '<td class="text-center"><a href="' . $this->base . '/' . $this->table . '/edit/' . $item->{$primary_key} . '" class="btn btn-success btn-sm">Edit</a></td>';
            $table .= '</tr>';
        }
        $table .= '</tbody></table></div>';
        $table .= '<div class="card-footer clearfix">';
        if ($this->request->getPost('table_search')) {
            $table .= '<a href="' . $this->base . '/' . $this->table . '" class="btn btn-warning btn-xs"><i class="fa fa-times"></i> Clear filters</a>';
        }
        //$this->ci->pagination->create_links()

        $table .=  $pagination . '</div></div>';

        return $table;
    }

    function schema()
    {
        return $this->model->schema($this->table);
    }

    protected function get_primary_key_field_name()
    {
        return $this->model->get_primary_key_field_name($this->table);
    }


    //Set flashdata session
    protected function flash($key, $value)
    {
        $session = session();
        $session->setFlashdata($key, $value);
        return true;
    }

    public function getTableTitle()
    {
        return $this->table_title;
    }
}
