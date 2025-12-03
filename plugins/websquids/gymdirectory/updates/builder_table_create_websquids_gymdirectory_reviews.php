<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateWebsquidsGymdirectoryReviews extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_reviews', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->integer('gym_id');
            $table->string('reviewer');
            $table->text('text');
            $table->integer('rate');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_reviews');
    }
}
