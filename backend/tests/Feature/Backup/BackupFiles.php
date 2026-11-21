<?php

namespace Tests\Feature\Backup;

use Illuminate\Support\Facades\File;

/** Сақтық көшірме тесттері нақты қалтаға жазады; әр тесттің алды-артында ол тазаланады. */
trait BackupFiles
{
    protected function setUpBackupFiles(): void
    {
        File::deleteDirectory(storage_path('app/backups'));
    }

    protected function tearDownBackupFiles(): void
    {
        File::deleteDirectory(storage_path('app/backups'));
    }
}
