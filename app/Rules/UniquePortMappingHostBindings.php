<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects port mappings that publish the same host binding (IP, host port and protocol) twice,
 * e.g. "8080:80,8080:81". Docker would refuse to start such a container.
 */
class UniquePortMappingHostBindings implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $hostBindings = [];
        foreach (explode(',', $value) as $portMapping) {
            $hostBinding = self::hostBinding($portMapping);
            if (is_null($hostBinding)) {
                continue;
            }
            if (in_array($hostBinding, $hostBindings, true)) {
                $fail('Each host port can only be mapped once per IP and protocol.');

                return;
            }
            $hostBindings[] = $hostBinding;
        }
    }

    /**
     * Get the host side of a port mapping, e.g. "10.0.0.5:8080/tcp" for "10.0.0.5:8080:80".
     *
     * Returns null when the mapping has no fixed host port (Docker picks a random one).
     */
    private static function hostBinding(string $portMapping): ?string
    {
        $protocol = preg_match('/\/(tcp|udp|sctp)/', $portMapping, $matches) ? $matches[1] : 'tcp';
        $portMapping = preg_replace('/\/(tcp|udp|sctp)/', '', trim($portMapping));
        $containerPortSeparator = strrpos($portMapping, ':');
        if ($containerPortSeparator === false) {
            return null;
        }
        $hostSide = substr($portMapping, 0, $containerPortSeparator);
        if ($hostSide === '' || str_ends_with($hostSide, ':')) {
            return null;
        }

        return "{$hostSide}/{$protocol}";
    }
}
