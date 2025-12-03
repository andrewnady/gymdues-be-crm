<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryPricing2 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_pricing', function($table)
        {
            $table->increments('id')->unsigned();
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_pricing', function($table)
        {
            $table->dropColumn('id');
        });
    }
}
