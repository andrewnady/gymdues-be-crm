<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

/**
 * Two token types live in this table:
 *
 *  magic   – One-time link sent in the approval email. Short-lived (48 h).
 *            Marked via used_at once consumed.
 *
 *  session – Long-lived bearer token returned after the magic link is consumed.
 *            The frontend sends this as "Authorization: Bearer <token>" for
 *            authenticated gym-owner API calls.
 */
class CreateGymOwnerTokensTable extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_gym_owner_tokens', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            // References Winter.User users table
            $table->integer('user_id')->unsigned()->index();
            // SHA-256 hash of the raw token (never store plain tokens)
            $table->string('token', 64)->unique();
            // 'magic' | 'session'
            $table->string('type', 10)->default('magic');
            $table->timestamp('expires_at')->nullable();
            // Set when a magic token is consumed (prevents reuse)
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_gym_owner_tokens');
    }
}
