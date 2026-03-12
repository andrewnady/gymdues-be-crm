<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

/**
 * Creates the gym_team_members table to support the "Invite Team Members"
 * feature. A gym owner can invite any email address to co-manage their gym
 * listing without that person going through the claim verification flow.
 *
 * Status lifecycle:
 *   pending  → invitation email sent, not yet accepted
 *   accepted → invited user clicked the magic link and now has dashboard access
 *   revoked  → owner removed this member's access
 */
class CreateGymTeamMembersTable extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_gym_team_members', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            // The gym this member can manage
            $table->integer('gym_id')->unsigned()->index();
            // The owner who sent the invitation
            $table->integer('invited_by_user_id')->unsigned()->index();
            // Populated when the invited user accepts the invitation
            $table->integer('user_id')->unsigned()->nullable()->index();
            // The email address that was invited
            $table->string('email', 255)->index();
            // Optional display name provided by the owner at invite time
            $table->string('name', 255)->nullable();
            // Optional role label (e.g. manager, staff) — informational only
            $table->string('role', 50)->default('manager');
            // pending | accepted | revoked
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_gym_team_members');
    }
}
