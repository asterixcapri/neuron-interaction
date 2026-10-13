<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Storage;

use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;

final class InMemoryStorageTest extends StorageContract
{
    protected function storage(): StorageInterface
    {
        return new InMemoryStorage();
    }
}
