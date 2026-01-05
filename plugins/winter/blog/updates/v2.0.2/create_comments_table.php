<?php

namespace Winter\Blog\Updates;

use Winter\Storm\Database\Updates\Migration;
use Winter\Storm\Support\Facades\Schema;

class CreateCommentsTable extends Migration
{
    public function up()
    {
        Schema::create('winter_blog_comments', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('post_id')->unsigned();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->text('comment');
            $table->boolean('is_approved')->default(false);
            $table->timestamps();

            $table->foreign('post_id')
                ->references('id')
                ->on('winter_blog_posts')
                ->onDelete('cascade');

            $table->index('post_id');
            $table->index('is_approved');
        });
    }

    public function down()
    {
        Schema::dropIfExists('winter_blog_comments');
    }
}

