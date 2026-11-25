<?php

// Registration hardening contract: features/auth/05-registration-hardening.md.
//
// Every value here has a safe, network-free default. The host may override it
// through the environment; this host has no production access, so the
// effective production values remain a human gate (D-92).
$positiveInt = static function (string $name, int $default): int {
    $value = env($name, $default);

    if (! is_numeric($value)) {
        return $default;
    }

    $value = (int) $value;

    return $value > 0 ? $value : $default;
};

$defaultBlockedDomains = 'example.com,example.net,example.org,euploading.com';
$configuredBlockedDomains = (string) env('REGISTRATION_BLOCKED_DOMAINS', $defaultBlockedDomains);
// Fail closed: an explicitly empty value must not silently disable the list.
if (trim($configuredBlockedDomains) === '') {
    $configuredBlockedDomains = $defaultBlockedDomains;
}

$blockedDomains = array_map(
    static fn (string $domain): string => strtolower(trim($domain)),
    explode(',', $configuredBlockedDomains),
);

return [
    // Exact matches only (not suffixes), so `team.example.com` stays distinct
    // from a blocked `example.com` and existing org-domain flows are unchanged.
    'blocked_domains' => array_values(array_unique(array_filter($blockedDomains))),

    'rate_limit' => [
        'per_ip' => $positiveInt('REGISTRATION_IP_LIMIT', 3),
        'per_domain' => $positiveInt('REGISTRATION_DOMAIN_LIMIT', 3),
        'window_seconds' => $positiveInt('REGISTRATION_WINDOW_SECONDS', 300),
    ],

    // Opt-in: DNS lookups are a host decision, never a default in dev/CI/tests.
    'mx_check' => [
        'enabled' => (bool) env('REGISTRATION_MX_CHECK', false),
    ],
];
