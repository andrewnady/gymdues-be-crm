<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryReviews extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->integer('gym_id')->nullable()->change();
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->integer('gym_id')->nullable(false)->change();
        });
    }
}
