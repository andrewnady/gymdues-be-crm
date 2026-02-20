<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableAddApprovalToReviews extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->string('email')->nullable();
            $table->string('status')->default('approved');
            $table->string('source')->default('google');
        });
    }

    public function down()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->dropColumn(['email', 'status', 'source']);
        });
    }
}
