<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Models\Mandal;
use App\Models\MandalMember;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MandalController
{
    use ApiResponse;

    /**
     * GET /api/v1/mandals
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $memberships = MandalMember::with('mandal')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        $data = $memberships->map(function ($mm) {
            $mandal = $mm->mandal;
            return [
                'id' => $mandal->id,
                'name' => $mandal->name,
                'city' => $mandal->city,
                'role' => $mm->role->value,
                'isDefault' => $mm->is_default,
                'logoUrl' => $mandal->logo_url,
            ];
        });

        return $this->success($data, 'Mandal list retrieved');
    }

    /**
     * POST /api/v1/mandals
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'registrationNumber' => ['nullable', 'string', 'max:255'],
            'establishedYear' => ['nullable', 'integer', 'min:1800', 'max:' . date('Y')],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
            'pincode' => ['required', 'string', 'size:6'],
            'wardNumber' => ['nullable', 'string', 'max:50'],
            'contactNumber' => ['required', 'string', 'size:10'],
            'logoBase64' => ['nullable', 'string'],
            'upiId' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        $mandal = DB::transaction(function () use ($validated, $user) {
            $logoUrl = null;
            if (! empty($validated['logoBase64'])) {
                $logoUrl = $this->storeBase64Image($validated['logoBase64'], 'logos');
            }

            $mandal = Mandal::create([
                'name' => $validated['name'],
                'registration_number' => $validated['registrationNumber'] ?? null,
                'established_year' => $validated['establishedYear'] ?? null,
                'address' => $validated['address'],
                'city' => $validated['city'],
                'pincode' => $validated['pincode'],
                'ward_number' => $validated['wardNumber'] ?? null,
                'contact_number' => $validated['contactNumber'],
                'logo_url' => $logoUrl,
                'upi_id' => $validated['upiId'] ?? null,
                'created_by_user_id' => $user->id,
            ]);

            MandalMember::create([
                'mandal_id' => $mandal->id,
                'user_id' => $user->id,
                'role' => MemberRole::ADMIN,
                'is_default' => true,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            return $mandal;
        });

        return $this->success([
            'id' => $mandal->id,
            'name' => $mandal->name,
            'city' => $mandal->city,
            'role' => MemberRole::ADMIN->value,
            'isDefault' => true,
            'logoUrl' => $mandal->logo_url,
        ], 'Mandal created successfully', 201);
    }

    /**
     * GET /api/v1/mandals/:mandalId
     */
    public function show(Request $request, Mandal $mandal)
    {
        $user = $request->user();
        $membership = MandalMember::where('mandal_id', $mandal->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
        }

        return $this->success([
            'id' => $mandal->id,
            'name' => $mandal->name,
            'registrationNumber' => $mandal->registration_number,
            'establishedYear' => $mandal->established_year,
            'address' => $mandal->address,
            'city' => $mandal->city,
            'pincode' => $mandal->pincode,
            'wardNumber' => $mandal->ward_number,
            'contactNumber' => $mandal->contact_number,
            'logoUrl' => $mandal->logo_url,
            'upiId' => $mandal->upi_id,
            'createdAt' => $mandal->created_at,
            'updatedAt' => $mandal->updated_at,
        ], 'Mandal details retrieved');
    }

    /**
     * PUT /api/v1/mandals/:mandalId
     */
    public function update(Request $request, Mandal $mandal)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'registrationNumber' => ['nullable', 'string', 'max:255'],
            'establishedYear' => ['nullable', 'integer', 'min:1800', 'max:' . date('Y')],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'size:6'],
            'wardNumber' => ['nullable', 'string', 'max:50'],
            'contactNumber' => ['nullable', 'string', 'size:10'],
            'logoBase64' => ['nullable', 'string'],
            'upiId' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $membership = MandalMember::where('mandal_id', $mandal->id)
            ->where('user_id', $user->id)
            ->whereIn('role', [MemberRole::ADMIN, MemberRole::SUPER_ADMIN])
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'Only ADMIN can update mandal details', 403);
        }

        if (! empty($validated['logoBase64'])) {
            $validated['logo_url'] = $this->storeBase64Image($validated['logoBase64'], 'logos');
        }

        $mandal->update(array_filter([
            'name' => $validated['name'] ?? null,
            'registration_number' => $validated['registrationNumber'] ?? null,
            'established_year' => $validated['establishedYear'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'pincode' => $validated['pincode'] ?? null,
            'ward_number' => $validated['wardNumber'] ?? null,
            'contact_number' => $validated['contactNumber'] ?? null,
            'logo_url' => $validated['logo_url'] ?? null,
            'upi_id' => $validated['upiId'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->success([
            'id' => $mandal->id,
            'name' => $mandal->name,
            'city' => $mandal->city,
            'logoUrl' => $mandal->logo_url,
        ], 'Mandal updated successfully');
    }

    /**
     * DELETE /api/v1/mandals/:mandalId
     */
    public function destroy(Request $request, Mandal $mandal)
    {
        $user = $request->user();
        $membership = MandalMember::where('mandal_id', $mandal->id)
            ->where('user_id', $user->id)
            ->whereIn('role', [MemberRole::ADMIN, MemberRole::SUPER_ADMIN])
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'Only ADMIN can delete mandals', 403);
        }

        // Soft-delete for audit trail; memberships/festivals stay intact.
        $mandal->delete();

        return $this->success(null, 'Mandal archived successfully');
    }

    protected function storeBase64Image(string $base64, string $folder): string
    {
        $data = base64_decode(explode(',', $base64)[1] ?? $base64);
        $path = "$folder/" . uniqid() . '.png';
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $data);
        return asset('storage/' . $path);
    }
}
