<?php

namespace Smaily\SmailyForMagento\Model\HTTP;

class Client
{
    protected $baseUrl;
    private $username;
    private $password;

    /**
     * Set base URL.
     *
     * @param string $url
     * @access public
     * @return Client
     */
    public function setBaseUrl($url)
    {
        $this->baseUrl = rtrim($url, '/');
        return $this;
    }

    /**
     * Set Basic-Auth credentials.
     *
     * @param string $username
     * @param string $password
     * @return Client
     */
    public function setCredentials($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
        return $this;
    }

    /**
     * Make a GET request.
     *
     * @param string $uri
     * @param array $params
     * @access public
     * @return array
     */
    public function get($uri, $params = [])
    {
        return $this->makeRequest('GET', $uri, $params);
    }

    /**
     * Make a POST request.
     *
     * @param string $uri
     * @param array $params
     * @param boolean $json
     * @access public
     * @return array
     */
    public function post($uri, $params = [], $json = true)
    {
        return $this->makeRequest('POST', $uri, $params, $json);
    }

    /**
     * Make the API request.
     *
     * @param string $method
     * @param string $uri
     * @param array|string $params
     * @param boolean $json
     * @access protected
     * @return array
     */
    protected function makeRequest($method, $uri, $params = [], $json = true)
    {
        $uri = $this->baseUrl . '/' . ltrim($uri, '/');

        $ch = curl_init();

        try {
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);

            // Prepare for sending JSON payload.
            if ($json === true) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                ]);
            }

            // Setup authentication.
            if (!empty($this->username) || !empty($this->password)) {
                curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
            }

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_URL, $uri);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json === true ? json_encode($params) : http_build_query($params));
            } else {
                $query = (strpos($uri, '?') === false ? '?' : '&') . http_build_query($params);
                curl_setopt($ch, CURLOPT_URL, $uri . $query);
            }

            $response = curl_exec($ch);

            // Handle response errors.
            if ($response === false) {
                throw new ClientException(
                    "HTTP request failed with error: " . curl_error($ch),
                    (int) curl_getinfo($ch, CURLINFO_HTTP_CODE)
                );
            }

            $json = json_decode($response, true);
            if (is_array($json) === false) {
                throw new ClientException('Received invalid response: ' . $response, 200);
            }

            return $json;
        } finally {
            curl_close($ch);
        }
    }
}
