<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MandalArea;
use App\Models\MandalMember;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class AreaController
{
    use ApiResponse;

    /**
     * GET /api/v1/mandals/{mandal}/areas
     */
    public function index(Request $request, $mandal)
    {
        $user = $request->user();

        $membership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'You are not an active member of this mandal', 403);
        }

        $areas = MandalArea::where('mandal_id', $mandal)
            ->orderBy('name', 'asc')
            ->get();

        return $this->success($areas->map(function ($area) {
            return [
                'id' => $area->id,
                'mandalId' => $area->mandal_id,
                'name' => $area->name,
                'wardNumber' => $area->ward_number,
                'createdAt' => $area->created_at?->toIso8601String(),
            ];
        }));
    }

    /**
     * POST /api/v1/mandals/{mandal}/areas
     */
    public function store(Request $request, $mandal)
    {
        $user = $request->user();

        $membership = MandalMember::where('mandal_id', $mandal)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership || ! in_array($membership->role?->value ?? $membership->role, ['ADMIN', 'TREASURER'])) {
            return $this->error('FORBIDDEN', 'Only admins and treasurers can manage areas', 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'wardNumber' => ['nullable', 'string', 'max:50'],
        ]);

        $area = MandalArea::create([
            'mandal_id' => $mandal,
            'name' => trim($validated['name']),
            'ward_number' => $validated['wardNumber'] ?? null,
        ]);

        return $this->success([
            'id' => $area->id,
            'mandalId' => $area->mandal_id,
            'name' => $area->name,
            'wardNumber' => $area->ward_number,
            'createdAt' => $area->created_at?->toIso8601String(),
        ], 'Area created successfully', 201);
    }

    /**
     * PUT /api/v1/areas/{area}
     */
    public function update(Request $request, $areaId)
    {
        $area = MandalArea::find($areaId);
        if (! $area) {
            return $this->error('NOT_FOUND', 'Area not found', 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'wardNumber' => ['nullable', 'string', 'max:50'],
        ]);

        $area->update([
            'name' => trim($validated['name']),
            'ward_number' => $validated['wardNumber'] ?? $area->ward_number,
        ]);

        return $this->success([
            'id' => $area->id,
            'mandalId' => $area->mandal_id,
            'name' => $area->name,
            'wardNumber' => $area->ward_number,
            'createdAt' => $area->created_at?->toIso8601String(),
        ], 'Area updated successfully');
    }

    /**
     * DELETE /api/v1/areas/{area}
     */
    public function destroy(Request $request, $areaId)
    {
        $area = MandalArea::find($areaId);
        if (! $area) {
            return $this->error('NOT_FOUND', 'Area not found', 404);
        }

        $area->delete();

        return $this->success(null, 'Area deleted successfully');
    }
}
