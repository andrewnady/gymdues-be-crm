<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryReviews2 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->dateTime('reviewed_at')->nullable()->after('rate');
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->dropColumn('reviewed_at');
        });
    }
}
