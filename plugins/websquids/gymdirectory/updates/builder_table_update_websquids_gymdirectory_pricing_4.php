<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryPricing4 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_pricing', function($table)
        {
            $table->integer('gym_id')->nullable()->change();
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_pricing', function($table)
        {
            $table->integer('gym_id')->nullable(false)->change();
        });
    }
}
