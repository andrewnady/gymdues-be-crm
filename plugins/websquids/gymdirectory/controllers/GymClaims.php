<?php namespace websquids\Gymdirectory\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\GymClaimRequest;
use websquids\Gymdirectory\Classes\GymOwnerService;
use Websquids\Gymdirectory\Jobs\SendClaimApprovalEmailJob;
use Websquids\Gymdirectory\Jobs\SendClaimRejectedEmailJob;

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
        $gym = Gym::find($model->gym_id);

        if ($model->status === GymClaimRequest::STATUS_APPROVED && empty($model->user_id)) {
            // Ensure verified_at is stamped if the admin forgot
            if (empty($model->verified_at)) {
                $model->verified_at = now();
                $model->save();
            }

            if (!$gym) {
                Log::warning("GymClaims@formAfterSave: gym #{$model->gym_id} not found for claim #{$model->id}");
                return;
            }

            $service      = new GymOwnerService();
            $magicToken   = $service->provisionAndGenerateMagicToken($model);
            $dashboardUrl = $service->buildMagicLoginUrl($magicToken);

            $this->dispatchApprovalEmail($model, $gym, $dashboardUrl);
        }

        if ($model->status === GymClaimRequest::STATUS_REJECTED && $gym) {
            SendClaimRejectedEmailJob::dispatch(
                $model->business_email,
                $model->full_name,
                $gym->name
            );
        }
    }

    // =========================================================================
    // Document download
    // =========================================================================

    /**
     * GET /backend/websquids/gymdirectory/gymclaims/download_document/{id}
     * Streams the uploaded claim document to the browser.
     */
    public function download_document($id)
    {
        $claim = GymClaimRequest::findOrFail($id);

        if (empty($claim->document_path)) {
            \Flash::error('No document has been uploaded for this claim.');
            return redirect()->back();
        }

        if (!Storage::disk('local')->exists($claim->document_path)) {
            \Flash::error('Document file not found on disk.');
            return redirect()->back();
        }

        $fullPath  = Storage::disk('local')->path($claim->document_path);
        $filename  = basename($claim->document_path);
        $mimeType  = mime_content_type($fullPath) ?: 'application/octet-stream';

        return response()->download($fullPath, $filename, ['Content-Type' => $mimeType]);
    }

    // =========================================================================
    // Email helpers
    // =========================================================================

    private function dispatchApprovalEmail(GymClaimRequest $claim, Gym $gym, string $dashboardUrl): void
    {
        SendClaimApprovalEmailJob::dispatch(
            $claim->business_email,
            $claim->full_name,
            $gym->name,
            $dashboardUrl
        );
    }
}
