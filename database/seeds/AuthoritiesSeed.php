<?php

use Illuminate\Database\Seeder;
use App\Authority;

class AuthoritiesSeed extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permmissions = array(
            array('name'=>'add_user','group'=>'user','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'edit_user','group'=>'user','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'delete_user','group'=>'user','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'view_user','group'=>'user','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'add_orgunit','group'=>'org unit','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'delete_orgunit','group'=>'org unit','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'edit_orgunit','group'=>'org unit','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'view_orgunit','group'=>'org unit','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'add_role','group'=>'role','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'delete_role','group'=>'role','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'edit_role','group'=>'role','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            array('name'=>'view_role','group'=>'role','created_at'=>new \dateTime,'updated_at'=> new \dateTime),
            
        );
        $authObj = new Authority();
        Authority::query()->truncate();
        $authObj->insert($permmissions);
        // $authObj->save();
    }
}
