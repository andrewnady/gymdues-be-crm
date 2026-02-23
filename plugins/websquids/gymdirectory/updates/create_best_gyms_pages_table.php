<?php namespace websquids\Gymdirectory\Updates;

use Illuminate\Support\Facades\Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateBestGymsPagesTable extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_best_gyms_pages', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->string('featured_image', 500)->nullable();
            $table->longText('intro_section')->nullable();
            $table->longText('faq_section')->nullable();
            $table->longText('gyms_data');
            $table->string('state', 20)->nullable()->index();
            $table->string('city', 255)->nullable()->index();
            $table->unsignedInteger('country_id')->nullable();
            $table->unsignedInteger('state_id')->nullable();
            $table->unsignedInteger('city_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_best_gyms_pages');
    }
}
