<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryPricing3 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_pricing', function($table)
        {
            $table->text('description')->nullable()->after('frequency');
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_pricing', function($table)
        {
            $table->dropColumn('description');
        });
    }
}
