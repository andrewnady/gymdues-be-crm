<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryPricing5 extends Migration
{
    public function up()
    {
        Schema::table('websquids_gymdirectory_pricing', function($table)
        {
            $table->string('price')->change();
        });
    }
    
    public function down()
    {
        Schema::table('websquids_gymdirectory_pricing', function($table)
        {
            $table->decimal('price', 10, 0)->change();
        });
    }
}

