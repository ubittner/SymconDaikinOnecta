<?php

declare(strict_types=1);

trait globalHelper
{
    protected function IsStringJsonEncoded(string $String): bool
    {
        json_decode($String);
        return json_last_error() === JSON_ERROR_NONE;
    }
}