<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\ClaudeTextProvider;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Support\AiRequest;
use App\Services\Ai\Support\AiResponse;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Provider-unabhaengige AI-Schicht (Saeule 8 des Workflow-Engine-Blueprints):
 * die Engine spricht nur gegen AiProviderInterface; der Claude-Adapter ist
 * eine austauschbare Implementierung.
 */
class AiProviderTest extends TestCase
{
    public function test_container_resolves_claude_by_default(): void
    {
        $provider = app(AiProviderInterface::class);
        $this->assertInstanceOf(ClaudeTextProvider::class, $provider);
        $this->assertSame('claude', $provider->name());
    }

    public function test_is_enabled_reflects_api_key(): void
    {
        config(['services.anthropic.key' => '']);
        $this->assertFalse(app(AiProviderInterface::class)->isEnabled());

        config(['services.anthropic.key' => 'test-key']);
        $this->assertTrue(app(AiProviderInterface::class)->isEnabled());
    }

    public function test_complete_sends_expected_request_and_parses_response(): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-5']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Hallo Welt']],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 3],
            'stop_reason' => 'end_turn',
        ])]);

        $response = app(AiProviderInterface::class)->complete(
            AiRequest::text('Du bist ein Assistent.', 'Sag Hallo.', maxTokens: 50)
        );

        $this->assertInstanceOf(AiResponse::class, $response);
        $this->assertSame('Hallo Welt', $response->text);
        $this->assertSame(12, $response->inputTokens);
        $this->assertSame(3, $response->outputTokens);
        $this->assertSame('claude', $response->provider);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($request->url(), 'api.anthropic.com')
                && $request->hasHeader('x-api-key', 'test-key')
                && $body['model'] === 'claude-sonnet-5'
                && $body['max_tokens'] === 50
                && $body['system'] === 'Du bist ein Assistent.'
                && $body['messages'][0]['content'][0]['text'] === 'Sag Hallo.';
        });
    }

    public function test_complete_throws_when_not_configured(): void
    {
        config(['services.anthropic.key' => '']);
        Http::fake();

        $this->expectException(\RuntimeException::class);
        app(AiProviderInterface::class)->complete(AiRequest::text('s', 'p'));
        Http::assertNothingSent();
    }

    public function test_complete_throws_on_service_error(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response('Server Error', 500)]);

        $this->expectException(\RuntimeException::class);
        app(AiProviderInterface::class)->complete(AiRequest::text('s', 'p'));
    }

    public function test_request_with_binary_produces_vision_block(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"ok":true}']],
        ])]);

        $request = AiRequest::text('system', 'Analysiere das Bild.')
            ->withBinary('BINARYDATA', 'image/jpeg');
        app(AiProviderInterface::class)->complete($request);

        Http::assertSent(function ($req) {
            $content = $req->data()['messages'][0]['content'];
            return $content[0]['type'] === 'image'
                && $content[0]['source']['media_type'] === 'image/jpeg'
                && $content[0]['source']['data'] === base64_encode('BINARYDATA')
                && $content[1]['type'] === 'text';
        });
    }

    public function test_response_json_peels_first_object(): void
    {
        $response = new AiResponse(text: 'Hier ist das Ergebnis: {"service":"neues_kind","confidence":92} - fertig.');
        $this->assertSame(['service' => 'neues_kind', 'confidence' => 92], $response->json());

        $this->assertNull((new AiResponse(text: 'kein json'))->json());
    }
}
