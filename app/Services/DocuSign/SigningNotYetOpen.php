<?php

namespace App\Services\DocuSign;

use RuntimeException;

/** A signing link asked for before this signer's turn in the signing order. */
class SigningNotYetOpen extends RuntimeException {}
