<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/**
 * A Command suitable for client admission while the Agent is working.
 *
 * Implementations must not interfere with state used by the active Agent work.
 * Each client Adapter decides whether to admit the Command; this marker grants no automatic
 * execution and does not restrict the ordinary CommandAdapterInterface controls.
 */
interface ConcurrentCommandInterface extends CommandInterface {}
