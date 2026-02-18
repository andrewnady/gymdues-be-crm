<?php

namespace Winter\Blog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateCommentsTable extends Migration
{
    public function up()
    {
        Schema::create('winter_blog_comments', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('post_id')->unsigned()->index();
            $table->string('name');
            $table->string('email');
            $table->text('comment');
            $table->boolean('is_approved')->default(false);
            $table->timestamps();

            $table->foreign('post_id')
                ->references('id')
                ->on('winter_blog_posts')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('winter_blog_comments');
    }
}
