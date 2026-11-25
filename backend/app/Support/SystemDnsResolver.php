<?php

namespace App\Support;

use App\Contracts\DnsResolver;

final class SystemDnsResolver implements DnsResolver
{
    public function hasMailExchange(string $domain): bool
    {
        return checkdnsrr($domain, 'MX');
    }
}
