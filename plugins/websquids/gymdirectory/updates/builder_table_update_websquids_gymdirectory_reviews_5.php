<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryReviews5 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->integer('address_id')->nullable();
        });
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->dropColumn('gym_id');
        });
    }

    public function down()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->integer('gym_id')->after('id');
            $table->dropColumn('address_id');
        });
    }
}

