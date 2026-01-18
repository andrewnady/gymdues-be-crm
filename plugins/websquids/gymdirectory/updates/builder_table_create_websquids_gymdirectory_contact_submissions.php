<?php

namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateWebsquidsGymdirectoryContactSubmissions extends Migration {
  public function up() {
    Schema::create('websquids_gymdirectory_contact_submissions', function ($table) {
      $table->engine = 'InnoDB';
      $table->increments('id')->unsigned();
      $table->string('name');
      $table->string('email');
      $table->string('subject');
      $table->text('message');
      $table->timestamp('read_at')->nullable();
      $table->timestamp('created_at')->nullable();
      $table->timestamp('updated_at')->nullable();
    });
  }

  public function down() {
    Schema::dropIfExists('websquids_gymdirectory_contact_submissions');
  }
}
