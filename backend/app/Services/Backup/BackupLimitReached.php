<?php

namespace App\Services\Backup;

use RuntimeException;

/** Бір күнде жасауға болатын көшірме саны таусылды. */
class BackupLimitReached extends RuntimeException {}
