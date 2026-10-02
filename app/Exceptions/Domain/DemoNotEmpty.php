<?php

namespace App\Exceptions\Domain;

/**
 * Loading the demo data needs an empty system (D-037, 409): mixing it with real rows
 * would collide on names and give document numbers and stock that tell no single story.
 */
class DemoNotEmpty extends DomainException
{
    public function __construct()
    {
        parent::__construct('The system already has data, so the demo data cannot be loaded. Use reset to start again from the demo data, or clear everything first.');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'demo_not_empty';
    }
}
