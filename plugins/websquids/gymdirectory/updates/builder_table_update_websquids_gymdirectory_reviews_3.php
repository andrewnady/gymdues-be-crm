<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryReviews3 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->string('google_review_id')->nullable();
            $table->string('reviewer_name')->nullable();
            $table->boolean('is_local_guide')->default(false);
            $table->integer('reviews_amount')->nullable();
            $table->integer('photos_amount')->nullable();
            $table->string('reviewer_link')->nullable();
            $table->integer('rating')->nullable();
            $table->dateTime('date')->nullable();
            $table->json('photos')->nullable();
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_reviews', function($table)
        {
            $table->dropColumn('google_review_id');
            $table->dropColumn('reviewer_name');
            $table->dropColumn('is_local_guide');
            $table->dropColumn('reviews_amount');
            $table->dropColumn('photos_amount');
            $table->dropColumn('reviewer_link');
            $table->dropColumn('rating');
            $table->dropColumn('date');
            $table->dropColumn('photos');
        });
    }
}

