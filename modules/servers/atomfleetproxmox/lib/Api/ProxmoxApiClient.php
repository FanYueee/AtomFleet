<?php
declare(strict_types=1);

namespace AtomFleet\Whmcs\Proxmox\Api;

use AtomFleet\Whmcs\Proxmox\Exception\ModuleException;
use AtomFleet\Whmcs\Proxmox\Support\ModuleLogger;
use AtomFleet\Whmcs\Proxmox\Support\ModuleParameters;

final class ProxmoxApiClient
{
    /** @var ModuleParameters */
    private $params;

    /** @var string */
    private $baseUri;

    /** @var string */
    private $username;

    /** @var string */
    private $secret;

    /** @var bool */
    private $usesTokenAuth;

    /** @var string|null */
    private $ticket;

    /** @var string|null */
    private $csrfToken;

    public function __construct(ModuleParameters $params)
    {
        $this->params = $params;
        $this->baseUri = rtrim($params->apiBaseUri(), '/');
        $this->username = $params->serverUsername();
        $this->secret = $params->serverPassword();
        $this->usesTokenAuth = strpos($this->username, '!') !== false;
        $this->ticket = null;
        $this->csrfToken = null;

        if ($this->username === '' || $this->secret === '') {
            throw new ModuleException('Missing Proxmox server credentials in the assigned WHMCS server.');
        }
    }

    public function get(string $path, array $query = array())
    {
        return $this->request('GET', $path, array(), $query);
    }

    public function post(string $path, array $payload = array())
    {
        return $this->request('POST', $path, $payload);
    }

    public function put(string $path, array $payload = array())
    {
        return $this->request('PUT', $path, $payload);
    }

    public function delete(string $path, array $payload = array())
    {
        return $this->request('DELETE', $path, $payload);
    }

    private function request(string $method, string $path, array $payload = array(), array $query = array())
    {
        if (!$this->usesTokenAuth) {
            $this->authenticate();
        }

        $url = $this->buildUrl($path, $query);
        $headers = array('Accept: application/json');

        if ($this->usesTokenAuth) {
            $headers[] = 'Authorization: PVEAPIToken=' . $this->username . '=' . $this->secret;
        } else {
            $headers[] = 'Cookie: PVEAuthCookie=' . $this->ticket;

            if ($method !== 'GET' && $this->csrfToken) {
                $headers[] = 'CSRFPreventionToken: ' . $this->csrfToken;
            }
        }

        if ($method !== 'GET') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
        }

        if ($method !== 'GET' && !empty($payload)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($payload, '', '&'));
        }

        $rawResponse = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($rawResponse === false) {
            throw new ModuleException('Unable to contact Proxmox API: ' . $curlError);
        }

        $decoded = json_decode($rawResponse, true);

        if ($statusCode >= 400) {
            throw new ModuleException($this->extractErrorMessage($decoded, $rawResponse, $statusCode));
        }

        if (is_array($decoded) && array_key_exists('errors', $decoded) && !empty($decoded['errors'])) {
            throw new ModuleException($this->extractErrorMessage($decoded, $rawResponse, $statusCode));
        }

        ModuleLogger::log(
            'API ' . $method . ' ' . $path,
            array(
                'serverusername' => $this->username,
                'serverpassword' => $this->secret,
            ),
            array('url' => $url, 'payload' => $payload),
            $decoded
        );

        if (is_array($decoded) && array_key_exists('data', $decoded)) {
            return $decoded['data'];
        }

        return $decoded;
    }

    private function authenticate(): void
    {
        if ($this->ticket !== null) {
            return;
        }

        $url = $this->buildUrl('/access/ticket');
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array(
            'username' => $this->username,
            'password' => $this->secret,
        ), '', '&'));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ));
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

        $rawResponse = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($rawResponse === false) {
            throw new ModuleException('Unable to authenticate with Proxmox: ' . $curlError);
        }

        $decoded = json_decode($rawResponse, true);

        if ($statusCode >= 400 || !isset($decoded['data']['ticket'])) {
            throw new ModuleException($this->extractErrorMessage($decoded, $rawResponse, $statusCode));
        }

        $this->ticket = $decoded['data']['ticket'];
        $this->csrfToken = isset($decoded['data']['CSRFPreventionToken'])
            ? $decoded['data']['CSRFPreventionToken']
            : null;
    }

    private function buildUrl(string $path, array $query = array()): string
    {
        $url = $this->baseUri . '/' . ltrim($path, '/');

        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    private function extractErrorMessage($decoded, string $rawResponse, int $statusCode): string
    {
        if (is_array($decoded)) {
            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                return 'Proxmox API error: ' . implode('; ', array_map('strval', $decoded['errors']));
            }

            if (!empty($decoded['data']) && is_string($decoded['data'])) {
                return 'Proxmox API error: ' . $decoded['data'];
            }

            if (!empty($decoded['message']) && is_string($decoded['message'])) {
                return 'Proxmox API error: ' . $decoded['message'];
            }
        }

        $trimmed = trim($rawResponse);

        if ($trimmed !== '') {
            return 'Proxmox API error (' . $statusCode . '): ' . $trimmed;
        }

        return 'Proxmox API error (' . $statusCode . ').';
    }
}
