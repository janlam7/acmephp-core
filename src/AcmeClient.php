<?php

/*
 * This file is part of the ACME PHP library.
 *
 * (c) Titouan Galopin <galopintitouan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AcmePhp\Core;

use AcmePhp\Core\Exception\AcmeCoreClientException;
use AcmePhp\Core\Exception\AcmeCoreServerException;
use AcmePhp\Core\Exception\Protocol\CertificateRequestFailedException;
use AcmePhp\Core\Exception\Protocol\CertificateRequestTimedOutException;
use AcmePhp\Core\Exception\Protocol\HttpChallengeNotSupportedException;
use AcmePhp\Core\Exception\Protocol\HttpChallengeTimedOutException;
use AcmePhp\Core\Http\SecureHttpClient;
use AcmePhp\Core\Protocol\Challenge;
use AcmePhp\Core\Protocol\ResourcesDirectory;
use AcmePhp\Ssl\Certificate;
use AcmePhp\Ssl\CertificateRequest;
use AcmePhp\Ssl\CertificateResponse;
use AcmePhp\Ssl\Signer\CertificateRequestSigner;
use Webmozart\Assert\Assert;

/**
 * ACME protocol client implementation.
 *
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
class AcmeClient implements AcmeClientInterface
{
    /**
     * @var SecureHttpClient
     */
    private $httpClient;

    /**
     * @var CertificateRequestSigner
     */
    private $csrSigner;

    /**
     * @var string
     */
    private $directoryUrl;

    /**
     * @var ResourcesDirectory
     */
    private $directory;

    /**
     * @param SecureHttpClient              $httpClient
     * @param string                        $directoryUrl
     * @param CertificateRequestSigner|null $csrSigner
     */
    public function __construct(SecureHttpClient $httpClient, $directoryUrl, CertificateRequestSigner $csrSigner = null)
    {
        $this->httpClient = $httpClient;
        $this->directoryUrl = $directoryUrl;
        $this->csrSigner = $csrSigner ?: new CertificateRequestSigner();
    }

    /**
     * {@inheritdoc}
     */
    public function registerAccount($agreement = null, $email = null)
    {
        Assert::nullOrString($agreement, 'registerAccount::$agreement expected a string or null. Got: %s');
        Assert::nullOrString($email, 'registerAccount::$email expected a string or null. Got: %s');

        $payload = [];
        $payload['resource'] = ResourcesDirectory::NEW_REGISTRATION;
        $payload['agreement'] = $agreement;

        if ($email) {
            $payload['contact'] = ['mailto:'.$email];
        }

        return $this->requestResource('POST', ResourcesDirectory::NEW_REGISTRATION, $payload);
    }

    /**
     * {@inheritdoc}
     */
    public function requestChallenge($domain)
    {
        Assert::string($domain, 'requestChallenge::$domain expected a string. Got: %s');

        $payload = [
            'resource'   => ResourcesDirectory::NEW_AUTHORIZATION,
            'identifier' => [
                'type'  => 'dns',
                'value' => $domain,
            ],
        ];

        $response = $this->requestResource('POST', ResourcesDirectory::NEW_AUTHORIZATION, $payload);

        if (!isset($response['challenges']) || !$response['challenges']) {
            throw new HttpChallengeNotSupportedException();
        }

        $base64encoder = $this->httpClient->getBase64Encoder();
        $keyParser = $this->httpClient->getKeyParser();
        $accountKeyPair = $this->httpClient->getAccountKeyPair();

        $parsedKey = $keyParser->parse($accountKeyPair->getPrivateKey());

        foreach ($response['challenges'] as $challenge) {
            if ('http-01' === $challenge['type']) {
                $token = $challenge['token'];

                $header = [
                    // This order matters
                    'e'   => $base64encoder->encode($parsedKey->getDetail('e')),
                    'kty' => 'RSA',
                    'n'   => $base64encoder->encode($parsedKey->getDetail('n')),
                ];

                $payload = $token.'.'.$base64encoder->encode(hash('sha256', json_encode($header), true));
                $location = $this->httpClient->getLastLocation();

                return new Challenge($domain, $challenge['uri'], $token, $payload, $location);
            }
        }

        throw new HttpChallengeNotSupportedException();
    }

    /**
     * {@inheritdoc}
     */
    public function checkChallenge(Challenge $challenge, $timeout = 180)
    {
        Assert::integer($timeout, 'checkChallenge::$timeout expected an integer. Got: %s');

        $payload = [
            'resource'         => ResourcesDirectory::CHALLENGE,
            'type'             => 'http-01',
            'keyAuthorization' => $challenge->getPayload(),
            'token'            => $challenge->getToken(),
        ];

        $response = $this->httpClient->signedRequest('POST', $challenge->getUrl(), $payload);

        // Waiting loop
        $waitingTime = 0;

        while ($waitingTime < $timeout) {
            $response = $this->httpClient->signedRequest('GET', $challenge->getLocation());

            if ('pending' !== $response['status']) {
                break;
            }

            $waitingTime++;
            sleep(1);
        }

        if ('pending' === $response['status']) {
            throw new HttpChallengeTimedOutException();
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function requestCertificate($domain, CertificateRequest $csr, $timeout = 180)
    {
        Assert::stringNotEmpty($domain, 'requestCertificate::$domain expected a non-empty string. Got: %s');
        Assert::integer($timeout, 'requestCertificate::$timeout expected an integer. Got: %s');

        $humanText = ['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----'];

        $csrContent = $this->csrSigner->signCertificateRequest($csr);
        $csrContent = trim(str_replace($humanText, '', $csrContent));
        $csrContent = trim($this->httpClient->getBase64Encoder()->encode(base64_decode($csrContent)));

        $response = $this->requestResource('POST', ResourcesDirectory::NEW_CERTIFICATE, [
            'resource' => ResourcesDirectory::NEW_CERTIFICATE,
            'csr'      => $csrContent,
        ], false);

        // If the CA has not yet issued the certificate, the body of this response will be empty
        if (strlen(trim($response)) < 10) { // 10 to avoid false results
            $location = $this->httpClient->getLastLocation();

            // Waiting loop
            $waitingTime = 0;

            while ($waitingTime < $timeout) {
                $response = $this->httpClient->unsignedRequest('GET', $location, null, false);

                if (200 === $this->httpClient->getLastCode()) {
                    break;
                }

                if (202 !== $this->httpClient->getLastCode()) {
                    throw new CertificateRequestFailedException($response);
                }

                $waitingTime++;
                sleep(1);
            }

            if (202 === $this->httpClient->getLastCode()) {
                throw new CertificateRequestTimedOutException($response);
            }
        }

        // Find issuers certificate
        $certificatesChain = null;

        foreach ($this->httpClient->getLastLinks() as $link) {
            if (!isset($link['rel']) || 'up' !== $link['rel']) {
                continue;
            }

            $location = substr($link[0], 1, -1);
            $certificate = $this->httpClient->unsignedRequest('GET', $location, null, false);

            if (strlen(trim($certificate)) > 10) {
                $pem = chunk_split(base64_encode($certificate), 64, "\n");
                $pem = "-----BEGIN CERTIFICATE-----\n".$pem."-----END CERTIFICATE-----\n";

                $certificatesChain = new Certificate($pem, $certificatesChain);
            }
        }

        // Domain certificate
        $pem = chunk_split(base64_encode($response), 64, "\n");
        $pem = "-----BEGIN CERTIFICATE-----\n".$pem."-----END CERTIFICATE-----\n";

        return new CertificateResponse($csr, new Certificate($pem, $certificatesChain));
    }

    /**
     * Request a resource (URL is found using ACME server directory).
     *
     * @param string $method
     * @param string $resource
     * @param array  $payload
     * @param bool   $returnJson
     *
     * @throws AcmeCoreServerException When the ACME server returns an error HTTP status code.
     * @throws AcmeCoreClientException When an error occured during response parsing.
     *
     * @return array|string
     */
    protected function requestResource($method, $resource, array $payload, $returnJson = true)
    {
        if (!$this->directory) {
            $this->directory = new ResourcesDirectory(
                $this->httpClient->unsignedRequest('GET', $this->directoryUrl, null, true)
            );
        }

        return $this->httpClient->signedRequest(
            $method,
            $this->directory->getResourceUrl($resource),
            $payload,
            $returnJson
        );
    }
}
