<?php namespace websquids\Gymdirectory\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Flash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\GymClaimRequest;
use websquids\Gymdirectory\Models\GymClaimDispute;
use websquids\Gymdirectory\Classes\GymOwnerService;

class GymClaimDisputes extends Controller
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
        BackendMenu::setContext('websquids.Gymdirectory', 'gymdirectory', 'gymclaimdisputes');
    }

    // =========================================================================
    // Approve action
    // =========================================================================

    public function onApprove()
    {
        $id      = post('record_id');
        $dispute = GymClaimDispute::whereNull('deleted_at')
            ->whereIn('status', [GymClaimDispute::STATUS_PENDING, GymClaimDispute::STATUS_UNDER_REVIEW])
            ->find($id);

        if (!$dispute) {
            Flash::error('Dispute not found or already resolved.');
            return;
        }

        $gym           = Gym::find($dispute->gym_id);
        $originalClaim = GymClaimRequest::whereNull('deleted_at')->find($dispute->existing_claim_id);

        // 1. Revoke the original claim
        if ($originalClaim) {
            $originalClaim->status = GymClaimRequest::STATUS_REJECTED;
            $originalClaim->save();
            $originalClaim->delete();
            $this->dispatchClaimRevokedEmail($originalClaim, $gym);
        }

        // 2. Create new approved claim for the disputer
        $newClaim = GymClaimRequest::create([
            'gym_id'              => $gym->id,
            'full_name'           => $dispute->full_name,
            'job_title'           => $dispute->job_title,
            'business_email'      => $dispute->business_email,
            'phone_number'        => $dispute->phone_number,
            'status'              => GymClaimRequest::STATUS_APPROVED,
            'verification_method' => GymClaimRequest::METHOD_DOCUMENT,
            'ip_address'          => $dispute->ip_address,
            'verified_at'         => now(),
        ]);

        // 3. Mark dispute approved
        $dispute->status      = GymClaimDispute::STATUS_APPROVED;
        $dispute->reviewed_at = now();
        $dispute->save();

        // 4. Provision user account and generate magic login token for the new owner
        $gymOwnerService = new GymOwnerService();
        $magicToken      = $gymOwnerService->provisionAndGenerateMagicToken($newClaim);
        $dashboardUrl    = $gymOwnerService->buildMagicLoginUrl($magicToken);

        $this->dispatchDisputeApprovedEmail($dispute, $gym, $dashboardUrl);

        Flash::success('Dispute approved. Original claim revoked and ownership transferred.');
        return redirect()->back();
    }

    // =========================================================================
    // Reject action
    // =========================================================================

    public function onReject()
    {
        $id      = post('record_id');
        $dispute = GymClaimDispute::whereNull('deleted_at')
            ->whereIn('status', [GymClaimDispute::STATUS_PENDING, GymClaimDispute::STATUS_UNDER_REVIEW])
            ->find($id);

        if (!$dispute) {
            Flash::error('Dispute not found or already resolved.');
            return;
        }

        $dispute->status      = GymClaimDispute::STATUS_REJECTED;
        $dispute->reviewed_at = now();
        $dispute->save();

        $gym = Gym::find($dispute->gym_id);
        $this->dispatchDisputeRejectedEmail($dispute, $gym);

        Flash::success('Dispute rejected.');
        return redirect()->back();
    }

    // =========================================================================
    // Document download
    // =========================================================================

    public function download($id)
    {
        $dispute = GymClaimDispute::whereNull('deleted_at')->find($id);

        if (!$dispute || !$dispute->document_path) {
            Flash::error('Document not found.');
            return redirect()->back();
        }

        $storage = \Illuminate\Support\Facades\Storage::disk('local');

        if (!$storage->exists($dispute->document_path)) {
            Flash::error('Document file is missing from storage.');
            return redirect()->back();
        }

        return $storage->download($dispute->document_path);
    }

    // =========================================================================
    // Email helpers
    // =========================================================================

    private function dispatchDisputeApprovedEmail(GymClaimDispute $dispute, ?Gym $gym, string $dashboardUrl): void
    {
        if (!$gym) return;
        try {
            $fullName = $dispute->full_name;
            $gymName  = $gym->name;
            $toEmail  = $dispute->business_email;

            Mail::send(
                'websquids.gymdirectory::mail.dispute_approved',
                compact('fullName', 'gymName', 'dashboardUrl'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('Dispute Approved – You\'ve Claimed ' . $gymName . ' on GymDues');
                }
            );
        } catch (\Exception $e) {
            Log::error('GymClaimDisputes@dispatchDisputeApprovedEmail: ' . $e->getMessage());
        }
    }

    private function dispatchDisputeRejectedEmail(GymClaimDispute $dispute, ?Gym $gym): void
    {
        if (!$gym) return;
        try {
            $fullName = $dispute->full_name;
            $gymName  = $gym->name;
            $toEmail  = $dispute->business_email;

            Mail::send(
                'websquids.gymdirectory::mail.dispute_rejected',
                compact('fullName', 'gymName'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('Dispute Update for ' . $gymName . ' – GymDues');
                }
            );
        } catch (\Exception $e) {
            Log::error('GymClaimDisputes@dispatchDisputeRejectedEmail: ' . $e->getMessage());
        }
    }

    private function dispatchClaimRevokedEmail(GymClaimRequest $claim, ?Gym $gym): void
    {
        if (!$gym) return;
        try {
            $fullName = $claim->full_name;
            $gymName  = $gym->name;
            $toEmail  = $claim->business_email;

            Mail::send(
                'websquids.gymdirectory::mail.claim_revoked',
                compact('fullName', 'gymName'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('Important: Your Claim for ' . $gymName . ' Has Been Revoked – GymDues');
                }
            );
        } catch (\Exception $e) {
            Log::error('GymClaimDisputes@dispatchClaimRevokedEmail: ' . $e->getMessage());
        }
    }
}
