<?php

namespace App\Helpers;

class ResponseStatusCodes
{
    const INVALID_AUTH_CREDENTIAL = '99';
    const FORCE_PASSWORD_RESET = '666';
    const UNAUTHORIZED = '999';
    const PERMISSION_DENIED = '99';
    const OK = '00';
    const BAD_REQUEST = '01';
    const DUPLICATE_REQUEST = '02'; //Request has been made before by same or different user.

}
