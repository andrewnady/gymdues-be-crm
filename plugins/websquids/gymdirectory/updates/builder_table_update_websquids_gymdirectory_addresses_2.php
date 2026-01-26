<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryAddresses2 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_addresses', function($table)
        {
            $table->boolean('is_primary')->default(false)->after('gym_id');
        });
    }

    public function down()
    {
        Schema::table('websquids_gymdirectory_addresses', function($table)
        {
            $table->dropColumn('is_primary');
        });
    }
}

