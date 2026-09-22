<?php

namespace App\Security;

final class EmailNormalizer
{
    public static function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    public static function mask(?string $email): ?string
    {
        $email = self::normalize($email);
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);
        $keep = substr($local, 0, 1);

        return $keep.'***@'.$domain;
    }
}
