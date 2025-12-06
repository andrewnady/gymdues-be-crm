<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryGyms7 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_gyms', function($table)
        {
            $table->dropColumn('slug');
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_gyms', function($table)
        {
            $table->string('slug', 255)->nullable();
        });
    }
}
