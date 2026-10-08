<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

/**
 * A value `PhpExport` cannot write as PHP source -- an object it does
 * not know how to rebuild, a closure, a resource. The compiled cache
 * catches it and simply does not compile that configuration.
 *
 * @internal
 */
final class NotExportable extends \RuntimeException
{
}
