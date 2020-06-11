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
        $files = [], //when current_values() is executed all fields that have type 'file' or 'files' will be stored here
        $multipart = false,
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
            foreach ($this->fields as $key => $field) {

                //Adding custom fields to schema for relational table
                if (isset($field['relation']) && isset($field['relation']['save_table'])) {
                    $newSchema = [
                        'Field' => $key,
                        'Type' => 'text',
                        'Key' => '',
                        'Default' => '',
                        'Extra' => 'other_table'
                    ];
                    $this->schema[] = (object) $newSchema;
                }

                //Adding custom fields to schema for relational table for files
                if (isset($field['files_relation']) && isset($field['files_relation']['files_table'])) {
                    $newSchema = [
                        'Field' => $key,
                        'Type' => 'text',
                        'Key' => '',
                        'Default' => '',
                        'Extra' => 'file_table'
                    ];
                    $this->schema[] = (object) $newSchema;
                }
            }
        }
        //Base uri
        if (isset($params['base']) && $params['base']) {
            $this->base = $params['base'];
        }

        //Check if form contains file fields
        $this->multipart = $this->formHasFileFields();


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

        $this->current_values = $item = $this->model->getItem($this->table, [$this->id_field => $id]);
        if (!$item) {
            $this->flash('warning', 'The record does not exist');
            return false;
        }

        foreach ($this->fields as $field => $options) {

            if (isset($options['type']) && $options['type'] == 'file') {
                $this->files[$field] = $item->{$field};
            }

            if (isset($options['type']) && $options['type'] == 'files') {

                $fileTable = $options['files_relation']['files_table'];
                $where = [$options['files_relation']['parent_field'] => $id];
                $files = $this->model->getAnyItems($fileTable, $where);
                $this->files[$field] = $files;
            }
        }



        $this->id = $id;
        $this->action = 'edit';
        return $item;
    }

    function view($page_number, $per_page, $columns = null, $where = null, $order = null)
    {

        //$root_url = $this->base . '/' . $this->table;

        $total_rows = $this->model->countTotalRows($this->table, $where, $this->request, $this->schema, $this->fields);
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

            if ($_FILES && isset($_FILES['files']))
                unset($_FILES['files']);

            // echo '<pre>';
            // print_r($_FILES);
            // echo '<pre>';
            // exit;

            $file_fields = [];
            foreach ($_FILES as $file_field_name => $file_field_value) {
                $file_fields[] = $file_field_name;
            }

            //Create rules
            $novalidation = true;
            $this->validator = service('validation');
            //Fields to be unsetted before insert or update
            $unsets = [];
            //Store ['field1', 'field2', '...']  to be hashed with password_hash
            $toHash = [];
            $otherTables = [];
            $otherTableValues = [];

            foreach ($this->fields as $field => $params) {
                if (($this->action == 'add' && !isset($params['only_edit']) && @$params['only_edit'] !== TRUE) ||
                    ($this->action == 'edit' && !isset($params['only_add']) && @$params['only_add'] !== TRUE)
                ) {
                    $theLabel = $this->get_label($this->fields[$field], $field);

                    if (isset($params['type']) && ($params['type'] == 'file' || $params['type'] == 'files')) {
                        $fileRulesArr = [];
                        $multi = $params['type'] == 'file' ? false : true;
                        if (isset($params['required']) && $params['required'] === TRUE) {
                            if ($multi) {
                                $fileFieldName = $field . '.0';
                                $unsets[] = $field;
                            } else
                                $fileFieldName = $field;

                            $fileRulesArr[] = 'uploaded[' . $fileFieldName . ']';
                        }

                        if (isset($params['max_size']))
                            $fileRulesArr[] = 'max_size[' . $field . ',' . $params['max_size'] . ']';

                        if (isset($params['is_image']) && $params['is_image'] === TRUE)
                            $fileRulesArr[] = 'is_image[' . $field . ']';

                        if (isset($params['ext_in']))
                            $fileRulesArr[] = 'ext_in[' . $field . ',' . $params['ext_in'] . ']';

                        $fileRules = implode('|', $fileRulesArr);

                        $this->validator->setRule($field, $theLabel, $fileRules);
                    } elseif ((isset($params['required']) && $params['required'] === TRUE) || (isset($params['type']) && $params['type'] == 'hidden')) {
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

                    //Check if relational values should be saved in different table
                    $otherTable = false;
                    if (isset($params['relation'])) {
                        $relOptions = $params['relation'];
                        $otherTable = $relOptions['save_table'] ?? false;
                    }
                    if ($otherTable) {
                        $otherTables[] = $otherTable;

                        $novalidation = false;
                        $otherTableValues[$otherTable] = [
                            'parent_field' => $relOptions['parent_field'],
                            'child_field' => $relOptions['child_field'],
                            'values' => $post[$field] ?? [],
                            //'current_field_name' => $field
                        ];


                        $unsets[] = $field;
                    }

                    //check relational table save

                    if (isset($params['password_hash']) && $params['password_hash'] === TRUE) {
                        $toHash = [$field];
                    }
                }
            }


            if ($this->validator->withRequest($this->request)->run() || $novalidation) //|| empty($this->fields)
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

                //If file fields exist do the uplaod
                $filesData = [];
                if ($file_fields) {
                    foreach ($file_fields as $file_field) {
                        $fileFieldOptions = $this->fields[$file_field];
                        //Single file (meaning that the name will be saved in the same table)

                        if ($fileFieldOptions['type'] == 'file') {
                            $uploadedFileName = $this->fileHandler($file_field, $this->fields[$file_field]);
                            if ($uploadedFileName)
                                $post[$file_field] = $uploadedFileName;
                        } elseif ($fileFieldOptions['type'] == 'files')
                            $filesData[$file_field] = $fileFieldOptions;
                    }
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

                    //Do not set flash if there is $otherTables (from relational options)
                    if (!$otherTables && !$filesData) {
                        if ($affected == 1)
                            $this->flash('success', 'Successfully Updated');
                        else
                            $this->flash('warning', 'The record was not updated or no changes were made');
                    }
                }


                if ($otherTables) {
                    foreach ($otherTables as $otherTable) {
                        $exisingRelations = [];
                        // Preparing existing relations from another (relational) table
                        if ($this->current_values) {

                            $otherWhere = [$otherTableValues[$otherTable]['parent_field'] => $this->current_values->{$this->get_primary_key_field_name()}];
                            $exisingRelationItems = $this->model->getRelationItems($otherTable, $otherWhere);
                            if ($exisingRelationItems) {
                                foreach ($exisingRelationItems as $exisingRelationItem) {
                                    $exisingRelations[] = $exisingRelationItem->{$otherTableValues[$otherTable]['child_field']};
                                }
                            }
                        }
                        //Preparing submited values
                        $newRelations = $otherTableValues[$otherTable]['values'];
                        $newRelations = (is_array($newRelations) ? $newRelations : []);
                        //Exclude same data
                        $toDelete = array_diff($exisingRelations, $newRelations);
                        $toInsert = array_diff($newRelations, $exisingRelations);

                        if ($toDelete) {
                            $where = [$otherTableValues[$otherTable]['parent_field'] => $this->id];
                            $this->model->deleteItems($otherTable, $where, $otherTableValues[$otherTable]['child_field'], $toDelete);
                        }

                        if ($toInsert) {
                            foreach ($toInsert as $toInsertItem) {
                                $newTempRelationData = [];
                                $newTempRelationData[] = [
                                    $otherTableValues[$otherTable]['parent_field'] => $this->id,
                                    $otherTableValues[$otherTable]['child_field'] => $toInsertItem
                                ];

                                $this->model->batchInsert($otherTable, $newTempRelationData);
                            }
                        }

                        if (!$filesData) {
                            if ($toDelete || $toInsert || $affected)
                                $this->flash('success', 'Successfully Updated');
                            else
                                $this->flash('warning', 'The record was not updated or no changes were made');
                        }
                    }
                    // $otherTableValues = [
                    //     'parent_field' => $relOptions['save_table_parent_id'],
                    //     'child_field' => $relOptions['save_table_child_id'],
                    //     'values' => $post[$field]
                    // ];


                }
                $insertedFilesAffectedRows = false;
                if ($filesData) {
                    foreach ($filesData as $fileDataKey => $fileDataOptions) {
                        $insertedFilesAffectedRows = $this->filesHandler($fileDataKey, $fileDataOptions);
                    }
                }

                if ($insertedFilesAffectedRows || $affected || $toDelete || $toInsert)
                    $this->flash('success', 'Successfully Updated');
                else
                    $this->flash('warning', 'The record was not updated or no changes were made');

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
              </div>';
        if ($this->multipart) {
            $form .= form_open_multipart('/' . $this->base . '/' . $this->table . '/' . ($this->action == 'add' ? 'add' : 'edit/' . $this->id)) . '<div class="card-body">';
        } else {
            $form .= form_open('/' . $this->base . '/' . $this->table . '/' . ($this->action == 'add' ? 'add' : 'edit/' . $this->id)) . '<div class="card-body">';
        }
        $form .= '<input type="hidden" name="form" value="1"><div class="row">';

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
                $rel_order_by = $rel['order_by'];
                $rel_where =  $rel['where'] ?? false;

                $rel_order = $rel['order'];
                $fields[$f->Field]['values'] = $this->model->getRelationItems($rel_table, $rel_where, $rel_order_by, $rel_order);
            }

            $field_values = $fields[$f->Field]['values'] ?? null;

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

            $form .= $this->$field_method($f->Field, $label, $fields[$f->Field] ?? null, $field_values, $class);
            if (!$hidden) {
                $form .=  "$helperText</div></div>";
            }
        }


        $form .= '</div></div><div class="card-footer">
              <button type="submit" class="btn btn-primary">' . ($this->action == 'add' ? $this->form_submit : $this->form_submit_update) . '</button>
              </div>' . form_close() . '</div>';

        if ($this->multipart) {
            $form .= '<script type="text/javascript">
                    $(document).ready(function () {
                    bsCustomFileInput.init();
                    });
                    </script>';
        }

        return $form;
    }

    protected function get_label($field, $default_label = '')
    {

        //When generating error labels, because $field is not the same object from Schema
        if (!isset($field->Type))
            return $field['label'] ??  ucfirst(str_replace('_', ' ', $default_label));

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

        $output = '<label for="' . $field_type . '">' . $label . ':' . ($required ? ' <span class="text-danger">*</span>' : '') . '</label>
                <div class="form-check">' . $input . '</div>';
        if ($this->validator && $this->validator->hasError($field_type)) {
            $output .= '<div class="error text-danger">' . $this->validator->getError($field_type) . '</div>';
        }
        return $output;
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
    $("#' . $field_type . '").select2({theme: "bootstrap4",width:"100%"});
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
    $("#' . $rid . '").select2({theme: "bootstrap4",width:"100%"});
});</script>';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_multiselect($field_type, $label, $field_params, $values)
    {

        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        //randomize_id for select2
        $rand_number = mt_rand(1545645, 15456546);
        $rid = $field_type . '_' . $rand_number;
        $input = '<input type="hidden" name="' . $field_type . '" value=""><select  class="form-control" ' . $required . ' id="' . $rid . '" name="' . $field_type . '[]" multiple="multiple">
                 <option></option>';

        $pk = $field_params['relation']['primary_key'];
        $display = $field_params['relation']['display'];
        //Values can be set from $_POST (if this is a form submission)
        if ($this->request->getPost($field_type)) {
            $val_arr = $this->request->getPost($field_type);
        } //Values can be set from save_table option
        elseif ($field_params['relation']['save_table'] && $this->id) {
            $relItems = $this->model->getRelationItems($field_params['relation']['save_table'], [$field_params['relation']['parent_field'] => $this->id]);
            $val_arr = [];
            if ($relItems) {
                foreach ($relItems as $relItem) {
                    $val_arr[] = $relItem->{$field_params['relation']['child_field']};
                }
            }
        }
        //Values can be set from current row (if this is an editing of the record and no $_POST yet)
        elseif ($this->current_values && $this->current_values->{$field_type} != '') {
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

            $input .= '<option value="'
                . $value->{$pk} . '" '
                . set_select($field_type, $value->{$pk}, (in_array($value->{$pk}, $val_arr) ? TRUE : FALSE))
                . '>' . $display_val . '</option>';
        }
        $input .= '</select><script>$(document).ready(function() {
    $("#' . $rid . '").select2({theme: "bootstrap4",width:"100%"});
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

        //  $input = '<input name="' . $field_type . '" type="checkbox" value="" checked style="display:none;">';
        $input = '<div class="row">';
        $pk = $field_params['relation']['primary_key'];
        $display = $field_params['relation']['display'];
        $inner_class = $field_params['relation']['inner_class'] ?? 'col-12';

        //Values can be set from $_POST (if this is a form submission)
        if ($this->request->getPost($field_type)) {
            $val_arr = $this->request->getPost($field_type);
        } elseif ($field_params['relation']['save_table'] && $this->id) {
            $relItems = $this->model->getRelationItems($field_params['relation']['save_table'], [$field_params['relation']['parent_field'] => $this->id]);
            $val_arr = [];
            if ($relItems) {
                foreach ($relItems as $relItem) {
                    $val_arr[] = $relItem->{$field_params['relation']['child_field']};
                }
            }
        }
        //Values can be set from current row (if this is an editing of the record and no $_POST yet)
        elseif ($this->current_values && $this->current_values->{$field_type} != '') {
            $val_arr = explode(',', $this->current_values->{$field_type});
        }
        //Values can be an emty array if none of the above is true
        else {
            $val_arr = [];
        }

        $i = 0;

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

            $checkboxId = $field_type . '-' . $i;
            $input .= '<label class="form-check-label  ' . $inner_class . '" for="' . $checkboxId . '"> <input
                        value="' . $value->{$pk} . '"
                        type="checkbox" 
                        class="form-check-input" 
                        name="' . $field_type . '[]" '
                . (in_array($value->{$pk}, $val_arr) ? ' checked ' : '') .
                'id="' . $checkboxId . '">';
            $input .=  $display_val . '</label>';

            $i++;
        }
        $input .= '</div>';
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
        fontSizes: [ "14", "16","18", "20", "22"],

      });
    });</script>
    ';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_file($field_type, $label, $field_params)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $input = '<div class="custom-file">
                      <input 
                      type="file"
                      name="' . $field_type . '" 
                      class="custom-file-input" 
                      id="' . $field_type . '">
                      <label class="custom-file-label" for="customFile">Choose file</label>
                    </div>';
        if (isset($field_params['wrapper_start']) && isset($field_params['wrapper_end'])) {
            $fileName = $this->files[$field_type] ?? null;
            $htmlFileName = '';
            $deleteButton = '';

            if ($field_params['show_file_names'] ?? false)
                $htmlFileName .= '<div class="file-name-wrapper text-center">' . $fileName . '</div>';

            if ($field_params['delete_callback'] ?? false) {
                $deleteUrl = $this->getBase() . '/' . $this->getTable() . '/' . $field_params['delete_callback'] . '/' . $this->id;
                $deleteButton .= '<a 
                        onclick="return confirm(\'Are you sure you want to delete this file?\')" 
                        class="' . ($field_params['delete_button_class'] ?? null) . '" 
                        href="' . $deleteUrl . '">Delete</a>';
            }

            if ($fileName) {
                $src = ltrim($field_params['path'], '.') . '/' . $fileName;
                $input .= $field_params['wrapper_start'] . '<a href="' . $src . '" target="_blank">';

                if (($field_params['is_image'] ?? false) && $field_params['is_image'] === TRUE) {
                    $input .= '<img class="img-fluid" src="' . $src . '">';
                } elseif (isset($field_params['placeholder'])) {
                    $input .= '<img class="img-fluid" src="' . $field_params['placeholder'] . '">';
                }

                $input .= $htmlFileName . '</a>' . $deleteButton . $field_params['wrapper_end'];
            }
        }

        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_files($field_type, $label, $field_params)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $relationOptions = $field_params['files_relation'];
        $input = '<div class="custom-file">
                      <input 
                      multiple
                      type="file"
                      name="' . $field_type . '[]" 
                      class="custom-file-input" 
                      id="' . $field_type . '">
                      <label class="custom-file-label" for="customFile">Choose file</label>
                    </div>';
        if (isset($field_params['wrapper_start']) && isset($field_params['wrapper_end'])) {

            if ($files = $this->files[$field_type] ?? null) {
                $input .= $field_params['wrapper_start'];

                foreach ($files as $file) {
                    $fileType = $file->{$relationOptions['file_type_field']};
                    $fileName = $file->{$relationOptions['file_name_field']};
                    $htmlFileName = '';
                    if ($field_params['show_file_names'] ?? false)
                        $htmlFileName .= '<div class="file-name-wrapper text-center">' . $fileName . '</div>';
                    if ($field_params['delete_callback'] ?? false) {
                        $deleteUrl = $this->getBase() . '/' . $this->getTable() . '/' . $field_params['delete_callback'] . '/' . $this->id . '/' . $file->{$relationOptions['primary_key']};
                        $htmlFileName .= '<a 
                        onclick="return confirm(\'Are you sure you want to delete this file?\')" 
                        class="' . ($field_params['delete_button_class'] ?? null) . '" 
                        href="' . $deleteUrl . '">Delete</a>';
                    }


                    $input .= $field_params['wrapper_item_start'];
                    $src = ltrim($field_params['path'], '.') . '/' . $this->id . '/' . $fileName;
                    if (strpos($fileType, 'image') !== false) {
                        $input .=  '<a href="' . $src . '" target="_blank"><img class="img-fluid" src="' . $src . '">' . $htmlFileName . '</a>';
                    } elseif (strpos($fileType, 'video') !== false) {
                        $input .=  '<video class="img-fluid" src="' . $src . '" controls></video><a href="' . $src . '" target="_blank">' . $htmlFileName . '</a>';
                    } else {
                        if (isset($field_params['placeholder'])) {
                            $placeholder = '<img class="img-fluid" src="' . $field_params['placeholder'] . '">';
                        } else {
                            $placeholder = '<i class="fas fa-file"></i>';
                        }
                        $input .=  '<a href="' . $src . '" target="_blank" class="d-block text-center">' . $placeholder . ' ' . $htmlFileName . '</a>';
                    }

                    $input .= $field_params['wrapper_item_end'];
                }
                $input .=  $field_params['wrapper_end'];
            }
        }

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
        $primary_key = $this->get_primary_key_field_name();

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
                    $th_class = ucfirst(str_replace('_', ' ', $column['label']));
                } else {
                    $label = $fields[$column]['label'] ?? ucfirst(str_replace('_', ' ', $column));
                    $th_class = $column;
                }
                $table .= '<th class="th-' . $th_class . '">' . $label . '</th>';
            }
        } else {

            foreach ($this->schema as $item) {
                $label = $fields[$item->Field]['label'] ?? ucfirst(str_replace('_', ' ', $item->Field));
                $table .= '<th class="th-' . $item->Field . '">' . $label . '</th>';
            }
        }

        $table .= '<th class="th-action" width="10%">Actions</th>';
        $table .= '</tr></thead><tbody><tr>' . form_open();


        //Search fields

        if ($columns) {

            foreach ($columns as $column) {
                //check date fields
                if (is_array($column)) {

                    if ($newCol = $column['search'] ?? false) {
                        $field_type = $column['search_field_type'] ?? 'text';
                        $label = $column['label'];
                        $table .= '<td><input type="' . $field_type . '" name="' . $newCol . '" class="form-control pull-right" value="' . set_value($newCol) . '" placeholder="' . $label . '"></td>';
                    } else {
                        $table .= '<td></td>';
                        continue;
                    }
                } else {
                    if (isset($fields[$column]['type']) && strpos($fields[$column]['type'], 'date') !== FALSE) {
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
                    $label = $fields[$column]['label'] ?? ucfirst(str_replace('_', ' ', $column));
                    $table .= '<td><input type="' . $field_type . '" name="' . $column . '" class="form-control pull-right" value="' . set_value($column) . '" placeholder="' . $label . '"></td>';
                }
            }
        } else {
            foreach ($this->schema as $item) {
                if (strpos($item->Type, 'date') !== FALSE)
                    $field_type = 'date';
                else
                    $field_type = 'text';

                $label = $fields[$item->Field]['label'] ?? ucfirst(str_replace('_', ' ', $item->Field));
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

                    if (is_array($column)) {
                        $display_val = $this->{$column['callback']}($item);
                    } elseif ($relation = $fields[$column]['relation'] ?? false) {
                        $relTable = $relation['save_table'] ?? false;
                        $relItems = false;
                        if ($relTable) {
                            $joinTable = $relation['table'];
                            $joinTablePk = $relation['primary_key'];

                            $joinString = $relTable . '.' . $relation['child_field'] . '=' . $joinTable . '.' . $joinTablePk;
                            $relWhere = [$relation['parent_field'] => $item->{$primary_key}];
                            $relItems = $this->model->getRelationItemsJoin($relTable, $relWhere, $joinTable, $joinString);
                        }
                        $display_val = '';

                        if ($relItems) {
                            $tempRelName = [];
                            foreach ($relItems as $relItem) {
                                if (is_array($relation['display'])) {
                                    $tempName = '';
                                    foreach ($relation['display'] as $rel_display) {
                                        $tempName .= $relItem->{$rel_display} . ' ';
                                    }
                                    $tempRelName[] = trim($tempName);

                                    //
                                } else
                                    $tempRelName[] = $relItem->{$relation['display']};

                                $display_val = implode(', ', $tempRelName);
                            }
                        } elseif ($relItems === false) {
                            if (is_array($relation['display'])) {

                                foreach ($relation['display'] as $rel_display) {
                                    $display_val .= $item->{$rel_display} . ' ';
                                }
                                $display_val = trim($display_val);
                                //
                            } else
                                $display_val = $item->{$relation['display']};
                        }
                    } else
                        $display_val = $item->{$column};

                    $table .= '<td>' . $display_val . '</td>';
                }
            } else {
                foreach ($this->schema as $column) {
                    $col_name = $column->Field;
                    $relation = $fields[$col_name]['relation'] ?? false;
                    if ($relation) {
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
            $table .= '<td class="text-center"><a href="' . $this->base . '/' . $this->table . '/edit/' . $item->{$primary_key} . '" class="btn btn-success btn-sm">Edit</a></td>';
            $table .= '</tr>';
        }
        $table .= '</tbody></table></div>';
        $table .= '<div class="card-footer clearfix">';
        if ($this->request->getPost('table_search')) {
            $table .= '<a href="' . $this->base . '/' . $this->table . '" class="btn btn-warning btn-xs"><i class="fa fa-times"></i> Clear filters</a>';
        } else {
            $table .=  $pagination;
        }

        $table .=  '</div></div>';

        return $table;
    }

    function schema()
    {
        return $this->model->schema($this->table);
    }

    public function get_primary_key_field_name()
    {
        return $this->model->get_primary_key_field_name($this->table);
    }


    //Set flashdata session
    public function flash($key, $value)
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

    protected function formHasFileFields()
    {

        foreach ($this->fields as $field) {
            $type = $field['type'] ?? false;
            if (!$type)
                continue;

            if ($type == 'file' || $type == 'files')
                return true;
        }

        return false;
    }

    protected function fileHandler($fileFieldName, $fieldOptions)
    {
        $file = $this->request->getFile($fileFieldName);
        if ($file->isValid() && !$file->hasMoved()) {
            $file->move($fieldOptions['path']);
            return  $file->getName();
        }

        return false;
    }

    protected function filesHandler($fileFieldName, $fileFieldOptions)
    {

        $newFilesData = [];
        $fileRelationOptions = $fileFieldOptions['files_relation'];
        if ($files = $this->request->getFiles()) {
            foreach ($files[$fileFieldName] as $file) {
                if ($file->isValid() && !$file->hasMoved()) {
                    $file->move(rtrim($fileFieldOptions['path'], '/') . '/' . $this->id);
                    $newFilesData[] = [
                        $fileRelationOptions['parent_field'] => $this->id,
                        $fileRelationOptions['file_name_field'] => $file->getName(),
                        $fileRelationOptions['file_type_field'] => $file->getClientMimeType(),
                    ];
                } else
                    return false;
            }
        }

        if ($newFilesData)
            return $this->model->batchInsert($fileRelationOptions['files_table'], $newFilesData);

        return false;
    }

    /** Get all the file fields from current_values 
     *  Do not use to get posted values $_FILES
     * 
     */
    public function getFiles($field = false)
    {
        if (!$field)
            return $this->files;

        if (isset($this->files[$field]))
            return $this->files[$field];

        return false;
    }

    public function deleteItem($table, $where)
    {
        $item = $this->model->getItem($table, $where);

        if ($item)
            $this->model->deleteItems($table, $where);

        return $item;
    }

    public function updateItem($table, $where, $data)
    {
        $affected = $this->model->updateItem($table, $where, $data);
        return $affected;
    }

    public function getItem($table, $where)
    {
        return $this->model->getItem($table, $where);
    }

    public function getFields($field = false)
    {
        if (!$field)
            return $this->fields;

        if ($field = $this->fields[$field] ?? false)
            return $field;

        return false;
    }
}
