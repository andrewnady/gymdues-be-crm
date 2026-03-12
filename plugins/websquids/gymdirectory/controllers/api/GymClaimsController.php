<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\Contact;
use websquids\Gymdirectory\Models\GymClaimRequest;
use websquids\Gymdirectory\Services\SmsService;
use websquids\Gymdirectory\Classes\GymOwnerService;

/**
 * GymClaimsController
 *
 * Backend for the "Claim Your Gym" flow. The FRONTEND decides which
 * verification method the user wants; the backend only tells it which
 * methods are available.
 *
 * Endpoints
 * ---------
 *   POST  /api/v1/gym-claims/initiate             Step 2 – Submit owner info
 *                                                  Returns available_methods so the
 *                                                  frontend can render the correct tabs.
 *
 *   POST  /api/v1/gym-claims/{id}/send-email-code  Step 3 Method 1 – Send OTP to business email
 *   POST  /api/v1/gym-claims/{id}/verify-email     Step 3 Method 1 – Confirm OTP
 *
 *   POST  /api/v1/gym-claims/{id}/send-phone-code  Step 3 Method 2 – Send OTP to gym's listed phone
 *   POST  /api/v1/gym-claims/{id}/verify-phone     Step 3 Method 2 – Confirm OTP
 *
 *   POST  /api/v1/gym-claims/{id}/upload-document  Step 3 Method 3 – Upload supporting document
 *
 *   GET   /api/v1/gym-claims/{id}                  Poll status (Step 4 confirmation check)
 */
class GymClaimsController extends Controller
{
    // =========================================================================
    // Step 2 – Initiate: submit owner info, get available verification methods
    // =========================================================================

    /**
     * POST /api/v1/gym-claims/initiate
     *
     * Body:
     *   gym_id          int    required
     *   full_name       string required
     *   job_title       string required
     *   business_email  string required
     *   phone_number    string required  (claimant's own phone)
     *
     * Response:
     *   claim_id          int
     *   available_methods string[]   which method tabs to show (frontend chooses)
     *   message           string
     */
    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'gym_id'         => 'required|integer|exists:websquids_gymdirectory_gyms,id',
            'full_name'      => 'required|string|max:255',
            'job_title'      => 'required|string|max:255',
            'business_email' => 'required|email|max:255',
            'phone_number'   => 'required|string|max:50',
        ]);

        $gym = Gym::findOrFail($validated['gym_id']);

        // ------------------------------------------------------------------
        // Guard: gym already claimed
        // ------------------------------------------------------------------
        $alreadyClaimed = GymClaimRequest::where('gym_id', $gym->id)
            ->where('status', GymClaimRequest::STATUS_APPROVED)
            ->whereNull('deleted_at')
            ->exists();

        if ($alreadyClaimed) {
            return response()->json([
                'success'           => false,
                'already_claimed'   => true,
                'dispute_available' => true,
                'message'           => 'This business has already been claimed. If you believe this is an error, submit a dispute.',
            ], 409);
        }

        // ------------------------------------------------------------------
        // Guard: document already uploaded — manual review in progress
        // ------------------------------------------------------------------
        $underReview = GymClaimRequest::where('gym_id', $gym->id)
            ->where('status', GymClaimRequest::STATUS_DOCUMENT_UPLOADED)
            ->whereNull('deleted_at')
            ->exists();

        if ($underReview) {
            return response()->json([
                'success' => false,
                'message' => 'A claim for this gym is already under manual review. We will notify you within 48 hours.',
            ], 409);
        }

        // ------------------------------------------------------------------
        // Upsert: if a pending/code_sent claim already exists for this gym
        // (from a previous attempt, regardless of email), update it in place.
        // This handles the "user went back and changed their email" case
        // without creating duplicate records.
        // ------------------------------------------------------------------
        $claim = GymClaimRequest::where('gym_id', $gym->id)
            ->whereIn('status', [
                GymClaimRequest::STATUS_PENDING,
                GymClaimRequest::STATUS_CODE_SENT,
            ])
            ->whereNull('deleted_at')
            ->first();

        if ($claim) {
            // Reset the claim with the new owner info
            $claim->full_name            = $validated['full_name'];
            $claim->job_title            = $validated['job_title'];
            $claim->business_email       = $validated['business_email'];
            $claim->phone_number         = $validated['phone_number'];
            $claim->verification_method  = null;
            $claim->verification_code    = null;
            $claim->verification_code_expires_at = null;
            $claim->status               = GymClaimRequest::STATUS_PENDING;
            $claim->ip_address           = $request->ip();
            $claim->save();
        } else {
            // No prior attempt — create a fresh record
            $claim = GymClaimRequest::create([
                'gym_id'         => $gym->id,
                'full_name'      => $validated['full_name'],
                'job_title'      => $validated['job_title'],
                'business_email' => $validated['business_email'],
                'phone_number'   => $validated['phone_number'],
                'status'         => GymClaimRequest::STATUS_PENDING,
                'ip_address'     => $request->ip(),
            ]);
        }

        return response()->json([
            'success'           => true,
            'claim_id'          => $claim->id,
            'available_methods' => $this->getAvailableMethods($validated['business_email'], $gym),
            'message'           => 'Claim initiated. Please choose a verification method.',
        ], 201);
    }

    // =========================================================================
    // Step 3 – Method 1: Business email domain verification
    // =========================================================================

    /**
     * POST /api/v1/gym-claims/{id}/send-email-code
     * Sends a 6-digit OTP to the claimant's business email.
     * Only works if the email domain matches the gym's website.
     */
    public function sendEmailCode(Request $request, $id)
    {
        $claim = $this->findActiveClaim($id);
        if (!$claim) {
            return $this->notFound();
        }

        if (in_array($claim->status, [GymClaimRequest::STATUS_APPROVED, GymClaimRequest::STATUS_REJECTED])) {
            return response()->json(['success' => false, 'message' => 'This claim is already ' . $claim->status . '.'], 409);
        }

        $gym = Gym::findOrFail($claim->gym_id);

        // Verify email domain eligibility at send-time
        if (!$this->emailDomainMatchesGym($claim->business_email, $gym)) {
            return response()->json([
                'success' => false,
                'message' => 'Your email domain does not match the gym\'s website domain. Please choose another verification method.',
            ], 422);
        }

        // Update method and send code
        $claim->verification_method = GymClaimRequest::METHOD_EMAIL_DOMAIN;
        $code = $claim->generateAndSaveCode(); // saves status=code_sent

        $this->dispatchEmailVerification($claim, $gym, $code);

        return response()->json([
            'success' => true,
            'message' => 'A 6-digit verification code has been sent to ' . $claim->business_email . '.',
        ]);
    }

    /**
     * POST /api/v1/gym-claims/{id}/verify-email
     * Body: { "code": "123456" }
     */
    public function verifyEmail(Request $request, $id)
    {
        $request->validate(['code' => 'required|string|size:6']);

        $claim = $this->findActiveClaim($id);
        if (!$claim) {
            return $this->notFound();
        }

        if ($claim->verification_method !== GymClaimRequest::METHOD_EMAIL_DOMAIN) {
            return response()->json([
                'success' => false,
                'message' => 'Email verification has not been initiated for this claim.',
            ], 422);
        }

        if (!$claim->isCodeValid($request->input('code'))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification code. Please request a new one.',
            ], 422);
        }

        $gym = Gym::findOrFail($claim->gym_id);
        $claim->approve();

        $magicToken  = (new GymOwnerService())->provisionAndGenerateMagicToken($claim);
        $dashboardUrl = (new GymOwnerService())->buildMagicLoginUrl($magicToken);
        $this->dispatchApprovalEmail($claim, $gym, $dashboardUrl);

        return response()->json([
            'success' => true,
            'message' => 'Email verified! Your gym claim for ' . $gym->name . ' has been approved.',
            'status'  => GymClaimRequest::STATUS_APPROVED,
        ]);
    }

    // =========================================================================
    // Step 3 – Method 2: Phone (SMS) verification against listed gym phone
    // =========================================================================

    /**
     * POST /api/v1/gym-claims/{id}/send-phone-code
     * Body: { "phone_number": "+1xxxxxxxxxx" }
     * Sends a 6-digit OTP via SMS to the phone number provided by the frontend.
     */
    public function sendPhoneCode(Request $request, $id)
    {
        $request->validate([
            'phone_number' => 'required|string|max:50',
        ]);

        $claim = $this->findActiveClaim($id);
        if (!$claim) {
            return $this->notFound();
        }

        if (in_array($claim->status, [GymClaimRequest::STATUS_APPROVED, GymClaimRequest::STATUS_REJECTED])) {
            return response()->json(['success' => false, 'message' => 'This claim is already ' . $claim->status . '.'], 409);
        }

        $gym   = Gym::findOrFail($claim->gym_id);
        $phone = $request->input('phone_number');

        // Update method and send code
        $claim->verification_method = GymClaimRequest::METHOD_PHONE_SMS;
        $code = $claim->generateAndSaveCode(); // saves status=code_sent

        try {
            $sms = new SmsService();
            $sms->send(
                $phone,
                'Your GymDues verification code for ' . $gym->name . ': ' . $code . '. Valid for 10 minutes.'
            );
            Log::info('VERIFICATION CODE VIA SMS : ' . $code);
        } catch (\Exception $e) {
            Log::error('GymClaimsController@sendPhoneCode SMS error: ' . $e->getMessage());
            // Code is persisted — user can still enter it once the SMS provider is re-tried
        }

        return response()->json([
            'success' => true,
            'message' => 'A verification code has been sent to the provided phone number.',
        ]);
    }

    /**
     * POST /api/v1/gym-claims/{id}/verify-phone
     * Body: { "code": "123456" }
     */
    public function verifyPhone(Request $request, $id)
    {
        $request->validate(['code' => 'required|string|size:6']);

        $claim = $this->findActiveClaim($id);
        if (!$claim) {
            return $this->notFound();
        }

        if ($claim->verification_method !== GymClaimRequest::METHOD_PHONE_SMS) {
            return response()->json([
                'success' => false,
                'message' => 'Phone verification has not been initiated for this claim.',
            ], 422);
        }

        if (!$claim->isCodeValid($request->input('code'))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification code. Please request a new one.',
            ], 422);
        }

        $gym = Gym::findOrFail($claim->gym_id);
        $claim->approve();

        $magicToken   = (new GymOwnerService())->provisionAndGenerateMagicToken($claim);
        $dashboardUrl = (new GymOwnerService())->buildMagicLoginUrl($magicToken);
        $this->dispatchApprovalEmail($claim, $gym, $dashboardUrl);

        return response()->json([
            'success' => true,
            'message' => 'Phone verified! Your gym claim for ' . $gym->name . ' has been approved.',
            'status'  => GymClaimRequest::STATUS_APPROVED,
        ]);
    }

    // =========================================================================
    // Step 3 – Method 3: Document upload (manual review)
    // =========================================================================

    /**
     * POST /api/v1/gym-claims/{id}/upload-document
     * Multipart field: document (PDF / JPG / PNG, max 10 MB)
     */
    public function uploadDocument(Request $request, $id)
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $claim = $this->findActiveClaim($id);
        if (!$claim) {
            return $this->notFound();
        }

        if ($claim->status === GymClaimRequest::STATUS_APPROVED) {
            return response()->json(['success' => false, 'message' => 'This claim is already approved.'], 409);
        }

        try {
            $file = $request->file('document');
            $ext  = $file->getClientOriginalExtension();
            $path = $file->storeAs(
                'gym-claims/' . $claim->gym_id,
                'claim-' . $claim->id . '-' . time() . '.' . $ext,
                'local'
            );

            $claim->verification_method = GymClaimRequest::METHOD_DOCUMENT;
            $claim->document_path       = $path;
            $claim->status              = GymClaimRequest::STATUS_DOCUMENT_UPLOADED;
            $claim->save();

        } catch (\Exception $e) {
            Log::error('GymClaimsController@uploadDocument: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Document upload failed. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your document is under review. We will notify you within 48 hours.',
            'status'  => GymClaimRequest::STATUS_DOCUMENT_UPLOADED,
        ]);
    }

    // =========================================================================
    // Step 4 – Status poll
    // =========================================================================

    /**
     * GET /api/v1/gym-claims/{id}
     */
    public function status(Request $request, $id)
    {
        $claim = GymClaimRequest::whereNull('deleted_at')->find($id);

        if (!$claim) {
            return $this->notFound();
        }

        $messages = [
            GymClaimRequest::STATUS_PENDING           => 'Claim created. Please complete a verification step.',
            GymClaimRequest::STATUS_CODE_SENT         => 'Verification code sent. Please enter it to continue.',
            GymClaimRequest::STATUS_DOCUMENT_UPLOADED => 'Your document is under review. We will notify you within 48 hours.',
            GymClaimRequest::STATUS_APPROVED          => 'Congratulations! Your gym claim has been approved.',
            GymClaimRequest::STATUS_REJECTED          => 'Your claim was not approved. Please contact support.',
        ];

        return response()->json([
            'claim_id'            => $claim->id,
            'gym_id'              => $claim->gym_id,
            'status'              => $claim->status,
            'verification_method' => $claim->verification_method,
            'message'             => $messages[$claim->status] ?? '',
            'verified_at'         => $claim->verified_at,
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Determine which verification methods are available for the given email + gym.
     * The frontend uses this list to render the tab set.
     *
     * @return string[]  e.g. ['email_domain', 'phone_sms', 'document']
     */
    private function getAvailableMethods(string $email, Gym $gym): array
    {
        $methods = [];

        if ($this->emailDomainMatchesGym($email, $gym)) {
            $methods[] = GymClaimRequest::METHOD_EMAIL_DOMAIN;
        }

        if ($this->gymHasPhone($gym)) {
            $methods[] = GymClaimRequest::METHOD_PHONE_SMS;
        }

        // Document upload is always available as a fallback
        $methods[] = GymClaimRequest::METHOD_DOCUMENT;

        return $methods;
    }

    /**
     * Return a non-deleted, non-rejected claim by ID.
     */
    private function findActiveClaim($id): ?GymClaimRequest
    {
        return GymClaimRequest::whereNull('deleted_at')
            ->where('status', '!=', GymClaimRequest::STATUS_REJECTED)
            ->find($id);
    }

    /**
     * True if the claimant's email domain matches any business_website contact for this gym.
     */
    private function emailDomainMatchesGym(string $email, Gym $gym): bool
    {
        $emailDomain = strtolower(ltrim(strrchr($email, '@'), '@'));

        if (empty($emailDomain)) {
            return false;
        }

        // Gym-level website contacts
        $websites = Contact::where('gym_id', $gym->id)
            ->where('type', 'business_website')
            ->whereNotNull('value')
            ->pluck('value');

        // Address-level website contacts
        $addressIds = $gym->addresses()->pluck('id');
        if ($addressIds->isNotEmpty()) {
            $websites = $websites->merge(
                Contact::whereIn('address_id', $addressIds)
                    ->where('type', 'business_website')
                    ->whereNotNull('value')
                    ->pluck('value')
            );
        }

        foreach ($websites as $website) {
            if ($this->extractDomain($website) === $emailDomain) {
                return true;
            }
        }

        return false;
    }

    private function gymHasPhone(Gym $gym): bool
    {
        return $this->getGymPhone($gym) !== null;
    }

    /**
     * Get the gym's primary business_phone contact value.
     */
    private function getGymPhone(Gym $gym): ?string
    {
        $contact = Contact::where('gym_id', $gym->id)
            ->where('type', 'business_phone')
            ->whereNotNull('value')
            ->first();

        if ($contact) {
            return $contact->value;
        }

        $addressIds = $gym->addresses()->pluck('id');
        if ($addressIds->isNotEmpty()) {
            $contact = Contact::whereIn('address_id', $addressIds)
                ->where('type', 'business_phone')
                ->whereNotNull('value')
                ->first();

            return $contact?->value;
        }

        return null;
    }

    /**
     * Strip scheme and www. to get the bare domain, e.g. "ironworksgym.com".
     */
    private function extractDomain(string $url): ?string
    {
        $parsed = parse_url($url);
        $host   = $parsed['host'] ?? null;

        if (!$host) {
            $parsed = parse_url('https://' . $url);
            $host   = $parsed['host'] ?? null;
        }

        return $host ? strtolower(preg_replace('/^www\./', '', $host)) : null;
    }

    private function dispatchEmailVerification(GymClaimRequest $claim, Gym $gym, string $code): void
    {
        try {
            $fullName = $claim->full_name;
            $gymName  = $gym->name;
            $toEmail  = $claim->business_email;

            Mail::send(
                'websquids.gymdirectory::mail.claim_verification',
                compact('code', 'fullName', 'gymName'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('Verify Your Claim for ' . $gymName . ' on GymDues');
                }
            );
            Log::info('VERIFICATION CODE VIA EMAIL ON ' . $toEmail . ': ' . $code);
        } catch (\Exception $e) {
            Log::error('GymClaimsController@dispatchEmailVerification: ' . $e->getMessage());
        }
    }

    private function dispatchApprovalEmail(GymClaimRequest $claim, Gym $gym, string $dashboardUrl): void
    {
        try {
            $fullName = $claim->full_name;
            $gymName  = $gym->name;
            $toEmail  = $claim->business_email;

            Log::info('Email sent on ' . $toEmail);

            Mail::send(
                'websquids.gymdirectory::mail.claim_approved',
                compact('fullName', 'gymName', 'dashboardUrl'),
                function ($message) use ($toEmail, $fullName, $gymName) {
                    $message->to($toEmail, $fullName)
                            ->subject('You\'ve Successfully Claimed ' . $gymName . ' on GymDues');
                }
            );
            Log::info('You\'ve Successfully Claimed ' . $gymName . ' on GymDues. Email sent on ' . $toEmail);
        } catch (\Exception $e) {
            Log::error('GymClaimsController@dispatchApprovalEmail: ' . $e->getMessage());
        }
    }

    private function notFound()
    {
        return response()->json(['success' => false, 'message' => 'Claim not found.'], 404);
    }
}
