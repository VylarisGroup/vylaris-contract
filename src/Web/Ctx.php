<?php

declare(strict_types=1);

namespace App\Web;

use App\Core\Request;
use App\Models\Principal;

/** Anfragekontext, der an die Controller gereicht wird. */
final class Ctx
{
    public ?Principal $p = null;
    /** @var array<string,mixed>|null */
    public ?array $user = null;

    public function __construct(
        public readonly Services $s,
        public readonly Session $session,
        public readonly View $view,
        public readonly Request $req,
        public readonly string $root,
    ) {
    }
}
