<?php

declare(strict_types=1);

namespace NeuronInteraction\Exception;

use RuntimeException;

/** Thrown by an event callback to abort the current execution. */
final class ExecutionAbortedException extends RuntimeException {}
