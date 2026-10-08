<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests\Support;

/**
 * An enum a consumer tag may return: something the compiled cache can
 * write as PHP even though it is not one of Cooper's own values.
 */
enum Level: string
{
    case Low = 'low';
    case High = 'high';
}
