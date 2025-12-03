<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateWebsquidsGymdirectoryPricing extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_pricing', function($table)
        {
            $table->engine = 'InnoDB';
            $table->integer('gym_id');
            $table->string('tier_name');
            $table->decimal('price', 10, 0);
            $table->string('frequency');
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_pricing');
    }
}
