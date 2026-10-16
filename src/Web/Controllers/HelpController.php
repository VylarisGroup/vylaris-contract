<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Web\Controller;
use App\Web\HelpTopics;

final class HelpController extends Controller
{
    /** @return array<string,array<string,mixed>> */
    private function topics(): array
    {
        $p = $this->p();
        return HelpTopics::visibleFor($p->isSystemAdmin, $p->can('user.manage') || $p->can('tenant.manage'), $p->userType === 'internal');
    }

    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        return $this->render('help/index', ['topics' => $this->topics()]);
    }

    /** @param array<string,mixed> $params */
    public function show(Request $r, array $params = []): Response
    {
        $topics = $this->topics();
        $slug = (string) ($params['slug'] ?? '');
        if (!isset($topics[$slug])) {
            throw new NotFoundException();
        }
        return $this->render('help/topic', ['slug' => $slug, 'topic' => $topics[$slug], 'topics' => $topics]);
    }
}
