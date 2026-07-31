<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Console;

use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\SmilePay;
use Illuminate\Console\Command;

/**
 * Resolves transactions that never reached a final state.
 *
 * Asynchronous payments fail asynchronously. A customer walks away from a USSD
 * prompt, a callback is lost, a deploy kills the poll job mid-flight — and the
 * transaction sits PROCESSING forever while the merchant has no idea whether
 * they were paid.
 *
 * This command is the answer. Schedule it:
 *
 *     Schedule::command('smilepay:reconcile')->everyFiveMinutes();
 *
 * A payments integration without a reconciliation job is not finished, however
 * well the happy path works.
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'smilepay:reconcile
        {--stale= : Only transactions older than this many seconds}
        {--limit= : Maximum transactions to check in this pass}
        {--dry-run : Report what would be checked without calling the gateway}';

    protected $description = 'Reconcile pending Smile&Pay transactions against the gateway';

    public function handle(SmilePay $smilepay): int
    {
        $stale = (int) ($this->option('stale')
            ?? config('smilepay.reconciliation.stale_after_seconds', 300));

        $limit = (int) ($this->option('limit')
            ?? config('smilepay.reconciliation.batch_size', 100));

        if ($this->option('dry-run')) {
            return $this->dryRun($smilepay, $stale, $limit);
        }

        $this->info(sprintf('Reconciling transactions pending for more than %ds...', $stale));

        $resolved = $smilepay->reconcile($stale, $limit);

        if ($resolved === []) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $settled = 0;
        $stillOpen = 0;
        $rows = [];

        foreach ($resolved as $reference => $status) {
            $status instanceof TransactionStatus && $status->isFinal() ? $settled++ : $stillOpen++;

            $rows[] = [$reference, $status->label()];
        }

        $this->table(['Order reference', 'Resolved status'], $rows);

        $this->info(sprintf(
            'Checked %d — %d reached a final state, %d still open.',
            count($resolved),
            $settled,
            $stillOpen
        ));

        // A transaction that stays open across many passes is usually a
        // customer who abandoned it, but a growing count is a signal worth
        // acting on rather than a number to scroll past.
        if ($stillOpen > 0) {
            $this->comment(
                'Transactions still open after reconciliation are typically abandoned by the '
                .'customer. Investigate if this count keeps growing.'
            );
        }

        return self::SUCCESS;
    }

    private function dryRun(SmilePay $smilepay, int $stale, int $limit): int
    {
        $rows = [];

        foreach ($smilepay->store()->pending($stale, $limit) as $transaction) {
            $rows[] = [
                $transaction->order_reference,
                $transaction->status->label(),
                $transaction->amount()->format(),
                $transaction->created_at?->diffForHumans() ?? '—',
                $transaction->poll_attempts,
            ];
        }

        if ($rows === []) {
            $this->info('Nothing would be reconciled.');

            return self::SUCCESS;
        }

        $this->table(['Reference', 'Status', 'Amount', 'Age', 'Polls'], $rows);
        $this->comment(sprintf('%d transaction(s) would be checked. No gateway calls were made.', count($rows)));

        return self::SUCCESS;
    }
}
