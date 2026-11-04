<?php

use Tests\TestCase;

/*
| Feature: app Laravel completo com tabela recriada no DynamoDB Local a cada
| teste (DYNAMODB_ENDPOINT). Unit: classes isoladas.
*/

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        $this->artisan('billing:dynamodb-table', ['--fresh' => true]);
    })
    ->in('Feature');
