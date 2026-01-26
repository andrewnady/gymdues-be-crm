<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableUpdateWebsquidsGymdirectoryHours2 extends Migration
{
    public function up()
    {
        // Drop the unique index first if it exists
        if (Schema::hasColumn('websquids_gymdirectory_hours', 'gym_id')) {
            try {
                Schema::table('websquids_gymdirectory_hours', function($table)
                {
                    $table->dropUnique('gym_day_unique_index');
                });
            } catch (\Exception $e) {
                // Index might not exist, continue
            }
        }
        
        // Add address_id column if it doesn't exist
        if (!Schema::hasColumn('websquids_gymdirectory_hours', 'address_id')) {
            Schema::table('websquids_gymdirectory_hours', function($table)
            {
                $table->integer('address_id')->nullable();
            });
        }
        
        // Drop gym_id column if it exists
        if (Schema::hasColumn('websquids_gymdirectory_hours', 'gym_id')) {
            Schema::table('websquids_gymdirectory_hours', function($table)
            {
                $table->dropColumn('gym_id');
            });
        }
        
        // Create new unique index on address_id + day if it doesn't exist
        try {
            Schema::table('websquids_gymdirectory_hours', function($table)
            {
                $table->unique(['address_id', 'day'], 'address_day_unique_index');
            });
        } catch (\Exception $e) {
            // Index might already exist, continue
        }
    }

    public function down()
    {
        // Drop the new unique index
        Schema::table('websquids_gymdirectory_hours', function($table)
        {
            $table->dropUnique('address_day_unique_index');
        });
        
        // Add gym_id column back
        Schema::table('websquids_gymdirectory_hours', function($table)
        {
            $table->integer('gym_id')->after('id');
        });
        
        // Drop address_id column
        Schema::table('websquids_gymdirectory_hours', function($table)
        {
            $table->dropColumn('address_id');
        });
        
        // Recreate the original unique index
        Schema::table('websquids_gymdirectory_hours', function($table)
        {
            $table->unique(['gym_id', 'day'], 'gym_day_unique_index');
        });
    }
}

