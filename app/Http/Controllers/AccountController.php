<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Models\PrivacyRequest;
use App\Services\ConsentService;
use App\Services\PrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(private ConsentService $consents, private PrivacyService $privacy) {}

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email:rfc', 'max:120', 'unique:users,email,'.$request->user()->id],
            'locale' => ['nullable', 'in:en,am'],
        ]);
        $request->user()->update($data);

        return response()->json($request->user()->only(['name', 'phone', 'email', 'locale']));
    }

    public function consents(Request $request): JsonResponse
    {
        $u = $request->user();
        $current = $this->consents->current($u);

        return response()->json([
            'marketing_sms' => $current[Consent::MARKETING_SMS]['granted'] ?? false,
            'marketing_email' => $current[Consent::MARKETING_EMAIL]['granted'] ?? false,
            'history' => $u->consents()->latest('id')->limit(50)->get(['type', 'version', 'granted', 'source', 'created_at']),
        ]);
    }

    public function updateConsents(Request $request): JsonResponse
    {
        $data = $request->validate(['marketing_sms' => ['sometimes', 'boolean'], 'marketing_email' => ['sometimes', 'boolean']]);
        $u = $request->user();
        if (array_key_exists('marketing_sms', $data)) {
            $this->consents->setMarketing($u, null, 'sms', $u->phone, $data['marketing_sms'], $request->header('X-Client', 'web'));
        }
        if (array_key_exists('marketing_email', $data)) {
            abort_if($data['marketing_email'] && ! $u->email, 422, 'Add an email address first.');
            if ($u->email) {
                $this->consents->setMarketing($u, null, 'email', $u->email, $data['marketing_email'], $request->header('X-Client', 'web'));
            }
        }

        return $this->consents($request);
    }

    /** Download a copy of your data (JSON). */
    public function export(Request $request): JsonResponse
    {
        return response()->json($this->privacy->export($request->user()))
            ->header('Content-Disposition', 'attachment; filename="bemengede-my-data.json"');
    }

    /** Request deletion. Runs after a short cooling-off period and can be cancelled until then. */
    public function destroy(Request $request): JsonResponse
    {
        $req = $this->privacy->scheduleDeletion($request->user());

        return response()->json([
            'status' => 'scheduled',
            'run_after' => $req->run_after->toIso8601String(),
            'message' => 'Your account and personal data will be deleted on '.$req->run_after->setTimezone('Africa/Addis_Ababa')->format('j M Y, H:i').'. You can cancel before then.',
        ], 202);
    }

    public function cancelDeletion(Request $request): JsonResponse
    {
        PrivacyRequest::where('user_id', $request->user()->id)->where('type', 'delete')->where('status', 'scheduled')
            ->update(['status' => 'cancelled']);

        return response()->json(['status' => 'cancelled']);
    }

    public function deletionStatus(Request $request): JsonResponse
    {
        $r = PrivacyRequest::where('user_id', $request->user()->id)->where('type', 'delete')->where('status', 'scheduled')->first();

        return response()->json(['scheduled' => (bool) $r, 'run_after' => $r?->run_after?->toIso8601String()]);
    }
}
