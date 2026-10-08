<?php

namespace App\Services\Platform;

use RuntimeException;

/**
 * SaaS-5: this purge worker no longer holds the request's lease (another worker took over an
 * expired one): it stops without changing anything further.
 */
final class LeaseLost extends RuntimeException {}
