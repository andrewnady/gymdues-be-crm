<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryGyms9 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_gyms', function($table)
        {
            $table->string('google_place_url')->nullable();
            $table->string('business_name')->nullable();
            $table->string('website_built_with')->nullable();
            $table->string('website_title')->nullable();
            $table->text('website_desc')->nullable();
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_gyms', function($table)
        {
            $table->dropColumn('google_place_url');
            $table->dropColumn('business_name');
            $table->dropColumn('website_built_with');
            $table->dropColumn('website_title');
            $table->dropColumn('website_desc');
        });
    }
}

