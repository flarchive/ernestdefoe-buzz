<?php

namespace Ernestdefoe\Buzz\Integration;

use Ernestdefoe\Buzz\Recorder;

/** ernestdefoe/onramp: a member finished their onboarding quest (sent once per member, ever). */
class Onramp
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function __invoke(\ErnestDefoe\Onramp\Event\Graduated $event): void
    {
        $this->recorder->record('quest', $event->user);
    }
}
