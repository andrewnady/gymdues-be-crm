<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateGymsNormalizedTable extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_gyms_normalized', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('slug')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('trending')->default(false);
            $table->string('google_place_url')->nullable();
            $table->string('business_name')->nullable();
            $table->string('website_built_with')->nullable();
            $table->string('website_title')->nullable();
            $table->text('website_desc')->nullable();
            $table->tinyInteger('is_popular')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_gyms_normalized');
    }
}
