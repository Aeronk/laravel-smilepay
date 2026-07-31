<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Tests;

use AaronKatema\SmilePay\Http\Client;
use AaronKatema\SmilePay\SmilePayServiceProvider;
use AaronKatema\SmilePay\Support\Config;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    protected array $recorded = [];


    protected function getPackageProviders($app): array
    {
        return [SmilePayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('smilepay.environment', 'sandbox');
        $app['config']->set('smilepay.environments.sandbox.key', 'test_key');
        $app['config']->set('smilepay.environments.sandbox.secret', 'test_secret');
        $app['config']->set('smilepay.defaults.return_url', 'https://merchant.test/return');
        $app['config']->set('smilepay.defaults.result_url', 'https://merchant.test/callback');
        $app['config']->set('smilepay.log_requests', false);
    }

    /**
     * Run the package migration by hand.
     *
     * `loadMigrationsFrom()` globs for `*.php`, so the published `.php.stub`
     * would silently never run and every DB-backed test would fail on a
     * missing table. Requiring the stub directly keeps a single source of
     * truth for the schema — the file merchants actually publish is the file
     * the suite tests against.
     */
    protected function defineDatabaseMigrations(): void
    {
        $migration = require __DIR__.'/../database/migrations/0001_01_01_000000_create_smilepay_tables.php.stub';

        $migration->up();
    }

    /**
     * Bind a Guzzle mock so no test can reach the network, and every outgoing
     * request is captured for assertion.
     *
     * @param  list<Response>  $responses
     */
    protected function mockGateway(array $responses): void
    {
        $this->recorded = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->recorded));

        $this->app->singleton(Client::class, fn () => new Client(
            $this->app->make(Config::class),
            null,
            new Guzzle(['handler' => $stack, 'http_errors' => false]),
        ));

        // Rebuild everything downstream of the client, or a previously resolved
        // singleton would keep the real Guzzle and quietly hit the network.
        $this->app->forgetInstance(\AaronKatema\SmilePay\SmilePay::class);
        $this->app->forgetInstance(\AaronKatema\SmilePay\Webhooks\CallbackHandler::class);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function jsonResponse(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /**
     * The decoded body of the nth request the package sent.
     *
     * @return array<string, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        $request = $this->recorded[$index]['request'] ?? null;

        if (! $request instanceof RequestInterface) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true) ?: [];

        return $decoded;
    }

    protected function sentPath(int $index = 0): string
    {
        $request = $this->recorded[$index]['request'] ?? null;

        return $request instanceof RequestInterface ? $request->getUri()->getPath() : '';
    }

    protected function sentHeader(string $name, int $index = 0): string
    {
        $request = $this->recorded[$index]['request'] ?? null;

        return $request instanceof RequestInterface ? $request->getHeaderLine($name) : '';
    }
}
