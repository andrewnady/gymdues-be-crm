<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateWebsquidsGymdirectoryGyms extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_gyms', function($table)
        {
            $table->engine = 'InnoDB';
            $table->string('name');
            $table->text('description');
            $table->decimal('rating', 10, 0)->default(0);
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_gyms');
    }
}
