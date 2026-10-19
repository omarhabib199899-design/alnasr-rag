<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\Inventory\InventoryImportProcessor;
use App\Services\Inventory\TransactionImportProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the actual file-parsing + DB-write step for an Excel import off the
 * request cycle, so a large file can't time out the upload/mapping request.
 * Drained by the `queue:work --stop-when-empty` schedule entry in
 * routes/console.php (there's no persistent worker on this host).
 */
class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * The processors already mark the Import as failed and record the
     * error message on any failure — retrying wouldn't fix that.
     */
    public int $tries = 1;

    /**
     * @param  array<string, string>  $mapping  source_column_name => mapped_field
     */
    public function __construct(
        public readonly Import $import,
        public readonly array $mapping,
    ) {}

    public function handle(InventoryImportProcessor $inventoryProcessor, TransactionImportProcessor $transactionProcessor): void
    {
        $processor = $transactionProcessor->supports($this->import->type) ? $transactionProcessor : $inventoryProcessor;
        $processor->process($this->import, $this->mapping);
    }
}
