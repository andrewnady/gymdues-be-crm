<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryReviews4 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->string('reviewed_at')->nullable()->change();
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->dateTime('reviewed_at')->nullable()->change();
        });
    }
}

