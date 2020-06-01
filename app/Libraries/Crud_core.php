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
        $request,
        $validator = false;

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

        helper('form');
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

    function parent_form()
    {

        $form = '';
        $post = $this->request->getPost();

        if (isset($post['form'])) {
            //This $_POST['form'] is just to check if the $_POST values are from
            // form submition and not search or something else
            unset($post['form']);
           
            if (isset($post['files']))
                unset($post['files']);
            
            //Create rules
            $novalidation = true;
            $this->validator = service('validation');
            //Fields to be unsetted before insert or update
            $unsets = [];
            //Store ['field1', 'field2', '...']  to be hashed with password_hash
            $toHash = [];

            foreach ($this->fields as $field => $params) {
                if (($this->action == 'add' && !isset($params['only_edit']) && @$params['only_edit'] !== TRUE) ||
                    ($this->action == 'edit' && !isset($params['only_add']) && @$params['only_add'] !== TRUE)
                ) {
                    $theLabel = $this->get_label($this->fields[$field], $field);
                    if ((isset($params['required']) && $params['required'] === TRUE) || (isset($params['type']) && $params['type'] == 'hidden')) {
                        $novalidation = false;
                        $this->validator->setRule($field, $theLabel, 'required');
                    }
                    if (isset($params['unique']) && isset($params['unique'][0]) && isset($params['unique'][1]) && $params['unique'][0] === TRUE) {
                        $unique_field = $params['unique'][1];
                        if (!isset($this->current_values) || $this->current_values->{$unique_field} != $post[$unique_field]) {
                            $novalidation = false;
                            $this->validator->setRule($field, $theLabel, 'is_unique[' . $this->table . '.' . $unique_field . ']');
                        }
                    }
                    if ((isset($params['confirm']) && $params['confirm'] === TRUE)) {

                        $novalidation = false;
                        $this->validator->setRule($field, $theLabel, 'trim');
                        $this->validator->setRule($field . '_confirm', $theLabel . ' confirmation', 'matches[' . $field . ']');
                        //Unset confirmation field
                        $unsets[] = $field . '_confirm';
                    }

                    if (isset($params['password_hash']) && $params['password_hash'] === TRUE) {
                        $toHash = [$field];
                    }
                }
            }


            if ($this->validator->withRequest($this->request)->run() || $novalidation)//|| empty($this->fields)
            {


                foreach ($unsets as $unset) {
                    unset($post[$unset]);
                }

                //Convert any array post to string
                foreach ($post as $key => $post_input) {
                    if (is_array($post_input)) {
                        if ($post_input[0] == '0') {
                            unset($post_input[0]);
                        }
                        $post[$key] = implode(',', $post_input);
                    }
                }

                foreach ($toHash as $hashIt) {
                    $post[$hashIt] = password_hash($hashIt, PASSWORD_DEFAULT);
                }


                if ($this->action == 'add') {
                    if (!$this->current_values) {
                        $this->id = $this->model->insertItem($this->table, $post);
                        if ($this->id) {
                            $this->flash('success', 'Successfully Added');
                        }
                    }
                } elseif ($this->action == 'edit') {
                    //Prepare data
                    //remove any foreign fields by compairing to schema
                    $update_data = [];
                    foreach ($this->schema as $schema_field) {
                        if (isset($post[$schema_field->Field])) {
                            $update_data[$schema_field->Field] = $post[$schema_field->Field];
                        }
                    }


                    $affected = $this->model->updateItem($this->table, [$this->id_field => $this->id], $update_data);
                    if ($affected == 1)
                        $this->flash('success', 'Successfully Updated');
                    else
                        $this->flash('danger', 'The record could not be update');
                }
                return ['redirect' => $this->base . '/' . $this->table . '/edit/' . $this->id];
            } else {
                // echo '<div>';
                //  print_r($this->validator->getErrors());
                // echo '<div>';
            }
        }

        $form .= '<div class="card card-primary">
            <div class="card-header">
              <h3 class="card-title">' . ($this->action == 'add' ? $this->form_title_add : $this->form_title_update) . '</h3>
              </div>' . form_open('/' . $this->base . '/' . $this->table . '/' . ($this->action == 'add' ? 'add' : 'edit/' . $this->id)) . '<div class="card-body">
              <input type="hidden" name="form" value="1"><div class="row">';

        $fields = $this->fields;
        foreach ($this->schema as $field) {

            $f = $field;
            if ($f->Extra == 'auto_increment') {
                continue;
            }

            if (isset($fields[$f->Field]['only_edit']) && $fields[$f->Field]['only_edit'] && $this->action == 'add') {
                continue;
            }

            if (isset($fields[$f->Field]['only_add']) && $fields[$f->Field]['only_add'] && $this->action == 'edit') {
                continue;
            }

            if (isset($fields[$f->Field]['type']) && $fields[$f->Field]['type'] == 'unset') {
                continue;
            }

            $label = $this->get_label($field);
            $field_type = (isset($fields[$f->Field]['type']) ? $fields[$f->Field]['type'] : $this->get_field_type($f));

            if ($field_type == 'enum' && !isset($fields[$f->Field]['values'])) {
                preg_match("/^enum\(\'(.*)\'\)$/", $f->Type, $matches);
                $fields[$f->Field]['values'] = explode("','", $matches[1]);
                $field_type = 'select';
            }
            //Check if relation table is set for the field
            if (isset($fields[$f->Field]['relation'])) {
                $rel = $fields[$f->Field]['relation'];
                $rel_table = $rel['table'];
                $rel_pk = $rel['primary_key'];
                $rel_order_by = $rel['order_by'];
                $rel_order = $rel['order'];
                $fields[$f->Field]['values'] = $this->model->getRelationItems($rel_table, $rel_order_by, $rel_order);
            }

            $field_values = (isset($fields[$f->Field]['values']) ? $fields[$f->Field]['values'] : null);

            $field_method = 'field_' . $field_type;

            //Checking if helper text is set for this field
            $helperText = '';
            if (isset($fields[$f->Field]['helper']))


                $helperText = '<small  class="form-text text-muted">' . $fields[$f->Field]['helper'] . '</small>';

            $class = "col-sm-12";
            if (isset($fields[$f->Field]['class']))
                $class = $fields[$f->Field]['class'];

            $hidden = false;
            if (isset($fields[$f->Field]['type']) && $fields[$f->Field]['type'] == 'hidden')
                $hidden = true;
            else {
                $form .= "<div class='$class'><div class='form-group'>";
            }

            //execute appropriate function
            $form .= $this->$field_method($f->Field, $label, (isset($fields[$f->Field]) ? $fields[$f->Field] : null), $field_values, $class);
            if (!$hidden) {
                $form .=  "$helperText</div></div>";
            }
        }


        $form .= '</div></div><div class="card-footer">
              <button type="submit" class="btn btn-primary">' . ($this->action == 'add' ? $this->form_submit : $this->form_submit_update) . '</button>
              </div>' . form_close() . '</div>';



        return $form;
    }

    protected function get_label($field, $default_label = '')
    {

        //When generating error labels, because $field is not the same object from Schema
        if (!isset($field->Type)) {
            return (isset($field['label']) ? $field['label'] : ucfirst(str_replace('_', ' ', $default_label)));
        }
        //  return ucfirst($field);

        //When generating form labels
        if (isset($this->fields[$field->Field]['label'])) {
            return $this->fields[$field->Field]['label'];
        } else {
            return ucfirst(str_replace('_', ' ', $field->Field));
        }
        //  return (isset($this->fields[$field->Field]['label']) ? $this->fields[$field->Field]['label'] : ucfirst(str_replace('_', ' ', $field->Field)));
    }

    protected function get_field_type($field)
    {
        $type = $field->Type;
        if (strpos($type, 'enum') !== FALSE) {
            return 'enum';
        } elseif (strpos($type, 'datetime') !== FALSE) {
            return 'datetime';
        } elseif (strpos($type, 'date') !== FALSE) {
            return 'date';
        } elseif (strpos($type, 'text') !== FALSE) {
            return 'textarea';
        } elseif (strpos($type, 'dropdown') !== FALSE) {
            return 'dropdown';
        } elseif (strpos($type, 'simple_dropdown') !== FALSE) {
            return 'simple_dropdown';
        } else {
            return 'text';
        }
    }

    protected function input_wrapper($field_type, $label, $input, $required)
    {
        $output = '<label for="' . $field_type . '">' . $label . ':' . ($required ? ' <span class="text-danger">*</span>' : '') . '</label>' . $input . '';
        if ($this->validator && $this->validator->hasError($field_type)) {
            $output .= '<div class="error text-danger">' . $this->validator->getError($field_type) . '</div>';
        }
        return $output;
    }

    protected function checkbox_wrapper($field_type, $label, $input, $required)
    {
        return '<label for="' . $field_type . '">' . $label . ':' . ($required ? ' <span class="text-danger">*</span>' : '') . '</label>
    <div class="checkbox">' . $input . '</div>';
        if ($this->validator && $this->validator->hasError($field_type)) {
            $output .= '<div class="error text-danger">' . $this->validator->getError($field_type) . '</div>';
        }
    }

    protected function field_text($field_type, $label, $field_params)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $input = '<input type="text" ' . $required . ' class="form-control" id="' . $field_type . '" name="' . $field_type . '"  placeholder="" value="' . set_value($field_type, (isset($this->current_values->{$field_type}) ? $this->current_values->{$field_type} : '')) . '" autocomplete="off">';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_password($field_type, $label, $field_params, $field_values, $class)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $input = '<input type="password" ' . $required . ' class="form-control" id="' . $field_type . '" name="' . $field_type . '"   value="" >';
        $password = $this->input_wrapper($field_type, $label, $input, $required);
        $password_confirm = '';
        if (isset($field_params['confirm']) && $field_params['confirm']) {
            $input_confirm = '<input type="password" ' . $required . ' class="form-control" id="' . $field_type . '_confirm" name="' . $field_type . '_confirm"   value="" >';
            $password_confirm = '</div></div><div class="' . $class . '"><div class="form-group">' . $this->input_wrapper($field_type . '_confirm', $label . ' confirm', $input_confirm, $required);
        }
        return $password . $password_confirm;
    }

    protected function field_number($field_type, $label, $field_params)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $attr = '';

        if (isset($field_params['attr'])) {
            foreach ($field_params['attr'] as $key => $value) {
                $attr .= ' ' . $key . '="' . $value . '" ';
            }
        }
        $input = '<input type="number" ' . $required . ' ' . $attr . ' class="form-control" id="' . $field_type . '" name="' . $field_type . '"  placeholder="" value="' . set_value($field_type, (isset($this->current_values->{$field_type}) ? $this->current_values->{$field_type} : '')) . '" autocomplete="off">';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_select($field_type, $label, $field_params, $values)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $input = '<select ' . $required . ' class="form-control" id="' . $field_type . '" name="' . $field_type . '"><option></option>';
        foreach ($values as $value) {
            $input .= '<option value="' . $value . '" ' . set_select($field_type, $value, (isset($this->current_values->{$field_type}) && $this->current_values->{$field_type} == $value ? TRUE : FALSE)) . '>' . ucfirst($value) . '</option>';
        }
        $input .= '</select><script>$(document).ready(function() {
    $("#' . $field_type . '").select2({theme: "bootstrap4"});
});</script>';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }



    protected function field_dropdown($field_type, $label, $field_params, $values)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        //randomize_id for select2
        $rand_number = mt_rand(1545645, 15456546);
        $rid = $field_type . '_' . $rand_number;
        $input = '<select  class="form-control" ' . $required . ' id="' . $rid . '" name="' . $field_type . '"><option></option>';
        $pk = $field_params['relation']['primary_key'];
        $display = $field_params['relation']['display'];
        foreach ($values as $value) {
            if (is_array($display)) {
                $display_val = '';
                foreach ($display as $disp) {
                    $display_val .= $value->{$disp} . ' ';
                }
                $display_val = trim($display_val);
            } else {
                $display_val = $value->{$display};
            }
            $input .= '<option value="' . $value->{$pk} . '" ' . set_select($field_type, $value->{$pk}, (isset($this->current_values->{$field_type}) && $this->current_values->{$field_type} == $value->{$pk} ? TRUE : FALSE)) . '>' . $display_val . '</option>';
        }
        $input .= '</select><script>$(document).ready(function() {
    $("#' . $rid . '").select2({theme: "bootstrap4"});
});</script>';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_simple_dropdown($field_type, $label, $field_params, $values)
    {

        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        //randomize_id for select2
        $rand_number = mt_rand(1545645, 15456546);
        $rid = $field_type . '_' . $rand_number;
        $input = '<select  class="form-control" ' . $required . ' id="' . $rid . '" name="' . $field_type . '"><option></option>';
        $pk = $field_params['relation']['primary_key'];

        $display = $field_params['relation']['display'];
        foreach ($values as $value) {

            if (is_array($display)) {
                $display_val = '';
                foreach ($display as $disp) {
                    $display_val .= $value->{$disp} . ' ';
                }
                $display_val = trim($display_val);
            } else {
                $display_val = $value->{$display};
            }
            $input .= '<option value="' . $value->{$pk} . '" ' . set_select($field_type, $value->{$pk}, (isset($this->current_values->{$field_type}) && $this->current_values->{$field_type} == $value->{$pk} ? TRUE : FALSE)) . '>' . $display_val . '</option>';
        }
        $input .= '</select>';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_checkboxes($field_type, $label, $field_params, $values)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');

        $input = '<input name="' . $field_type . '[]" type="checkbox" checked>';
        $pk = $field_params['relation']['primary_key'];
        $display = $field_params['relation']['display'];

        //Values can be set from $_POST (if this is a form submission)
        if ($this->request->getPost($field_type)) {
            $val_arr = $this->request->getPost($field_type);
        }
        //Values can be set from current row (if this is an editing of the record and no $_POST yet)
        elseif ($this->current_values) {
            $val_arr = explode(',', $this->current_values->{$field_type});
        }
        //Values can be an emty array if none of the above is true
        else {
            $val_arr = [];
        }

        foreach ($values as $value) {
            if (is_array($display)) {
                $display_val = '';
                foreach ($display as $disp) {
                    $display_val .= $value->{$disp} . ' ';
                }
                $display_val = trim($display_val);
            } else {
                $display_val = $value->{$display};
            }


            $input .= '<label><input
                    value="' . $value->{$pk} . '"
                    name="' . $field_type . '[]"
                    type="checkbox"
                    ' . (in_array($value->{$pk}, $val_arr) ? ' checked ' : '') . '> '
                . $display_val .
                '</label><br>';
        }

        return $this->checkbox_wrapper($field_type, $label, $input, $required);
    }

    protected function field_email($field_type, $label, $field_params)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $input = '<input type="email" ' . $required . ' class="form-control" id="' . $field_type . '" name="' . $field_type . '"  placeholder="" value="' . set_value($field_type, (isset($this->current_values->{$field_type}) ? $this->current_values->{$field_type} : '')) . '" autocomplete="off">';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_hidden($field_type, $label, $field_params)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $input = '<input type="hidden" ' . $required . '  id="' . $field_type . '" name="' . $field_type . '"  placeholder="" value="' . (isset($this->current_values->{$field_type}) ? $this->current_values->{$field_type} : $field_params['value']) . '" >';
        return $input;
    }

    protected function field_datetime($field_type, $label, $field_params)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');

        $input = '<div class="input-group date" id="' . $field_type . '" data-target-input="nearest">
        <input 
        type="text"  ' . $required . ' 
        class="form-control datetimepicker-input" 
        data-target="#' . $field_type . '"
        id="datetime-' . $field_type . '" name="' . $field_type . '" 
        placeholder="" value="' . set_value($field_type, (isset($this->current_values->{$field_type}) ? $this->current_values->{$field_type} : '')) . '" />
        <div class="input-group-append" data-target="#' . $field_type . '" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="far fa-calendar"></i></div>
                      </div>
    </div>
    <script type="text/javascript">
            $(function () {
                $("#' . $field_type . '").datetimepicker({
                    format: "YYYY-MM-DD HH:mm"
                });
            });
        </script>';

        //$input = '<input type="datetime-local" '.$required.' class="form-control" id="'.$field_type.'" name="'.$field_type.'"  placeholder="" value="'.set_value($field_type, (isset($this->current_values->{$field_type}) ? $this->current_values->{$field_type} : '')).'" autocomplete="off">';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_date($field_type, $label, $field_params)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');

        $input = '<div class="input-group date" id="' . $field_type . '" data-target-input="nearest">
        <input 
        type="text"  ' . $required . ' 
        class="form-control datetimepicker-input" 
        data-target="#' . $field_type . '"
        id="datetime-' . $field_type . '" name="' . $field_type . '" 
        placeholder="" value="' . set_value($field_type, (isset($this->current_values->{$field_type}) ? $this->current_values->{$field_type} : '')) . '" />
        <div class="input-group-append" data-target="#' . $field_type . '" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="far fa-calendar"></i></div>
                      </div>
    </div>
    <script type="text/javascript">
            $(function () {
                $("#' . $field_type . '").datetimepicker({
                    format: "YYYY-MM-DD"
                });
            });
        </script>';

        //$input = '<input type="datetime-local" '.$required.' class="form-control" id="'.$field_type.'" name="'.$field_type.'"  placeholder="" value="'.set_value($field_type, (isset($this->current_values->{$field_type}) ? $this->current_values->{$field_type} : '')).'" autocomplete="off">';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_textarea($field_type, $label, $field_params)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $input = '<textarea  id="' . $field_type . '"   name="' . $field_type . '"  class="form-control" rows="5" ' . $required . ' placeholder="">' . set_value($field_type, (isset($this->current_values->{$field_type}) ? $this->current_values->{$field_type} : '')) . '</textarea>';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }
    protected function field_editor($field_type, $label, $field_params)
    {
        $rand_number = mt_rand(1545645, 15456546);
        $rid = $field_type . '_' . $rand_number;
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $input = '<textarea  id="' . $rid . '"   name="' . $field_type . '"  class="form-control" rows="5" ' . $required . ' placeholder="">' . set_value($field_type, (isset($this->current_values->{$field_type}) ? $this->current_values->{$field_type} : '')) . '</textarea>';
        $input .= '
    <script>$(document).ready(function() {
      $("#' . $rid . '").summernote({
          height: 150,
        toolbar: [
          [\'style\', [\'style\',\'bold\', \'italic\', \'underline\', \'clear\']],
          [\'font\', [\'strikethrough\']],
          [\'fontsize\', [\'fontsize\',\'fontname\']],
          [\'color\', [\'color\']],
          [\'para\', [\'ul\', \'ol\', \'paragraph\']],
          [\'insert\', [ \'video\']],
          [\'misc\', [ \'codeview\']],

        ],
        fontSizes: [ "14",  "16","18", "20", "22"],

      });
    });</script>
    ';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }
    /////////////////////////
    /////////////////////////
    ///////HELPERS/////////
    /////////////////////////
    /////////////////////////

    protected function items_table($columns = null, $items, $pagination)
    {
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

    public function getAddTitle()
    {
        return $this->form_title_add;
    }

    public function getEditTitle()
    {
        return $this->form_title_update;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getBase()
    {
        return $this->base;
    }
}
