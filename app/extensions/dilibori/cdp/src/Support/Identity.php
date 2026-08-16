<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

use Igniter\User\Facades\Auth;
use Illuminate\Support\Str;

class Identity
{
    public static function anonymousId(): string
    {
        $cookie = (string) config('dilibori-cdp.anonymous_id_cookie', 'dilibori_anonymous_id');

        $fromCookie = request()->cookie($cookie)
            ?? request()->cookie('rl_anonymous_id');

        if (is_string($fromCookie) && $fromCookie !== '') {
            return trim($fromCookie, '"');
        }

        return "anon_".Str::uuid()->toString();
    }

    public static function userId(): ?string
    {
        try {
            $customer = Auth::customer();
            if ($customer && $customer->getKey()) {
                return (string) $customer->getKey();
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @return array{email?: string, name?: string, first_name?: string, last_name?: string}
     */
    public static function traitsFromCustomer(mixed $customer): array
    {
        if (! is_object($customer)) {
            return [];
        }

        $traits = [];
        if (! empty($customer->email)) {
            $traits['email'] = (string) $customer->email;
        }
        $first = (string) ($customer->first_name ?? '');
        $last = (string) ($customer->last_name ?? '');
        if ($first !== '') {
            $traits['first_name'] = $first;
        }
        if ($last !== '') {
            $traits['last_name'] = $last;
        }
        $name = trim("{$first} {$last}");
        if ($name !== '') {
            $traits['name'] = $name;
        }

        return $traits;
    }
}
