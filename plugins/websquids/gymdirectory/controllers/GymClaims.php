<?php namespace websquids\Gymdirectory\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\GymClaimRequest;
use websquids\Gymdirectory\Classes\GymOwnerService;

class GymClaims extends Controller
{
    public $implement = [
        'Backend\Behaviors\ListController',
        'Backend\Behaviors\FormController',
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('websquids.Gymdirectory', 'gymdirectory', 'gymclaims');
    }

    /**
     * Called by FormController after the record is saved.
     *
     * Triggers user provisioning + approval email when an admin manually sets
     * a claim status to "approved" (e.g. after reviewing an uploaded document),
     * but only the first time (user_id is still null).
     */
    public function formAfterSave($model)
    {
        if (
            $model->status === GymClaimRequest::STATUS_APPROVED &&
            empty($model->user_id)
        ) {
            // Ensure verified_at is stamped if the admin forgot
            if (empty($model->verified_at)) {
                $model->verified_at = now();
                $model->save();
            }

            $gym = Gym::find($model->gym_id);

            if (!$gym) {
                Log::warning("GymClaims@formAfterSave: gym #{$model->gym_id} not found for claim #{$model->id}");
                return;
            }

            $service      = new GymOwnerService();
            $magicToken   = $service->provisionAndGenerateMagicToken($model);
            $dashboardUrl = $service->buildMagicLoginUrl($magicToken);

            $this->dispatchApprovalEmail($model, $gym, $dashboardUrl);
        }
    }

    // =========================================================================
    // Email helpers
    // =========================================================================

    private function dispatchApprovalEmail(GymClaimRequest $claim, Gym $gym, string $dashboardUrl): void
    {
        try {
            $fullName = $claim->full_name;
            $gymName  = $gym->name;
            $toEmail  = $claim->business_email;

            Mail::send(
                'websquids.gymdirectory::mail.claim_approved',
                compact('fullName', 'gymName', 'dashboardUrl'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('You\'ve Successfully Claimed ' . $gymName . ' on GymDues');
                }
            );
            Log::info("GymClaims@formAfterSave: approval email sent to {$toEmail} for gym {$gymName}");
        } catch (\Exception $e) {
            Log::error('GymClaims@dispatchApprovalEmail: ' . $e->getMessage());
        }
    }
}
