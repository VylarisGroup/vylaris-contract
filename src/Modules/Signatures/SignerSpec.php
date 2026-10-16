<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

final class SignerSpec
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly int $order = 1,
        public readonly ?int $counterpartyId = null,
        public readonly ?int $userId = null,
    ) {
    }

    /** @return array{email:string,name:string,order:int,counterparty_id:?int,user_id:?int} */
    public function toArray(): array
    {
        return ['email' => $this->email, 'name' => $this->name, 'order' => $this->order, 'counterparty_id' => $this->counterpartyId, 'user_id' => $this->userId];
    }
}
