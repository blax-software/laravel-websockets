<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Identity;

use BlaxSoftware\LaravelWebSockets\Contracts\IdentityFormatter;

/**
 * Default identity formatter — handles the common case of an Eloquent User
 * (or any object exposing id / name / username / email properties).
 *
 * Output shape: `#<id> - <name> | <username> - <email>` with each suffix
 * dropped if the corresponding field is missing or null. Returns "Guest"
 * for null subjects and for subjects that have no usable id at all.
 *
 * Apps with a different subject shape (Company, ApiClient, custom auth blob)
 * should implement `IdentityFormatter` and bind it in their service provider
 * — see the interface docblock for an example.
 */
class DefaultIdentityFormatter implements IdentityFormatter
{
    public function format(mixed $subject, string $socketId): string
    {
        if (! $subject) {
            return 'Guest';
        }

        $id = $this->read($subject, 'id');
        if ($id === null || $id === '') {
            return 'Guest';
        }

        $out = '#' . $id;

        if ($name = $this->read($subject, 'name')) {
            $out .= ' - ' . $name;
        }
        if ($username = $this->read($subject, 'username')) {
            $out .= ' | ' . $username;
        }
        if ($email = $this->read($subject, 'email')) {
            $out .= ' - ' . $email;
        }

        return $out;
    }

    /**
     * Best-effort property read across Eloquent models, stdClass, and arrays.
     */
    private function read(mixed $subject, string $key): mixed
    {
        if (is_array($subject)) {
            return $subject[$key] ?? null;
        }
        if (is_object($subject)) {
            // Eloquent and stdClass both support property access; missing
            // attributes on Eloquent return null instead of throwing.
            return $subject->{$key} ?? null;
        }
        return null;
    }
}
