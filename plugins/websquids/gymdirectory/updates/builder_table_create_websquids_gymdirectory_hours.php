<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateWebsquidsGymdirectoryHours extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_hours', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->string('day');
            $table->time('from');
            $table->time('to');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_hours');
    }
}
