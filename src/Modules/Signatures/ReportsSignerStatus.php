<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/** Optional: Anbieter, die den Stand je Unterzeichnenden kennen. */
interface ReportsSignerStatus
{
    /** @return list<array{name:string,email:string,status:string,at:?string,order:int}> status: waiting|invited|signed|declined */
    public function signerStatus(string $externalId): array;
}
