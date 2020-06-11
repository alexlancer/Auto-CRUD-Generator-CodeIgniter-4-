<?php

namespace App\Controllers;

use App\Libraries\Crud;


class Projects extends BaseController
{
	protected $crud;

	function __construct()
	{
		$params = [
			'table' => 'projects',
			'dev' => false,
			'fields' => $this->field_options(),
			'form_title_add' => 'Add Project',
			'form_title_update' => 'Edit Project',
			'form_submit' => 'Add',
			'table_title' => 'Projects',
			'form_submit_update' => 'Update',
			'base' => '',

		];

		$this->crud = new Crud($params, service('request'));
	}

	public function index()
	{
		$page = 1;
		if (isset($_GET['page'])) {
			$page = (int) $_GET['page'];
			$page = max(1, $page);
		}

		$data['title'] = $this->crud->getTableTitle();

		$per_page = 20;
		//'p_start_date', 'p_end_date', 'p_status',
		$columns = ['p_id',
		[
		 'label' => 'Image',
		 'callback' => 'callback_featured_image',
		 'search' => 'p_image',
		 'search_field_type' => 'text'
		],
		 'p_uid',
		 'p_title',
		 'p_price',
		 'tags',
		  [
			'label' => 'Days left',
			'callback' => 'callback_days_left',
			
		  ],
		];
		$where = null; //['u_status =' => 'Active'];
		$order = [
			['p_id', 'ASC']
		];
		$data['table'] = $this->crud->view($page, $per_page, $columns, $where, $order);
		return view('admin/projects/table', $data);
	}

	public function add()
	{

		$data['form'] = $form = $this->crud->form();
		$data['title'] = $this->crud->getAddTitle();

		if (is_array($form) && isset($form['redirect']))
			return redirect()->to($form['redirect']);

		return view('admin/projects/form', $data);
	}

	public function edit($id)
	{
		if (!$this->crud->current_values($id))
			return redirect()->to($this->crud->getBase() . '/' . $this->crud->getTable());

		$data['item_id'] = $id;
		$data['form'] = $form = $this->crud->form();
		$data['title'] = $this->crud->getEditTitle();

		if (is_array($form) && isset($form['redirect']))
			return redirect()->to($form['redirect']);

		return view('admin/projects/form', $data);
	}


	protected function field_options()
	{
		$fields = [];
		$fields['p_id'] = ['label' => 'ID'];
		$fields['p_uid'] = [
			'label' => 'User',
			'required' => true,
			'type' => 'dropdown',
			'relation' => [
				'table'=> 'users',
				'primary_key' => 'u_id',
				'display' => ['u_firstname', 'u_lastname'],
				'order_by' => 'u_firstname',
				'order' => 'ASC'
				]
			];
		$fields['tags'] = [
			'label' => 'Tags',
			'required' => false,
			'type' => 'checkboxes',
			'relation' => [
				'save_table' => 'project_tags',
				'parent_field' => 'pt_project_id',
				'child_field' => 'pt_tag_id',
				'inner_class' => 'col-6 col-sm-3',
				'table' => 'tags',
				'primary_key' => 't_id',
				'display' => ['t_name'],
				'order_by' => 't_name',
				'order' => 'ASC'
			]
		];
		$fields['p_image'] = [
			'label' => 'Featured Image',
			'type' => 'file',
			'path' => './uploads/images',
			'is_image' => true,
			'max_size' => '1024',
			'ext_in' => 'png,jpg,gif',
			'wrapper_start' => '<div class="row"><div class="col-12 col-sm-3 mt-3 mb-3">',
			'wrapper_end' => '</div></div>',
			'show_file_names' => true,
			'placeholder' => '/admin/assets/img/pdf-icon.png',
			'delete_callback' => 'removeFeaturedImage',
			'delete_file' => true,
			'delete_button_class' => 'btn btn-danger btn-xs'
		];
		$fields['project_files'] = [
			'label' => 'Files',
			'type' => 'files',
			'files_relation' => [
				'files_table' => 'project_files',
				'primary_key' => 'pf_id',
				'parent_field' => 'pf_project_id',
				'file_name_field' => 'pf_file_name',
				'file_type_field' => 'pf_file_type',
			],
			'path' => './uploads/images',
			//'is_image' => true,
			'max_size' => '2048',
			//'ext_in' => 'png,jpg,gif',
			'wrapper_start' => '<div class="row">',
			'wrapper_end' => '</div>',
			'wrapper_item_start' => '<div class="col-4 col-sm-2 mt-3 mb-3">',
			'wrapper_item_end' => '</div>',
			'show_file_names' => true,
			'placeholder' => '/admin/assets/img/file-icon.png',
			'delete_callback' => 'deleteFile',
			'delete_file' => true,
			'delete_button_class' => 'btn btn-danger btn-xs'
		];
		$fields['p_description'] = ['label' => 'Description', 'type' => 'editor'];
		$fields['p_start_date'] = ['label' => 'Starts at', 'required' => true, 'class' => 'col-12 col-sm-6'];
		$fields['p_end_date'] = ['label' => 'Ends at', 'required' => true, 'class' => 'col-12 col-sm-6'];
		$fields['p_title'] = ['label' => 'Title', 'required' => true];
		$fields['p_status'] = ['label' => 'Status', 'required' => true, 'class' => 'col-12 col-sm-6'];
		$fields['p_price'] = ['label' => 'Price', 'required' => true, 'class' => 'col-12 col-sm-6'];
		$fields['p_created_at'] = ['type' => 'unset'];
		$fields['p_updated_at'] = ['type' => 'unset'];
		return $fields;
	}


	public function removeFeaturedImage($parent_id)
	{
		$crud = $this->crud;
		$current_values = $crud->current_values($parent_id);
		if (!$current_values)
		return redirect()->to($crud->getBase() . '/' . $crud->getTable());

		$fileColumnName = 'p_image';
		$field = $crud->getFields($fileColumnName);

		$table = $crud->getTable();
		$data = [$fileColumnName => ''];
		$where = [$crud->get_primary_key_field_name() => $parent_id];
		$affected = $crud->updateItem($table, $where, $data);

		if (!$affected)
		$crud->flash('warning', 'File could not be deleted');
		else {

			if ($field['delete_file'] ?? false && $field['delete_file'] === TRUE)
				unlink($field['path'] . '/' .  $current_values->{$fileColumnName});

			$crud->flash('success', 'File was deleted');
		}

		$url = $crud->getBase() . '/' . $crud->getTable() . '/edit/' . $parent_id;
		return redirect()->to($url);
	}

	public function deletefile($parent_id, $file_id)
	{
		$crud = $this->crud;
		$current_values = $crud->current_values($parent_id);
		if (!$current_values)
			return redirect()->to($crud->getBase() . '/' . $crud->getTable());

		$field = $crud->getFields('project_files');

		$table = $field['files_relation']['files_table'];
		$relationOptions = $field['files_relation'];
		$where = [$relationOptions['primary_key'] => $file_id];
		$item = $crud->deleteItem($table, $where);

		if (!$item)
			$crud->flash('warning', 'File could not be deleted');
		else {

			if ($field['delete_file'] ?? false && $field['delete_file'] === TRUE)
				unlink($field['path'] . '/' . $parent_id . '/' . $item->{$relationOptions['file_name_field']});

			$crud->flash('success', 'File was deleted');
		}

		$url = $crud->getBase() . '/' . $crud->getTable() . '/edit/' . $parent_id;
		return redirect()->to($url);
	}

	

	//--------------------------------------------------------------------

}
