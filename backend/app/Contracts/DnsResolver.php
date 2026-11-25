<?php

namespace App\Contracts;

interface DnsResolver
{
    /**
     * Can mail be delivered to this domain (an MX record exists)?
     *
     * The interface exists so the optional registration MX check can be tested
     * without outbound DNS; the system implementation is bound in
     * AppServiceProvider::register().
     */
    public function hasMailExchange(string $domain): bool;
}
