<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Every error a member can hit in the installed app ends on a page with a way back to Hub home. */
class HubErrorPagesTest extends TestCase
{
    public function test_each_refusal_page_explains_itself_and_links_home(): void
    {
        foreach ([
            401 => 'Sign in to continue',
            403 => 'You do not have access to this page',
            405 => 'This page cannot be opened this way',
            429 => 'Too many requests',
        ] as $status => $title) {
            $page = view('errors.'.$status, ['exception' => new HttpException($status)])->render();

            $this->assertStringContainsString('ERROR '.$status, $page, (string) $status);
            $this->assertStringContainsString($title, $page, (string) $status);
            $this->assertStringContainsString('href="'.url('/').'"', $page, (string) $status);
            $this->assertStringContainsString('Return to Hub home', $page, (string) $status);
            $this->assertStringNotContainsString("\u{2014}", strip_tags(explode('<main', $page, 2)[1]), (string) $status);
        }
    }

    public function test_repeating_the_same_request_is_only_offered_where_it_can_help(): void
    {
        foreach ([401, 403, 404, 405] as $status) {
            $this->assertStringNotContainsString('Try again', view('errors.'.$status, ['exception' => new HttpException($status)])->render(), (string) $status);
        }
        foreach ([429, 500, 503] as $status) {
            $this->assertStringContainsString('Try again', view('errors.'.$status, ['exception' => new HttpException($status)])->render(), (string) $status);
        }
    }

    public function test_a_deliberate_sentence_is_shown_on_403_and_nothing_else_is(): void
    {
        $sentence = 'The 300 Command profile is available to Captains, Division Chiefs, and administrators.';
        $page = view('errors.403', ['exception' => new HttpException(403, $sentence)])->render();
        $this->assertStringContainsString($sentence.' Nothing was changed.', $page);

        foreach ([
            '',
            'This action is unauthorized.',
            'Forbidden',
            'App\\Policies\\StationPolicy::update denied',
            'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates constraint "users_pkey".',
            '<script>alert(1)</script> This is a long enough message to pass a length check.',
        ] as $internal) {
            $page = view('errors.403', ['exception' => new HttpException(403, $internal)])->render();
            $this->assertStringContainsString('Your account is not set up to open this page', $page, $internal);
            if ($internal !== '') {
                $this->assertStringNotContainsString(e($internal), $page, $internal);
            }
        }
        $this->assertStringContainsString('Your account is not set up to open this page', view('errors.403')->render());
    }

    public function test_real_requests_render_the_hub_pages(): void
    {
        Route::middleware('web')->group(function (): void {
            Route::get('/_test/refused-with-reason', fn () => abort(403, 'Only the shift Captain can open this roster today.'));
            Route::get('/_test/refused-plain', fn () => abort(403));
        });

        $this->get('/_test/refused-with-reason')->assertForbidden()
            ->assertSee('Only the shift Captain can open this roster today. Nothing was changed.')
            ->assertSee('Return to Hub home');
        $this->get('/_test/refused-plain')->assertForbidden()
            ->assertSee('You do not have access to this page')
            ->assertSee('Return to Hub home');
        $this->get('/logout')->assertStatus(405)->assertSee('This page cannot be opened this way');
    }

    public function test_an_oversized_upload_on_a_web_page_gets_the_hub_page_and_the_json_contract_is_unchanged(): void
    {
        $handler = app(ExceptionHandler::class);
        $response = $handler->render(Request::create('/some/web/page', 'POST'), new PostTooLargeException);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertStringContainsString('That file is too large', $response->getContent());
        $this->assertStringContainsString('Return to Hub home', $response->getContent());

        $json = $handler->render(
            Request::create('/employee/forms/api/froc/import-preview', 'POST', server: ['HTTP_ACCEPT' => 'application/json']),
            new PostTooLargeException,
        );
        $this->assertSame(413, $json->getStatusCode());
        $this->assertSame('request_too_large', json_decode($json->getContent(), true)['code']);
    }
}
