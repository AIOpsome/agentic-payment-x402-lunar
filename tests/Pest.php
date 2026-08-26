<?php

use Lunar\CryptoPayments\Tests\Feature\TestCase as FeatureTestCase;
use Lunar\CryptoPayments\Tests\TestCase;

require_once __DIR__.'/Feature/Helpers.php';

uses(TestCase::class)->in('Unit');
uses(FeatureTestCase::class)->in('Feature');
