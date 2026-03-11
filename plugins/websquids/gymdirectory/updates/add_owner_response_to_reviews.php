<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class AddOwnerResponseToReviews extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_reviews', function ($table) {
            $table->text('owner_response')->nullable();
            $table->timestamp('owner_responded_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('websquids_gymdirectory_reviews', function ($table) {
            $table->dropColumn(['owner_response', 'owner_responded_at']);
        });
    }
}
