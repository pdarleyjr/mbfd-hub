<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Mbfd\PolicyLibrary\Support\PinErrorRedactor;

final class PrivacyTest extends TestCase
{
    public function test_pin_request_bodies_and_queries_are_removed_for_every_body_format(): void
    {
        $redactor = new PinErrorRedactor('files.mbfdhub.com');
        foreach (['/access', '/access/', '/acce%73s'] as $path) {
            foreach ([['pin' => 'private-value'], '{"pin":"private-value"}', 'pin=private-value', 'unparseable-private-value'] as $body) {
                $request = $redactor->redactRequest(['url' => 'https://files.mbfdhub.com'.$path.'?pin=private-value', 'data' => $body, 'query_string' => 'pin=private-value', 'method' => 'POST']);
                $this->assertSame(['url' => 'https://files.mbfdhub.com/access', 'method' => 'POST'], $request);
            }
        }
    }

    public function test_redaction_does_not_change_other_hub_error_requests(): void
    {
        $redactor = new PinErrorRedactor('files.mbfdhub.com');
        foreach (['https://mbfdhub.com/access', 'https://files.mbfdhub.com/manage/manuals', 'https://files.mbfdhub.com.evil.test/access'] as $url) {
            $request = ['url' => $url, 'data' => ['label' => 'Manual update']];
            $this->assertSame($request, $redactor->redactRequest($request));
        }
    }
}
