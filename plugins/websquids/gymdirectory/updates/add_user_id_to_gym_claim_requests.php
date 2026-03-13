<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class AddUserIdToGymClaimRequests extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_gym_claim_requests', function ($table) {
            // References the Winter.User users table – populated when a claim is approved
            $table->integer('user_id')->unsigned()->nullable()->after('gym_id')->index();
        });
    }

    public function down()
    {
        Schema::table('websquids_gymdirectory_gym_claim_requests', function ($table) {
            $table->dropColumn('user_id');
        });
    }
}
