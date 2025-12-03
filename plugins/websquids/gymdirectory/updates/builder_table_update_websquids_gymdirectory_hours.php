<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryHours extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_hours', function($table)
        {
            $table->integer('gym_id')->nullable()->after('id');
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_hours', function($table)
        {
            $table->dropColumn('gym_id');
        });
    }
}
