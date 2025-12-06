<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryGyms3 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_gyms', function($table)
        {
            $table->string('city')->nullable()->default('0')->after('rating');
            $table->string('state')->nullable()->after('rating');
            $table->dropColumn('rating');
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_gyms', function($table)
        {
            $table->dropColumn('city');
            $table->dropColumn('state');
            $table->decimal('rating', 10, 0)->default(0);
        });
    }
}
