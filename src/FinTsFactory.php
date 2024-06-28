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
        string $instanceFilePath = null
    ): FinTs {
        $options = self::createOptions($url, $bankCode, $productName, $productVersion);
        $credentials = Credentials::create($userName, $pin);

        $finTs = FinTs::new($options, $credentials);
        $finTs->selectTanMode($tanMode, $tanMedium);

        if (is_string($instanceFilePath)
            && $instanceFilePath !== ''
            && file_exists($instanceFilePath)
            && filesize($instanceFilePath) > 0
        ) {
            $finTs->loadPersistedInstance(base64_decode(file_get_contents($instanceFilePath), true));
        }

        return $finTs;
    }

    public static function createOptions(
        string $url,
        string $bankCode,
        string $productName,
        string $productVersion
    ): FinTsOptions {
        $options = new FinTsOptions();
        $options->url = $url;
        $options->bankCode = $bankCode;
        $options->productName = $productName;
        $options->productVersion = $productVersion;
        $options->validate();

        return $options;
    }
}
