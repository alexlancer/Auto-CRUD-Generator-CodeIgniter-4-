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

		$per_page = 10;
		$columns = [];
		$where = null;//['u_status =' => 'Active'];
		$order = [
			['p_id', 'ASC']
		];
		$data['table'] = $this->crud->view($page, $per_page, $columns, $where, $order);
		return view('admin/projects/table', $data);
	}

	public function add(){
		$data['form'] = $form = $this->crud->form();
		$data['title'] = $this->crud->getAddTitle();

		if(is_array($form) && isset($form['redirect']))
			return redirect()->to($form['redirect']);

		return view('admin/projects/form', $data);
	}

	public function edit($id)
	{
		if(!$this->crud->current_values($id))
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
		$fields['p_description'] = ['label' => 'Description', 'type' => 'editor'];
		// $field['u_id'] = ['label' => 'ID'];
		// $fields['u_firstname'] = ['label' => 'First Name', 'required' => true, 'helper' => 'Type your First name', 'class' => 'col-12 col-sm-6'];
		// $fields['u_lastname'] = ['label' =>'Last Name', 'required' => true, 'helper' => 'Type your Last name', 'class' => 'col-12 col-sm-6'];
		// $fields['u_email'] = ['label' => 'Email','required' => true, 'unique' => [true, 'u_email']];
		// $fields['u_status'] = ['label' => 'Status','required' => true,];
		// $fields['u_created_at'] = ['label' => 'Created at', 'only_edit' => true];
		// $fields['u_password'] = ['label' => 'Password',
		//  'required' => true, 
		//  'only_add' => true,
		//  'type' => 'password',
		// 	'class' => 'col-12 col-sm-6',
		//  'confirm' => true, 
		//  'password_hash' => true];

		return $fields;
	}

	//--------------------------------------------------------------------

}
