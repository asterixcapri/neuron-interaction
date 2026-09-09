<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/**
 * A Command that can run while the Agent is working.
 *
 * Implementations must not interfere with state used by the active Agent work.
 * Adapters decide whether to admit the Command; this marker grants no automatic
 * execution and does not restrict the ordinary CommandAdapterInterface controls.
 */
interface ConcurrentCommandInterface extends CommandInterface
{
}
