<?php

declare(strict_types=1);

namespace App\Archive;

/**
 * What the archive knows about one block of a race snapshot (the TD, the
 * staff and facilities, the driver profile).
 */
enum BlockSource
{
    /** A usable payload was archived for that race. */
    case Present;

    /** The office stamped for that race says the manager had none. */
    case None;

    /** Nothing archived for that race says either way. */
    case Absent;
}
