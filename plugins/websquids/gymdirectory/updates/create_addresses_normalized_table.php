<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateAddressesNormalizedTable extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_addresses_normalized', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('gym_id');
            $table->boolean('is_primary')->default(false);
            $table->string('google_id')->nullable();
            $table->string('category')->nullable();
            $table->string('sub_category')->nullable();
            $table->text('full_address')->nullable();
            $table->string('borough')->nullable();
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('timezone')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('google_review_url')->nullable();
            $table->integer('total_reviews')->nullable();
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->json('reviews_per_score')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('gym_id')
                  ->references('id')
                  ->on('websquids_gymdirectory_gyms_normalized')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_addresses_normalized');
    }
}
