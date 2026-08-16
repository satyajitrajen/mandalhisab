<?php

namespace App\Services;

use App\Models\CashHandover;

class HandoverService
{
    public function __construct(protected FundService $fundService)
    {
    }

    /**
     * Submit a new cash handover request.
     */
    public function submit(array $data): CashHandover
    {
        return $this->fundService->createHandover(
            $data['festival_id'],
            $data,
            $data['from_user_id']
        );
    }

    /**
     * Verify (accept/reject) an existing handover.
     */
    public function verify(string $handoverId, string $action, string $authMethod, ?string $notes = null): CashHandover
    {
        return $this->fundService->verifyHandover($handoverId, $action, $authMethod, $notes);
    }
}
