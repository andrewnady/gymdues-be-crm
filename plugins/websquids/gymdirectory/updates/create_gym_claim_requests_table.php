<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateGymClaimRequestsTable extends Migration
{
    public function up()
    {
        Schema::create('websquids_gymdirectory_gym_claim_requests', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->integer('gym_id')->unsigned()->index();
            $table->string('full_name');
            $table->string('job_title');
            $table->string('business_email');
            $table->string('phone_number');
            // Chosen by user on frontend: email_domain | phone_sms | document
            $table->string('verification_method')->nullable();
            // 6-digit OTP for email/phone verification
            $table->string('verification_code', 10)->nullable();
            $table->timestamp('verification_code_expires_at')->nullable();
            // Path to uploaded supporting document (Method 3)
            $table->string('document_path', 500)->nullable();
            // pending | code_sent | document_uploaded | approved | rejected
            $table->string('status')->default('pending');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('websquids_gymdirectory_gym_claim_requests');
    }
}
