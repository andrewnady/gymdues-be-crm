<?php

namespace websquids\Gymdirectory\Updates;

use Illuminate\Support\Facades\Schema;
use Winter\Storm\Database\Updates\Migration;

/**
 * Create winter_blog_comments table if missing (e.g. server has Winter.Blog < 2.2.2).
 * Our API uses this table via Websquids\Gymdirectory\Models\Comment.
 */
class CreateWinterBlogCommentsTableIfMissing extends Migration
{
    public function up()
    {
        if (Schema::hasTable('winter_blog_comments')) {
            return;
        }

        Schema::create('winter_blog_comments', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('post_id')->unsigned()->index();
            $table->string('name');
            $table->string('email');
            $table->text('comment');
            $table->boolean('is_approved')->default(false);
            $table->timestamps();

            if (Schema::hasTable('winter_blog_posts')) {
                $table->foreign('post_id')
                    ->references('id')
                    ->on('winter_blog_posts')
                    ->onDelete('cascade');
            }
        });
    }

    public function down()
    {
        // Do not drop - table may belong to Winter.Blog
    }
}
