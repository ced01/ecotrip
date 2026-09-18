<?php
namespace App\Provider;
/** Technical details remain server-side; never expose getMessage() in the API. */
final class ProviderUnavailable extends \RuntimeException {}
