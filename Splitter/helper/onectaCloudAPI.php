<?php

declare(strict_types=1);

trait onectaCloudAPI
{
    ##### Important Information #####

    // The maximum lifetime of the authorization session is 1 year.
    // After this period, the access and refresh token will expire.
    // Users will then need to re-authorize their integration to gain access again to the API.
    // Otherwise, requests will result in an HTTP 401 Unauthorized error.

    // To maintain reliability and durability, it's advisable to avoid initiating compressor start and stop operations more frequently than once every 10 minutes.

    // This rate limit configuration allows you to send a maximum of 200 requests per 24-hour window to the ONECTA Cloud API.
    // Furthermore, per minute, a maximum of 20 requests can be sent.
    // This rate limitation gives you the possibility to query the state of your devices periodically while keeping the flexibility to perform sufficient actions on your devices.

    /**
     * Gets all sites related to the user.
     *
     * @return string
     * @throws Exception
     */
    public function GetSites(): string
    {
        $endpoint = 'https://api.onecta.daikineurope.com/v1/sites';
        $result = $this->SendDataToCloudAPI($endpoint, 'GET', '');
        $this->SendDebug(__FUNCTION__, 'Result: ' . $result, 0);
        return $result;
    }

    /**
     * Gets the devices related to the user.
     *
     * @return string
     * @throws Exception
     */
    public function GetDevices(): string
    {
        $endpoint = 'https://api.onecta.daikineurope.com/v1/gateway-devices';
        $result = $this->SendDataToCloudAPI($endpoint, 'GET', '');
        $this->SendDebug(__FUNCTION__, 'Result: ' . $result, 0);
        return $result;
    }

    /**
     * Gets the management points of a device
     *
     * @param string $DeviceID
     * @return string
     * @throws Exception
     */
    public function GetDeviceManagementPoints(string $DeviceID): string
    {
        $endpoint = 'https://api.onecta.daikineurope.com/v1/gateway-devices/' . $DeviceID;
        $result = $this->SendDataToCloudAPI($endpoint, 'GET', '');
        $this->SendDebug(__FUNCTION__, 'Result: ' . $result, 0);
        return $result;
    }

    /**
     * Updates the characteristic of a device.
     *
     * @param string $DeviceID
     * @param string $EmbeddedID
     * @param string $Name
     * @param string $Body
     * @return string
     * @throws Exception
     */
    public function UpdateCharacteristic(string $DeviceID, string $EmbeddedID, string $Name, string $Body): string
    {
        $endpoint = 'https://api.onecta.daikineurope.com/v1/gateway-devices/' . $DeviceID . '/management-points/' . $EmbeddedID . '/characteristics/' . $Name;
        $this->SendDebug(__FUNCTION__, 'Endpoint: ' . $endpoint, 0);
        $this->SendDebug(__FUNCTION__, 'Body: ' . $Body, 0);
        $result = $this->SendDataToCloudAPI($endpoint, 'PATCH', $Body);
        $this->SendDebug(__FUNCTION__, 'Result: ' . $result, 0);
        return $result;
    }

    /**
     * Sends the data to the endpoint of the DAIKIN Onecta Cloud API and returns a result.
     *
     * @param string $Endpoint
     * @param string $CustomRequest
     * @param string $Postfields
     * @return string
     * @throws Exception
     */
    public function SendDataToCloudAPI(string $Endpoint, string $CustomRequest, string $Postfields): string
    {
        if (!$this->CheckStatus()) {
            return '';
        }
        $this->SendDebug(__FUNCTION__, 'Endpoint: ' . $Endpoint, 0);
        $this->SendDebug(__FUNCTION__, 'CustomRequest: ' . $CustomRequest, 0);
        $this->SendDebug(__FUNCTION__, 'Postfields: ' . $Postfields, 0);
        $accessToken = $this->GetAccessToken();
        if ($accessToken == '') {
            return '';
        }
        //Send the data to the endpoint
        $timeout = round($this->ReadPropertyInteger('Timeout') / 1000);
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: */*'
        ];
        $body = '{}';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST   => $CustomRequest,
            CURLOPT_URL             => $Endpoint,
            CURLOPT_HEADER          => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FAILONERROR     => true,
            CURLOPT_CONNECTTIMEOUT  => $timeout,
            CURLOPT_TIMEOUT         => 60,
            CURLOPT_POSTFIELDS      => $Postfields,
            CURLOPT_HTTPHEADER      => $headers]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!curl_errno($ch)) {
            $this->SendDebug(__FUNCTION__, 'Response http code: ' . $httpCode, 0);
            # 200 = OK
            if ($httpCode == 200) {
                $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $header = substr($response, 0, $header_size);
                $this->SendDebug(__FUNCTION__, 'Response header: ' . $header, 0);
                $body = substr($response, $header_size);
                $this->SendDebug(__FUNCTION__, 'Response body: ' . $body, 0);
            }
        } else {
            $error_msg = curl_error($ch);
            $this->SendDebug(__FUNCTION__, 'An error has occurred: ' . json_encode($error_msg), 0);
        }
        curl_close($ch);
        return json_encode([
            'http_code' => $httpCode,
            'body'      => $this->ConvertJsonBody($body)
        ]);
    }

    ##### Private

    private function ConvertJsonBody(string $Body): string|array
    {
        $decoded = json_decode($Body, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : $Body;
    }

    private function CheckStatus(): bool
    {
        if ($this->GetStatus() == 102) {
            return true;
        }
        $this->SendDebug(__FUNCTION__, 'Abort, Status is ' . $this->GetStatus() . ' and not 102!', 0);
        return false;
    }
}

