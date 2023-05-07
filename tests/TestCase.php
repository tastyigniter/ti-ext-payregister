<?php

namespace Igniter\PayRegister\Tests;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Igniter\Flame\ServiceProvider::class,
            \Igniter\PayRegister\Extension::class,
        ];
    }
}
