<?php

namespace App\Libraries;

class Admin
{

    public function title($params)
    {
        if ($params['title'])
            return view('admin/cmps/title', $params);
    }
}
