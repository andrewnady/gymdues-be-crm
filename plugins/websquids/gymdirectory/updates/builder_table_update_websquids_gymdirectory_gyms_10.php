<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryGyms10 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_gyms', function($table)
        {
            $table->tinyInteger('is_popular')->default(0);
        });
    }

    public function down()
    {
        Schema::table('websquids_gymdirectory_gyms', function($table)
        {
            $table->dropColumn('is_popular');
        });
    }
}
