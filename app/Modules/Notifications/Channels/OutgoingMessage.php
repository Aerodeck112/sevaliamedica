<?php

namespace App\Modules\Notifications\Channels;

final class OutgoingMessage
{
    /**
     * @param  list<string>  $providerParams  WhatsApp: valorile parametrilor șablonului aprobat, în ordine
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $recipient,
        public readonly string $body,
        public readonly ?string $subject = null,
        public readonly ?string $providerTemplate = null,
        public readonly array $providerParams = [],
        public readonly string $reference = '',
    ) {}
}
