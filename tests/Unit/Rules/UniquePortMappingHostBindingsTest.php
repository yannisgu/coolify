<?php

use App\Rules\UniquePortMappingHostBindings;

function portMappingHostBindingsAreUnique(mixed $value): bool
{
    $valid = true;
    (new UniquePortMappingHostBindings)->validate('ports_mappings', $value, function () use (&$valid) {
        $valid = false;
    });

    return $valid;
}

it('accepts mappings that publish each host binding once', function (mixed $value) {
    expect(portMappingHostBindingsAreUnique($value))->toBeTrue();
})->with([
    'null' => null,
    'empty' => '',
    'single' => '8080:80',
    'different host ports' => '8080:80,8443:443',
    'same container port' => '8080:80,8081:80',
    'several ports on the same IP' => '10.0.0.5:80:80,10.0.0.5:443:443',
    'same port on different IPs' => '10.0.0.5:80:80,10.0.0.6:80:80',
    'same port on IPv4 and IPv6' => '10.0.0.5:80:80,[::1]:80:80',
    'same port with different protocols' => '5353:53/tcp,5353:53/udp',
    'random host ports' => '127.0.0.1::80,127.0.0.1::81',
    'container port only' => ':80,:81',
]);

it('rejects mappings that publish a host binding twice', function (string $value) {
    expect(portMappingHostBindingsAreUnique($value))->toBeFalse();
})->with([
    'same host port' => '8080:80,8080:81',
    'same IP and port' => '10.0.0.5:8080:80,10.0.0.5:8080:81',
    'same IPv6 address and port' => '[::1]:8080:80,[::1]:8080:81',
    'same port and protocol' => '5353:53/udp,5353:54/udp',
    'tcp is the default protocol' => '5353:53,5353:54/tcp',
    'same port range' => '8000-8010:8000-8010,8000-8010:9000-9010',
]);
