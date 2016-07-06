<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Laravel\Lumen\Routing\Controller;
use App\Models\Test as TModel;
use Illuminate\Support\Facades\Event;
use App\Events\ExampleEvent;
use medoo;

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

        //Event::fire(new ExampleEvent('jzm'));

        $database = new medoo([
            'database_type' => 'mysql',
            'database_name' => 'dc',
            'server' => '121.41.13.126',
            'username' => 'aidaojia',
            'password' => 'Aidaojia123',
            'charset' => 'utf8'
        ]);

        $database->insert('account', [
            'user_name' => 'foo',
            'email' => 'foo@bar.com',
            'age' => 25,
            'lang' => ['en', 'fr', 'jp', 'cn']
        ]);
    }
}
