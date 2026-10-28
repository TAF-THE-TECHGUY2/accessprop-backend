<?php

return [

    /*
    | Investors must set up an authenticator, or decline it by accepting the
    | risk warning. Admins must always have one; that is not configurable.
    | Off only in the test suite, so tests unrelated to sign-in need not enrol
    | every investor they create.
    */
    'require_investors' => (bool) env('TWO_FACTOR_REQUIRE_INVESTORS', true),

    /*
    | What an investor accepts to go without it. Recorded on their activity
    | log with the time, IP and browser when they accept.
    */
    'opt_out_acknowledgement' => 'I understand and accept the risks of disabling two-factor authentication. '
        .'Access Properties LLC (AP) shall not be liable for losses or damages resulting from my decision '
        .'to disable this protection, to the extent permitted by applicable law.',

];
