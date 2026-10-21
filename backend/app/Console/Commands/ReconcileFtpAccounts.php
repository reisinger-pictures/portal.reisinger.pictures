<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Exceptions\FtpCredentialException;
use App\Exceptions\SftpGoException;
use App\Models\User;
use App\Services\FtpCredentialService;
use App\Values\FtpAccountReconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reconciles the cached FTP account status with SFTPGo (P1-M30).
 *
 * `users.ftp_account_status` is a cache, not the truth (feature doc 7.4): the
 * read path is not allowed to repair it, because a live query in the inbox
 * endpoint would make the UI depend on a service that may be down (7.5). This
 * command is the explicit path: an operator runs it, it asks SFTPGo what it
 * knows about every camera account, and it writes the answer back.
 *
 * It is a command and not an endpoint on purpose. The reconciliation needs the
 * SFTPGo credential, and a photographer-facing route would put a service
 * outage into a request that has nothing to do with the photographer's
 * gallery. The command also gives the operator a place to *see* the result
 * before it is written, which an endpoint cannot.
 *
 * ## What it writes
 *
 * The state transitions are the ones of
 * {@see FtpCredentialService::reconcileAccount()} and are documented there.
 * In short: an account that SFTPGo has (and has enabled) is `active`, one
 * that is gone or disabled is `revoked` with `ftp_revoked_at`, and an account
 * whose state could not be determined keeps its status and gets the reason in
 * `ftp_account_error`.
 *
 * ## What it prints
 *
 * One line per account — **slug, resulting status, reason** — and a counting
 * summary. No name, no e-mail address, no password: the log of a run is a
 * file that outlives the terminal it was written in, and the slug is the only
 * identifier the account itself is named by.
 *
 * ## Exit codes
 *
 * - `0` — every account was checked (whether or not its state changed). An
 *   account whose name cannot be used at all (a legacy slug, P1-M21) is
 *   *skipped*, not failed: it is counted in the summary and named in a warning,
 *   because the slug is not rewritten behind the photographer's back.
 * - `1` — at least one account could not be checked: SFTPGo was unreachable,
 *   not configured, or answered with an error. The states that *were* checked
 *   before the failure are written; the rest keep their status.
 * - `2` — the `--user` option named nobody.
 */
class ReconcileFtpAccounts extends Command
{
    protected $signature = 'ftp:reconcile
                            {--user= : Nur dieses Konto abstimmen (FTP-Kontoname, User-ID oder E-Mail)}';

    protected $description = 'Reconcile the cached FTP account status with SFTPGo (P1-M30)';

    /**
     * The reasons that mean "the service itself is not usable", as opposed to
     * a single account that could not be read.
     *
     * They end the run: every further account would fail the same way, and
     * each attempt costs a connect timeout. The remaining accounts are
     * reported as *not checked* rather than as failed — they were not
     * refused, the run stopped before reaching them.
     */
    private const SERVICE_WIDE_REASONS = [
        SftpGoException::REASON_NOT_CONFIGURED,
        SftpGoException::REASON_UNREACHABLE,
        SftpGoException::REASON_UNAUTHORIZED,
    ];

    public function handle(FtpCredentialService $credentials): int
    {
        $users = $this->resolveUsers();

        if ($users === null) {
            return self::INVALID;
        }

        if ($users->isEmpty()) {
            $this->info('Keine Fotografen mit FTP-Kontoname gefunden — es gibt nichts abzustimmen.');

            return self::SUCCESS;
        }

        $counters = [
            FtpAccountReconciliation::OUTCOME_ACTIVE => 0,
            FtpAccountReconciliation::OUTCOME_WITHDRAWN => 0,
            FtpAccountReconciliation::OUTCOME_UNCHANGED => 0,
            FtpAccountReconciliation::OUTCOME_DIVERGENT => 0,
            'skipped' => 0,
            'failed' => 0,
            'unchecked' => 0,
        ];

        $checked = 0;

        foreach ($users as $user) {
            try {
                $result = $credentials->reconcileAccount($user);
            } catch (FtpCredentialException $exception) {
                // The account name is unusable (a legacy slug, P1-M21): the
                // portal cannot ask SFTPGo about it, and the slug is not
                // rewritten behind the photographer's back. Not a failure of
                // the run — the operator sees it in the summary.
                $counters['skipped']++;
                $this->warn(sprintf('%s: übersprungen (%s)', $this->label($user), $exception->getMessage()));

                continue;
            } catch (SftpGoException $exception) {
                $counters['failed']++;
                $this->error(sprintf('%s: fehlgeschlagen (%s)', $this->label($user), $exception->getMessage()));

                if (in_array($exception->reason, self::SERVICE_WIDE_REASONS, true)) {
                    $unchecked = $users->count() - $checked - 1;
                    $counters['unchecked'] = $unchecked;

                    $this->error(sprintf(
                        'SFTPGo ist nicht verfügbar (%s) — der Lauf wird abgebrochen, %d Konten wurden nicht geprüft.',
                        $exception->reason,
                        $unchecked,
                    ));

                    break;
                }

                continue;
            }

            $checked++;
            $counters[$result->outcome]++;

            $this->line(sprintf('%s: %s (%s)', $this->label($user), $result->status, $result->reason));

            if ($result->outcome === FtpAccountReconciliation::OUTCOME_DIVERGENT) {
                $this->warn(sprintf(
                    '%s: das Konto existiert in SFTPGo, wurde im Portal aber entzogen — der Status bleibt "revoked". Bitte manuell entscheiden.',
                    $this->label($user),
                ));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Reconciliation abgeschlossen: %d aktiv, %d entzogen, %d unverändert, %d abweichend, %d übersprungen, %d fehlgeschlagen, %d nicht geprüft (von %d Konten).',
            $counters[FtpAccountReconciliation::OUTCOME_ACTIVE],
            $counters[FtpAccountReconciliation::OUTCOME_WITHDRAWN],
            $counters[FtpAccountReconciliation::OUTCOME_UNCHANGED],
            $counters[FtpAccountReconciliation::OUTCOME_DIVERGENT],
            $counters['skipped'],
            $counters['failed'],
            $counters['unchecked'],
            $users->count(),
        ));

        if ($counters['failed'] > 0) {
            $this->error('Mindestens ein Konto konnte nicht geprüft werden — der Zustand dieser Konten wurde nicht verändert.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The accounts to reconcile.
     *
     * Without `--user` every photographer with an account name is checked: an
     * account without a slug cannot exist in SFTPGo either, and a user who is
     * not a photographer has no camera account to reconcile.
     *
     * @return Collection<int, User>|null Null when `--user` named nobody.
     */
    private function resolveUsers(): ?Collection
    {
        $requested = $this->option('user');

        if (is_string($requested) && $requested !== '') {
            $user = User::query()
                ->where('ftp_slug', $requested)
                ->orWhere('id', $requested)
                ->orWhere('email', $requested)
                ->first();

            if ($user === null) {
                $this->error(sprintf(
                    "Kein Konto für '%s' gefunden — weder als FTP-Kontoname noch als User-ID noch als E-Mail-Adresse.",
                    $requested,
                ));

                return null;
            }

            return collect([$user]);
        }

        return User::query()
            ->whereNotNull('ftp_slug')
            ->where('ftp_slug', '!=', '')
            ->whereHas('roles', fn ($query) => $query->where('name', UserRole::PHOTOGRAPHER->value))
            ->orderBy('id')
            ->get();
    }

    /**
     * The label of an account in the output: the slug, and nothing else.
     *
     * The slug is what the account is named by in SFTPGo and on the camera.
     * A name or an e-mail address would be personal data in a log file that
     * outlives the run, and neither is needed to identify the account.
     */
    private function label(User $user): string
    {
        $slug = $user->ftp_slug;

        return is_string($slug) && $slug !== '' ? $slug : (string) $user->getKey();
    }
}
