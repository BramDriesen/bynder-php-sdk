<?php

namespace Bynder\Api\Impl\PermanentTokens;

use Guzzle;

use Bynder\Api\Impl\AbstractRequestHandler;

class RequestHandler extends AbstractRequestHandler
{
    protected $configuration;
    protected $httpClient;

    public function __construct($configuration)
    {
        $this->configuration = $configuration;
        $this->httpClient = new \GuzzleHttp\Client();
    }

    protected function sendAuthenticatedRequest($requestMethod, $uri, $options = ['headers' => []])
    {
        $request = new \GuzzleHttp\Psr7\Request($requestMethod, $uri, $options);
        $updatedHeaders = ['headers' => array_merge(
            $options['headers'],
            [
                'User-Agent' => 'bynder-php-sdk/' . $this->configuration->getSdkVersion(),
                'Authorization' => 'Bearer ' . $this->configuration->getToken()
            ]
        )];
        return $this->httpClient->sendAsync(
            $request,
            array_merge(
                $options,
                $this->configuration->getRequestOptions(),
                $updatedHeaders
            )
        );
    }
}
