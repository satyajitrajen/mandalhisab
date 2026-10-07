<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Models\DeviceToken;
use App\Models\MandalMember;
use App\Services\FcmService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class AppUpdateController
{
    use ApiResponse;

    /**
     * Current release metadata configuration
     */
    private function getReleaseMetadata(): array
    {
        return [
            'latestVersion' => config('app.latest_version', '1.0.0'),
            'latestBuildNumber' => (int) config('app.latest_build_number', 1),
            'minSupportedVersion' => config('app.min_supported_version', '1.0.0'),
            'minSupportedBuildNumber' => (int) config('app.min_supported_build_number', 1),
            'isForceUpdate' => (bool) config('app.force_update', false),
            'releaseTitleMarathi' => 'नवीन अपडेट उपलब्ध आहे! 🚩',
            'releaseTitleEnglish' => 'New Update Available! 🚩',
            'releaseNotesMarathi' => "• रोख हस्तांतरण दुहेरी नोंदवले जाणार नाही\n• हस्तांतरण योग्य खजिनदाराकडेच जाते\n• जमा केलेल्या रोख रकमेपेक्षा जास्त हस्तांतरण करता येत नाही\n• स्पष्ट त्रुटी संदेश व योग्य भूमिका\n• कार्यप्रदर्शन सुधारणा आणि बग फिक्सेस",
            'releaseNotesEnglish' => "• Cash handovers can no longer be submitted twice\n• Handovers always go to the right treasurer\n• You can't hand over more cash than you collected\n• Clearer error messages and correct role labels\n• Performance improvements & bug fixes",
            'downloadUrl' => rtrim((string) config('app.url'), '/').'/download',
            'apkSizeMb' => (float) config('app.apk_size_mb', 63.3),
            'releasedAt' => '2026-10-07T00:00:00Z',
        ];
    }

    /**
     * GET /api/v1/app/version
     *
     * Public endpoint for apps to check latest version & release notes.
     */
    public function checkVersion(Request $request)
    {
        $currentBuildNumber = (int) $request->query('buildNumber', 0);
        $currentVersion = $request->query('version', '1.0.0');

        $meta = $this->getReleaseMetadata();

        $updateAvailable = $meta['latestBuildNumber'] > $currentBuildNumber;
        $isForceUpdate = $meta['isForceUpdate'] || ($currentBuildNumber > 0 && $currentBuildNumber < $meta['minSupportedBuildNumber']);

        return $this->success([
            'updateAvailable' => $updateAvailable,
            'isForceUpdate' => $isForceUpdate,
            'currentClientVersion' => $currentVersion,
            'currentClientBuild' => $currentBuildNumber,
            'latestVersion' => $meta['latestVersion'],
            'latestBuildNumber' => $meta['latestBuildNumber'],
            'minSupportedVersion' => $meta['minSupportedVersion'],
            'releaseTitleMarathi' => $meta['releaseTitleMarathi'],
            'releaseTitleEnglish' => $meta['releaseTitleEnglish'],
            'releaseNotesMarathi' => $meta['releaseNotesMarathi'],
            'releaseNotesEnglish' => $meta['releaseNotesEnglish'],
            'downloadUrl' => $meta['downloadUrl'],
            'apkSizeMb' => $meta['apkSizeMb'],
            'releasedAt' => $meta['releasedAt'],
        ], 'App version checked successfully');
    }

    /**
     * POST /api/v1/app/broadcast-update
     *
     * Admin endpoint to broadcast an instant push notification to all devices
     * instructing the app to pop up the in-app update dialog.
     */
    public function broadcastUpdatePush(Request $request, FcmService $fcmService)
    {
        $isSuperAdmin = MandalMember::where('user_id', auth()->id())
            ->where('is_active', true)
            ->where('role', MemberRole::SUPER_ADMIN)
            ->exists();

        if (! $isSuperAdmin) {
            return $this->error('FORBIDDEN', 'Only platform super admins can broadcast app updates', 403);
        }

        $validated = $request->validate([
            'force' => ['nullable', 'boolean'],
            'customTitle' => ['nullable', 'string'],
            'customBody' => ['nullable', 'string'],
        ]);

        $meta = $this->getReleaseMetadata();
        $isForce = $validated['force'] ?? $meta['isForceUpdate'];

        $title = $validated['customTitle'] ?? '🚩 मंडळ हिशोब - नवीन व्हर्जन उपलब्ध!';
        $body = $validated['customBody'] ?? 'अ‍ॅपमध्ये नवीन फीचर्स व सुधारणा आल्या आहेत. त्वरित अपडेट करा.';

        $tokens = DeviceToken::pluck('token')->unique()->toArray();

        $payload = [
            'type' => 'APP_UPDATE',
            'latestVersion' => $meta['latestVersion'],
            'latestBuildNumber' => (string) $meta['latestBuildNumber'],
            'forceUpdate' => $isForce ? 'true' : 'false',
            'downloadUrl' => $meta['downloadUrl'],
            'title' => $title,
            'body' => $body,
        ];

        $result = $fcmService->sendToTokens($tokens, $title, $body, $payload);

        return $this->success([
            'broadcastSent' => $result['sent'] > 0,
            'targetedTokens' => count($tokens),
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'payload' => $payload,
        ], 'App update push broadcast dispatched successfully');
    }
}
