<?php

namespace App\Console\Commands;

use App\Support\ProductionOperationsPolicy;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Throwable;

class ValidateProductionOperations extends Command
{
    protected $signature = 'ops:validate-production';

    protected $description = 'Validate the production queue, mail, worker, and scheduler topology.';

    public function handle(ProductionOperationsPolicy $policy, MailFactory $mail): int
    {
        if (! app()->environment('production')) {
            $this->info('Production operations policy skipped outside production.');

            return self::SUCCESS;
        }

        $usesOnOneServerScheduler = false;
        foreach (app(Schedule::class)->events() as $event) {
            if ($event->onOneServer) {
                $usesOnOneServerScheduler = true;
                break;
            }
        }

        $violations = $policy->violations(
            config()->all(),
            (int) config('operations.queue_worker_timeout'),
            (int) config('operations.queue_worker_restart_delay'),
            $usesOnOneServerScheduler,
        );

        foreach ($this->smtpTransportViolations($mail) as $violation) {
            $violations[] = $violation;
        }

        $violations = array_values(array_unique($violations));

        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->error($violation);
            }

            return self::FAILURE;
        }

        $this->info('Production operations policy passed.');

        return self::SUCCESS;
    }

    /**
     * Verifies the transport that Laravel actually builds from the mailer
     * configuration instead of trusting the raw environment values. Resolving
     * the transport performs no network I/O.
     *
     * @return list<string>
     */
    private function smtpTransportViolations(MailFactory $mail): array
    {
        try {
            $mailer = $mail->mailer('smtp');
        } catch (Throwable $exception) {
            return [
                'The production SMTP transport could not be constructed: '.$exception::class,
            ];
        }

        if (! $mailer instanceof Mailer) {
            return ['The production SMTP transport did not resolve to an SMTP mailer.'];
        }

        $transport = $mailer->getSymfonyTransport();

        if (! $transport instanceof EsmtpTransport) {
            return ['The production SMTP transport must be a Symfony ESMTP transport.'];
        }

        $stream = $transport->getStream();

        if ($transport->isTlsRequired() || ($stream instanceof SocketStream && $stream->isTLS())) {
            return [];
        }

        return [
            'The production SMTP transport must require TLS: neither implicit TLS (MAIL_SCHEME=smtps) '
            .'nor a required STARTTLS upgrade (MAIL_SCHEME=smtp with MAIL_REQUIRE_TLS=true) is effective.',
        ];
    }
}
