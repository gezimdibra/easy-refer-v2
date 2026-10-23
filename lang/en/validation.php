<?php

/*
 | Validation language lines. Only the password rule family is
 | overridden, in the product's plain language (F-03); every other
 | key falls back to the framework file. The password rule chain
 | (App\Support\PasswordPolicy) is shared by signup, password
 | change, and reset, so its failures speak the same way on all
 | three surfaces. AUTH-UP-03 states the two rules to the user:
 | at least 12 characters, and never a password used anywhere
 | else; the uncompromised line below is that second rule failing.
 */

return [
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'That password has appeared in a data leak. Choose a different password.',
    ],
];
