<?php

namespace App\Listeners;

use App\Services\ResetFlag;
use Illuminate\Queue\Events\Looping;

/**
 * The queue worker takes no job while a factory reset runs: returning false
 * from a Looping listener makes it wait and try again on its next loop. The
 * jobs the reset deletes are then never run half way through it.
 */
final class PauseWorkerWhileResetting
{
    public function __construct(private ResetFlag $flag) {}

    public function handle(Looping $event): ?bool
    {
        return $this->flag->active() ? false : null;
    }
}
