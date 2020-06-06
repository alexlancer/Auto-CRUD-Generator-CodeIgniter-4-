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
		$columns = ['p_id', 'p_uid', 'p_title', 'p_price', 'tags', 'p_start_date', 'p_end_date', 'p_status',];
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

	//--------------------------------------------------------------------

}
