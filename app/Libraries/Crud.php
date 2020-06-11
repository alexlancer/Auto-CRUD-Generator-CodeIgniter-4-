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

    public function callback_days_left($item){
        $days = $this->days($item->p_end_date);
        if($days < 0){
            $out = '<span class="text-success">Completed</span>';
        }else{
            $out = '<span>' . $days . ' days left</span>';
        }

        return $out;
    }

    private function days($date)
    {
        $now = time(); // or your date as well
        $your_date = strtotime($date);
        $datediff = $your_date - $now;

        return round($datediff / (60 * 60 * 24));
    }

    public function callback_featured_image($item){
        $src = $item->p_image ? '/uploads/images/'.$item->p_image : '/admin/assets/img/profile.png';

        return '<img src="'.$src.'" style="width:54px;" class="img-circle">';
    }
}
