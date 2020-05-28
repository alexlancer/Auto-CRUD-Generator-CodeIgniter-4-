<?php

namespace App\Controllers;

class Admin extends BaseController
{
	public function index()
	{
		$data['title'] = 'Hello, Admin';
		return view('admin/dashboard', $data);
	}

	//--------------------------------------------------------------------

}
