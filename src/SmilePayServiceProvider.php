<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay;

use AaronKatema\SmilePay\Console\ReconcileCommand;
use AaronKatema\SmilePay\Console\StatusCommand;
use AaronKatema\SmilePay\Contracts\TransactionStore;
use AaronKatema\SmilePay\Http\Client;
use AaronKatema\SmilePay\Repositories\EloquentTransactionStore;
use AaronKatema\SmilePay\Repositories\NullTransactionStore;
use AaronKatema\SmilePay\Support\Config;
use AaronKatema\SmilePay\Webhooks\CallbackHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Wires the package into Laravel.
 *
 * Everything is bound lazily. A misconfigured merchant account throws on first
 * use with a message naming the exact `.env` key — it does not break `artisan`
 * for an application that only touches payments on one route.
 */
final class SmilePayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/smilepay.php', 'smilepay');

        $this->app->singleton(Config::class, function (): Config {
            /** @var array<string, mixed> $config */
            $config = $this->app['config']->get('smilepay', []);

            return Config::fromArray($config);
        });

        $this->app->singleton(LoggerInterface::class.'@smilepay', function (): LoggerInterface {
            /** @var string|null $channel */
            $channel = $this->app['config']->get('smilepay.log_channel');

            return $channel === null ? Log::getLogger() : Log::channel($channel);
        });

        $this->app->singleton(Client::class, fn (): Client => new Client(
            $this->app->make(Config::class),
            $this->smilePayLogger(),
        ));

        $this->app->singleton(TransactionStore::class, function (): TransactionStore {
            $config = $this->app->make(Config::class);

            return $config->persistTransactions
                ? new EloquentTransactionStore($config->environment)
                : new NullTransactionStore;
        });

        $this->app->singleton(SmilePay::class, fn (): SmilePay => new SmilePay(
            $this->app->make(Config::class),
            $this->app->make(Client::class),
            $this->app->make(TransactionStore::class),
            $this->app->make(Dispatcher::class),
            $this->smilePayLogger(),
        ));

        $this->app->alias(SmilePay::class, 'smilepay');

        $this->app->singleton(CallbackHandler::class, function (): CallbackHandler {
            $config = $this->app->make(Config::class);

            // Verification cannot be switched off in production. The only
            // reason to disable it is local development against a stub, and a
            // config flag that silently removes the package's core security
            // guarantee on a live merchant account is not a trade worth
            // offering.
            $verify = $config->verifyCallbacks || $config->environment->isProduction();

            return new CallbackHandler(
                $this->app->make(SmilePay::class),
                $this->app->make(Dispatcher::class),
                $this->smilePayLogger(),
                (bool) $this->app['config']->get('smilepay.webhook.store_events', true)
                    && $config->persistTransactions,
                $verify,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/smilepay.php' => config_path('smilepay.php'),
            ], 'smilepay-config');

            $this->publishes([
                __DIR__.'/../database/migrations/0001_01_01_000000_create_smilepay_tables.php.stub'
                    => $this->migrationPath(),
            ], 'smilepay-migrations');

            $this->commands([
                ReconcileCommand::class,
                StatusCommand::class,
            ]);
        }

        if ((bool) $this->app['config']->get('smilepay.webhook.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/smilepay.php');
        }
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            SmilePay::class,
            'smilepay',
            Client::class,
            Config::class,
            TransactionStore::class,
            CallbackHandler::class,
        ];
    }

    private function smilePayLogger(): LoggerInterface
    {
        /** @var LoggerInterface */
        return $this->app->make(LoggerInterface::class.'@smilepay');
    }

    /**
     * Timestamp the published migration so it lands after the host app's own.
     */
    private function migrationPath(): string
    {
        return database_path(
            'migrations/'.date('Y_m_d_His').'_create_smilepay_tables.php'
        );
    }
}
