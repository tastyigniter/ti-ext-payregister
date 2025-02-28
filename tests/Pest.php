<?php

declare(strict_types=1);

use SamPoyigi\Testbench\TestCase;

use Igniter\User\Models\User;

uses(TestCase::class)->in(__DIR__);

function actingAsSuperUser()
{
    return test()->actingAs(User::factory()->superUser()->create(), 'igniter-admin');
}
