<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Console;

use AaronKatema\SmilePay\Exceptions\SmilePayException;
use AaronKatema\SmilePay\Models\SmilePayTransaction;
use AaronKatema\SmilePay\SmilePay;
use AaronKatema\SmilePay\Support\Config;
use Illuminate\Console\Command;

/**
 * Inspect a single transaction, or check that credentials work at all.
 *
 * The first thing to run when a merchant says "payments are broken" — it
 * separates a configuration problem from a gateway problem in one command,
 * and shows the local record beside the gateway's answer so a divergence is
 * immediately visible.
 */
final class StatusCommand extends Command
{
    protected $signature = 'smilepay:status
        {reference? : Order reference to look up}
        {--local : Show only the local record, without calling the gateway}';

    protected $description = 'Check a Smile&Pay transaction, or verify your configuration';

    public function handle(SmilePay $smilepay, Config $config): int
    {
        $reference = $this->argument('reference');

        if (! is_string($reference) || trim($reference) === '') {
            return $this->showConfiguration($config);
        }

        $local = $smilepay->transaction($reference);

        if ($local instanceof SmilePayTransaction) {
            $this->line('<comment>Local record</comment>');
            $this->table(['Field', 'Value'], [
                ['Order reference', $local->order_reference],
                ['Transaction reference', $local->transaction_reference ?? '—'],
                ['Status', $local->status->label()],
                ['Verified', $local->isVerified() ? 'yes' : 'no'],
                ['Method', $local->method?->label() ?? '—'],
                ['Amount', $local->amount()->format()],
                ['Merchant fee', $local->merchantFee()?->format() ?? '—'],
                ['Net', $local->netAmount()->format()],
                ['Customer', $local->mobile_number ?? '—'],
                ['Created', $local->created_at?->toDateTimeString() ?? '—'],
                ['Paid', $local->paid_at?->toDateTimeString() ?? '—'],
                ['Poll attempts', (string) $local->poll_attempts],
                ['Last error', $local->last_error ?? '—'],
            ]);
        } else {
            $this->warn('No local record for this reference.');
        }

        if ($this->option('local')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<comment>Gateway</comment>');

        try {
            $snapshot = $smilepay->status($reference);
        } catch (SmilePayException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['Status', $snapshot->status->label()],
            ['Transaction reference', $snapshot->transactionReference ?? '—'],
            ['Amount', $snapshot->amount?->format() ?? '—'],
            ['Method', $snapshot->method?->label() ?? '—'],
            ['Client fee', $snapshot->clientFee?->format() ?? '—'],
            ['Merchant fee', $snapshot->merchantFee?->format() ?? '—'],
            ['Created', $snapshot->createdAt?->format('Y-m-d H:i:s') ?? '—'],
        ]);

        // A divergence here is the whole reason to print both tables. It means
        // a callback was missed or a status changed after the last check, and
        // it is exactly what reconciliation exists to fix.
        if ($local instanceof SmilePayTransaction && $local->status !== $snapshot->status) {
            $this->newLine();
            $this->warn(sprintf(
                'Local record says %s but the gateway says %s. Run `smilepay:reconcile` to resolve.',
                $local->status->label(),
                $snapshot->status->label()
            ));
        }

        return self::SUCCESS;
    }

    private function showConfiguration(Config $config): int
    {
        $this->line('<comment>Smile&Pay configuration</comment>');

        $this->table(['Setting', 'Value'], [
            ['Environment', $config->environment->value],
            ['Base URL', $config->baseUrl],
            ['API key', \AaronKatema\SmilePay\Support\Redactor::maskCredential($config->apiKey)],
            ['API secret', \AaronKatema\SmilePay\Support\Redactor::maskCredential($config->apiSecret)],
            ['Default currency', $config->defaultCurrency->value],
            ['Persist transactions', $config->persistTransactions ? 'yes' : 'no'],
            ['Verify callbacks', $config->verifyCallbacks ? 'yes' : 'NO — UNSAFE'],
            ['TLS verification', $config->verifySsl ? 'yes' : 'NO — UNSAFE'],
            ['Timeout', $config->timeout.'s'],
        ]);

        if (! $config->verifyCallbacks) {
            $this->error(
                'Callback verification is disabled. Unsigned Smile&Pay callbacks are forgeable — '
                .'anyone who knows your webhook URL can claim a payment succeeded.'
            );
        }

        $this->newLine();
        $this->comment('Pass an order reference to look up a specific transaction.');

        return self::SUCCESS;
    }
}
