<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Exceptions\FtpCredentialException;
use App\Exceptions\SftpGoException;
use App\Models\User;
use App\Services\FtpCredentialService;
use App\Services\SftpGoClient;
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
 * knows about every camera account, compares remote names with portal slugs,
 * and writes only reconciled account states back.
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
 * In short: an enabled account becomes `active`; a missing or disabled account
 * follows the prior-state transition (`active` to `revoked`, `error` to
 * `pending`, and `pending`/`revoked` unchanged). An unclaimed camera account
 * makes an `active`/`error` 404 name drift instead: the status,
 * `ftp_account_error`, and `ftp_revoked_at` remain untouched. An account whose
 * state could not be determined keeps its status and gets the reason in
 * `ftp_account_error`.
 *
 * ## What it prints
 *
 * One line per account — **slug, resulting status, reason** — a dedicated
 * name-drift section, and a counting summary. No personal/display name,
 * e-mail address, or password is logged; technical SFTPGo account names are
 * included in the drift warnings. The log outlives the terminal it was written
 * in, and the slug is the identifier the account itself is named by.
 *
 * ## Exit codes
 *
 * - `0` — every account was checked (whether or not its state changed) and no
 *   name drift was found. An account whose name cannot be used at all (a legacy slug, P1-M21) is
 *   *skipped*, not failed: it is counted in the summary and named in a warning,
 *   because the slug is not rewritten behind the photographer's back.
 * - `1` — an account could not be checked, the drift listing failed, an account
 *   lock was busy, or name drift needs an operator decision. The states that
 *   *were* checked before an account failure are written; drifted states remain
 *   untouched.
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

    public function handle(FtpCredentialService $credentials, SftpGoClient $sftpGo): int
    {
        $users = $this->resolveUsers();

        if ($users === null) {
            return self::INVALID;
        }

        try {
            $cameraAccounts = $sftpGo->listCameraAccounts();
        } catch (SftpGoException $exception) {
            $this->error(sprintf(
                'FATAL: SFTPGo-Kamerakonten konnten nicht aufgelistet werden (%s) — Drift-Prüfung abgebrochen; es wurde kein Konto abgestimmt.',
                $exception->reason,
            ));

            return self::FAILURE;
        }

        $portalSlugs = User::query()->whereNotNull('ftp_slug')->pluck('ftp_slug')->all();
        $portalSlugSet = array_fill_keys($portalSlugs, true);
        $orphanAccounts = array_values(array_filter(
            $cameraAccounts,
            static fn (array $account): bool => ! array_key_exists($account['username'], $portalSlugSet),
        ));
        $orphanAccountNames = array_column($orphanAccounts, 'username');

        if ($users->isEmpty()) {
            $this->info('Keine Fotografen mit FTP-Kontoname gefunden — es gibt nichts abzustimmen.');
        }

        $counters = [
            FtpAccountReconciliation::OUTCOME_ACTIVE => 0,
            FtpAccountReconciliation::OUTCOME_WITHDRAWN => 0,
            FtpAccountReconciliation::OUTCOME_UNCHANGED => 0,
            FtpAccountReconciliation::OUTCOME_DIVERGENT => 0,
            FtpAccountReconciliation::OUTCOME_RETRYABLE => 0,
            'skipped' => 0,
            'failed' => 0,
            'unchecked' => 0,
            'drift' => count($orphanAccounts),
        ];

        $attempted = 0;
        $driftedUsers = [];

        foreach ($users as $user) {
            $attempted++;

            try {
                $result = $credentials->reconcileAccount($user, $orphanAccountNames);
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
                    $unchecked = $users->count() - $attempted;
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

            $counters[$result->outcome]++;

            $this->line(sprintf('%s: %s (%s)', $this->label($user), $result->status, $result->reason));

            if ($result->outcome === FtpAccountReconciliation::OUTCOME_RETRYABLE) {
                $this->warn(sprintf('%s: später erneut abstimmen (%s)', $this->label($user), $result->reason));
            }

            if ($result->outcome === FtpAccountReconciliation::OUTCOME_SKIPPED) {
                $this->warn(sprintf('%s: übersprungen (%s)', $this->label($user), $result->reason));
            }

            if ($result->outcome === FtpAccountReconciliation::OUTCOME_DIVERGENT
                && $result->reason === FtpAccountReconciliation::REASON_ACCOUNT_NAME_DRIFT) {
                $driftedUsers[] = $user;
                $counters['drift']++;
            } elseif ($result->outcome === FtpAccountReconciliation::OUTCOME_DIVERGENT) {
                $this->warn(sprintf(
                    '%s: das Konto existiert in SFTPGo, wurde im Portal aber entzogen — der Status bleibt "revoked". Bitte manuell entscheiden.',
                    $this->label($user),
                ));
            }
        }

        $this->newLine();
        $this->warn(sprintf(
            'Namensabweichungen (%d), gelesen wurden %d Kamerakonten in SFTPGo:',
            $counters['drift'],
            count($cameraAccounts),
        ));

        foreach ($orphanAccounts as $account) {
            $this->warn(sprintf(
                '%s: unbeanspruchtes Kamera-Konto in %s — kein Portal-Slug beansprucht diesen Namen.',
                $account['username'],
                $account['home_dir'],
            ));
        }

        foreach ($driftedUsers as $user) {
            $this->warn(sprintf(
                '%s: Der Portal-Slug hat kein Konto; unbeanspruchte Kamera-Konten: %s. Der Befund ist mehrdeutig (Umbenennung oder tatsächlicher Entzug); der Status blieb unverändert. Bitte manuell entscheiden.',
                $this->label($user),
                implode(', ', $orphanAccountNames),
            ));
        }

        if ($counters['drift'] === 0) {
            $this->line('Keine Namensabweichungen gefunden.');
        }

        $this->newLine();
        $this->info(sprintf(
            'Reconciliation abgeschlossen: %d aktiv, %d entzogen, %d unverändert, %d abweichend, Namensabweichungen: %d, %d erneut abzustimmen, %d übersprungen, %d fehlgeschlagen, %d nicht geprüft (von %d Konten).',
            $counters[FtpAccountReconciliation::OUTCOME_ACTIVE],
            $counters[FtpAccountReconciliation::OUTCOME_WITHDRAWN],
            $counters[FtpAccountReconciliation::OUTCOME_UNCHANGED],
            $counters[FtpAccountReconciliation::OUTCOME_DIVERGENT],
            $counters['drift'],
            $counters[FtpAccountReconciliation::OUTCOME_RETRYABLE],
            $counters['skipped'],
            $counters['failed'],
            $counters['unchecked'],
            $users->count(),
        ));

        if ($counters['failed'] > 0 || $counters[FtpAccountReconciliation::OUTCOME_RETRYABLE] > 0) {
            $this->error('Mindestens ein Konto konnte nicht geprüft werden — der Zustand dieser Konten wurde nicht verändert.');

            return self::FAILURE;
        }

        if ($counters['drift'] > 0) {
            $this->error('Namensabweichungen gefunden — eine manuelle Entscheidung ist erforderlich.');

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
