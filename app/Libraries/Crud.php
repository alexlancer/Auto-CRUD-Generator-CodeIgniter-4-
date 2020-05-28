<?php

namespace App\Libraries;

use App\Libraries\Crud_core;
use CodeIgniter\HTTP\RequestInterface;

class Crud extends Crud_core
{
    function __construct($params, RequestInterface $request)
    {
        parent::__construct($params, $request);
    }

    function form()
    {
        return $this->parent_form();
    }
}
