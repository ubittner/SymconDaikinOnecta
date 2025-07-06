<?php

declare(strict_types=1);

trait webOAuth
{
    ##### Public

    /**
     * Authorizes the application with the ONECTA Cloud API.
     *
     * @return string
     * @throws Exception
     */
    public function Register(): string
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = $this->ReadAttributeString('RedirectURI');
        $scope = $this->ReadPropertyString('Scope');
        if (empty($clientID) || empty($clientSecret)) {
            $this->SendDebug(__FUNCTION__, 'Error: Missing credentials!', 0);
            return 'Error: Missing credentials!';
        }
        $authURL = 'https://idp.onecta.daikineurope.com/v1/oidc/authorize?' .
            'response_type=code' .
            '&client_id=' . urlencode($clientID) .
            '&redirect_uri=' . urlencode($redirectURI) .
            '&scope=' . urlencode($scope);
        $this->SendDebug(__FUNCTION__, 'Authorize URL: ' . $authURL, 0);
        return $authURL;
    }

    ##### Protected

    /**
     * Requests the access and refresh tokens.
     *
     * @param string $AuthCode
     * @return bool
     * @throws Exception
     */
    protected function RequestAccessToken(string $AuthCode): bool
    {
        // Any access token you receive from the authorization server is configured to have a lifetime of one hour.
        // This means that your application needs to retrieve a new access token when the current access token is expired.
        // The refresh token is valid for 1 year and rotates each time it is used.

        $url = 'https://idp.onecta.daikineurope.com/v1/oidc/token';
        $postData = http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->ReadPropertyString('ClientID'),
            'client_secret' => $this->ReadPropertyString('ClientSecret'),
            'code'          => $AuthCode,
            'redirect_uri'  => $this->ReadAttributeString('RedirectURI'),
        ]);
        $options = [
            'http' => [
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        return $this->CacheTokens($response);
    }

    /**
     * Refreshes the access and refresh token.
     *
     * @return bool
     * @throws Exception
     */
    protected function RefreshTokens(): bool
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $refreshToken = $this->ReadAttributeString('RefreshToken');
        if (empty($clientID) || empty($clientSecret) || empty($refreshToken)) {
            $this->SendDebug(__FUNCTION__, 'Abort, credentials are missing!', 0);
            $this->LogMessage('Abort, credentials are missing!', KL_ERROR);
            return false;
        }
        $url = 'https://idp.onecta.daikineurope.com/v1/oidc/token';
        $postData = http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientID,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken
        ]);
        $options = [
            'http' => [
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        return $this->CacheTokens($response);
    }

    /**
     * Gets the actual access token.
     *
     * @return string
     * @throws Exception
     */
    protected function GetAccessToken(): string
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $expires = $this->ReadAttributeInteger('AccessTokenValidUntil');
        $refreshToken = $this->ReadAttributeString('RefreshToken');
        if ($accessToken == '' || $expires == 0 || $refreshToken == '') {
            $this->SendDebug(__FUNCTION__, 'Abort, no Access Token or Refresh Token available!', 0);
            return '';
        }
        if (time() < $expires - 10) {
            $this->SendDebug(__FUNCTION__, 'OK! Access Token is valid until: ' . date('d.m.y H:i:s', $expires), 0);
            return $accessToken;
        }
        // If we slipped here, we need to fetch the new Access Token via the Refresh Token
        $this->SendDebug(__FUNCTION__, 'Use Refresh Token to get a new Access Token!', 0);
        if ($this->RefreshTokens()) {
            return $this->ReadAttributeString('AccessToken');
        } else {
            return '';
        }
    }

    ##### Private

    /**
     * Caches the access and refresh tokens.
     *
     * @param string $Data
     * @return bool
     * @throws Exception
     */
    private function CacheTokens(string $Data): bool
    {
        $responseData = json_decode($Data, true);
        if (isset($responseData['access_token'], $responseData['refresh_token'], $responseData['expires_in'])) {
            $result = true;
            // Access token
            $this->WriteAttributeString('AccessToken', $responseData['access_token']);
            $this->SendDebug(__FUNCTION__, 'Access Token: ' . $responseData['access_token'], 0);
            $this->UpdateFormField('AccessToken', 'caption', 'Access Token: ' . substr($responseData['access_token'], 0, 20) . '.....');
            // Expires in, valid until
            $expires = time() + $responseData['expires_in'];
            $this->WriteAttributeInteger('AccessTokenValidUntil', $expires);
            $date = date('d.m.y H:i:s', $expires);
            $this->SendDebug(__FUNCTION__, 'Access Token valid until: ' . $date, 0);
            $this->UpdateFormField('AccessTokenValidUntil', 'caption', $this->Translate('Valid until') . ': ' . $date);
            // Refresh token
            $this->WriteAttributeString('RefreshToken', $responseData['refresh_token']);
            $this->SendDebug(__FUNCTION__, 'Refresh Token: ' . $responseData['refresh_token'], 0);
            $this->UpdateFormField('RefreshToken', 'caption', 'Refresh Token: ' . substr($responseData['refresh_token'], 0, 20) . '.....');
            // Log tokens
            if ($this->ReadPropertyBoolean('LogTokens')) {
                $this->LogMessage('Access Token: ' . $responseData['access_token'], KL_NOTIFY);
                $this->LogMessage('Access Token valid until: ' . $date, KL_NOTIFY);
                $this->LogMessage('Refresh Token: ' . $responseData['refresh_token'], KL_NOTIFY);
            }
        } else {
            $result = false;
            $this->SendDebug(__FUNCTION__, 'Token exchange failed!', 0);
            $this->LogMessage('Token exchange failed!', KL_ERROR);
        }
        $this->ValidateConfiguration();
        return $result;
    }
}
