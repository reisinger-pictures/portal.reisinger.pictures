<?php

namespace Tests\Unit\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MailpitAssertions;
use Tests\TestCase;

class MailpitAssertionsTest extends TestCase
{
    use MailpitAssertions;

    /**
     * assertMailpitSentTo() must use the recipient-scoped /search endpoint, and
     * must never fetch the global /messages list. GET /messages applies
     * Mailpit's default limit (50) and returns only the newest messages, so on
     * a long-lived or heavily parallel Mailpit the mail under test falls
     * outside the window and the assertion reports it as missing. Measured with
     * 569 stored messages: /messages returned 50, /search returned the 7 that
     * matched. The negative half of this test — no request to /messages — is
     * the regression guard that would have caught the original bug.
     */
    public function test_assert_mailpit_sent_to_searches_by_recipient_and_never_lists_all_messages(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'messages' => [[
                'ID' => 'message-1',
                'To' => [['Address' => 'buyer@example.com']],
            ]],
        ]));

        $this->assertMailpitSentTo('buyer@example.com');

        // The regression: the previous implementation called GET /messages and
        // filtered the client-side, which silently missed older mail. This
        // negative assertion is the one that catches a revert; it is checked
        // first so a regression fails on the mechanism, not on the count.
        Http::assertNotSent(
            fn (Request $request): bool => str_ends_with(
                (string) parse_url($request->url(), PHP_URL_PATH),
                '/messages'
            )
        );

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/search')
                && ($query['query'] ?? null) === 'to:buyer@example.com';
        });

        Http::assertSentCount(1);
    }
}
