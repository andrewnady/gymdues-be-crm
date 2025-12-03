<?php

namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateUniqueIndexForHours extends Migration {
  public function up() {
    Schema::table('websquids_gymdirectory_hours', function ($table) {
      // This creates a constraint: A gym_id + day combination must be unique
      $table->unique(['gym_id', 'day'], 'gym_day_unique_index');
    });
  }

  public function down() {
    Schema::table('websquids_gymdirectory_hours', function ($table) {
      $table->dropIndex('gym_day_unique_index');
    });
  }
}
