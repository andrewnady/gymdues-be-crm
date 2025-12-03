<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryGyms2 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_gyms', function($table)
        {
            $table->increments('id')->unsigned()->before('name');
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_gyms', function($table)
        {
            $table->dropColumn('id');
        });
    }
}
