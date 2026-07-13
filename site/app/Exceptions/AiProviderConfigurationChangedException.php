<?php

namespace App\Exceptions;

class AiProviderConfigurationChangedException extends AiProviderException
{
    public function __construct()
    {
        parent::__construct(
            'Journal AI provider configuration changed before execution.',
            self::CATEGORY_CONFIGURATION_CHANGED,
        );
    }
}
