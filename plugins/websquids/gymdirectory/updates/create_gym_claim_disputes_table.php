<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateGymClaimDisputesTable extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_gym_claim_disputes', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->integer('gym_id')->unsigned()->index();
            // The approved claim being disputed
            $table->integer('existing_claim_id')->unsigned()->index('gym_disputes_claim_id_index');
            $table->string('full_name');
            $table->string('job_title');
            $table->string('business_email');
            $table->string('phone_number');
            // Path to the uploaded ownership document
            $table->string('document_path', 500)->nullable();
            // pending | under_review | approved | rejected
            $table->string('status')->default('pending');
            $table->text('admin_notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_gym_claim_disputes');
    }
}
