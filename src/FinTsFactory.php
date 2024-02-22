<?php

declare(strict_types=1);

namespace Marcus\PhpFinTsWrapper;

use Fhp\FinTs;
use Fhp\Options\Credentials;
use Fhp\Options\FinTsOptions;

class FinTsFactory
{
    public static function create(
        string $url,
        string $bankCode,
        string $productName,
        string $productVersion,
        string $userName,
        string $pin,
        int $tanMode,
        string $tanMedium,
    ): FinTs {
        $options = new FinTsOptions();
        $options->url = $url;
        $options->bankCode = $bankCode;
        $options->productName = $productName;
        $options->productVersion = $productVersion;
        $options->validate();

        $finTs = FinTs::new($options, Credentials::create($userName, $pin));
        $finTs->selectTanMode($tanMode, $tanMedium);

        return $finTs;
    }
}
