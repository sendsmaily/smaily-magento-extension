<?php

namespace Smaily\SmailyForMagento\Model\API;

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
     * @access public
     * @return array
     */
    public function post($uri, $params = [])
    {
        return $this->makeRequest('POST', $uri, $params);
    }

    /**
     * Make the API request.
     *
     * @param string $method
     * @param string $uri
     * @param array|string $params
     * @access protected
     * @return array
     */
    protected function makeRequest($method, $uri, $params = [])
    {
        $uri = $this->baseUrl . '/' . ltrim($uri, '/');

        $ch = curl_init();

        try {
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);

            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_URL, $uri);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            }
            else {
                curl_setopt($ch, CURLOPT_URL, $uri . (strpos($uri, '?') === false ? '?' : '&') . http_build_query($params));
            }

            $response = curl_exec($ch);

            // Handle response errors.
            if ($response === false) {
                throw new \Exception("Smaily API request failed with error: ". curl_error($ch));
            }

            $json = json_decode($response, true);
            if (is_array($json) === false) {
                throw new \Exception('Received invalid response from Smaily API: ' . $response);
            }
            else if ($method === 'POST' && (int) $json['code'] !== 101) {
                throw new \Exception('Smaily API responded with: ' . $json['message']);
            }

            return $json;
        }
        finally {
            curl_close($ch);
        }
    }
}
