<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Spec Section 8.2: passwords are hashed with Argon2id at OWASP
    | parameters (memory at least 19 MiB, iterations at least 2,
    | parallelism 1). The parameters live in env so they can be raised
    | after benchmarking on the production host without a code change.
    |
    */

    'driver' => 'argon2id',

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Kept only so any legacy hash presented at login can be verified and
    | then upgraded by the rehash-on-login path. New hashes never use it.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    */

    'argon' => [
        'memory' => env('ARGON_MEMORY', 19456),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 2),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash on Login
    |--------------------------------------------------------------------------
    */

    'rehash_on_login' => true,

];
