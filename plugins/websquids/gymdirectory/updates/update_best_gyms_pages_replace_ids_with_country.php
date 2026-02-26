<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class UpdateBestGymsPagesReplaceIdsWithCountry extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_best_gyms_pages', function($table)
        {
            $table->dropColumn(['country_id', 'state_id', 'city_id']);
        });
        Schema::table('websquids_gymdirectory_best_gyms_pages', function($table)
        {
            $table->string('country', 100)->nullable()->after('gyms_data');
        });
    }

    public function down()
    {
        Schema::table('websquids_gymdirectory_best_gyms_pages', function($table)
        {
            $table->dropColumn('country');
        });
        Schema::table('websquids_gymdirectory_best_gyms_pages', function($table)
        {
            $table->unsignedInteger('country_id')->nullable();
            $table->unsignedInteger('state_id')->nullable();
            $table->unsignedInteger('city_id')->nullable();
        });
    }
}
