<?php

namespace App\Console\Commands;

use App\Services\AccountDeletionService;
use Illuminate\Console\Command;

class ProcessAccountDeletions extends Command
{
    protected $signature = 'accounts:process-deletions';

    protected $description = 'Permanently anonymize accounts whose 7-day deletion wait has ended';

    public function handle(AccountDeletionService $deletionService): int
    {
        $count = $deletionService->processDueDeletions();
        $this->info("Processed {$count} account deletion(s).");

        return self::SUCCESS;
    }
};
