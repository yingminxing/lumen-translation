<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Laravel\Lumen\Routing\Controller;
use App\Models\Test as TModel;
use Illuminate\Support\Facades\Event;
use App\Events\ExampleEvent;

class TestController extends Controller
{
    public function test()
    {
        //$results = DB::select('select * from groupbuy where id = 585');
        //$results =app('db')->select("SELECT * FROM groupbuy where id = 585");
        //var_dump($results);
        //$res = DB::raw(DB::select('select * from dc.groupbuy where id = 585'));
        // $res = TModel::find(585);
        //echo "qqq";

        Event::fire(new ExampleEvent('jzm'));
    }
}
