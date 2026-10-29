<?php

namespace PanelAlpha\Migrator\Services\Backup;

/**
 * Writes the migration file once per batch instead of after every chunk.
 * A chunk sent again after a crash in the middle of a batch is recognised by the destination.
 */
trait DefersMigrationSaves
{
    private bool $deferSaves = false;
    private bool $unsavedChanges = false;

    /**
     * Runs a batch of chunks and saves the migration file at the end, also when the batch fails.
     *
     * @param callable $callback
     * @return mixed
     */
    public function inBatch(callable $callback)
    {
        $this->deferSaves = true;

        try {
            return $callback();
        } finally {
            $this->deferSaves = false;

            if ($this->unsavedChanges) {
                $this->unsavedChanges = false;
                $this->saveMigrationData();
            }
        }
    }

    /**
     * Saves migration data to storage, or marks it for the end of the batch.
     */
    private function saveMigrationData(): void
    {
        if ($this->deferSaves) {
            $this->unsavedChanges = true;
            return;
        }

        $this->storageService->saveMigrationData($this->migrationId, $this->migrationData);
    }
}
