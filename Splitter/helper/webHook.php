<?php

/** @noinspection PhpMissingReturnTypeInspection */
/** @noinspection PhpUndefinedFunctionInspection */

declare(strict_types=1);

trait webHook
{
    ##### Protected

    /**
     * Processes the hook data which will be called by the hook control.
     *
     * @throws Exception
     */
    protected function ProcessHookData()
    {
        $this->SendDebug(__FUNCTION__, 'Incoming data: ' . print_r($_SERVER, true), 0);
        if (!isset($_GET['code'])) {
            $this->SendDebug(__FUNCTION__, 'No authorization code received!', 0);
            http_response_code(400);
            echo 'Error: No authorization code received!';
            return;
        }
        $authCode = $_GET['code'];
        $state = $_GET['state'] ?? '';
        $this->SendDebug(__FUNCTION__, 'Auth Code: ' . $authCode . ' , State: ' . $state, 0);
        if ($this->RequestAccessToken($authCode)) {
            echo $this->Translate('Registration successful!');
        }
    }

    ##### Private

    /**
     * Registers a webhook to the WebHook Control.
     *
     * @param string $WebHook
     * @return void
     */
    private function RegisterWebHook(string $WebHook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::CORE_WEBHOOK_GUID);
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $WebHook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
                $this->SendDebug(__FUNCTION__, 'WebHook was successfully registered.', 0);
            }
            IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }

    /**
     * Creates the Redirect URI.
     *
     * @return void
     * @throws Exception
     */
    private function CreateRedirectURI(): void
    {
        $redirectURI = $this->ReadAttributeString('RedirectURI');
        $this->SendDebug(__FUNCTION__, 'Saved Redirect URI: ' . $redirectURI, 0);
        if (!empty($redirectURI)) {
            return;
        }
        // Get ipmagic address and add webhook credentials
        $ids = IPS_GetInstanceListByModuleID(self::CORE_CONNECT_GUID);
        if (count($ids) > 0) {
            $url = CC_GetURL($ids[0]) . '/hook/' . $this->identifier; // Prepared for multiple user accounts // . '_' . $this->InstanceID;
            $this->WriteAttributeString('RedirectURI', $url);
            $this->SendDebug(__FUNCTION__, 'Redirect URI: ' . $url, 0);
        }
    }

    /**
     * Unregisters a specified webhook.
     *
     * @param string $WebHook
     * @return void
     */
    private function UnregisterWebHook(string $WebHook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::CORE_WEBHOOK_GUID);
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $found = false;
            $index = null;
            foreach ($hooks as $key => $hook) {
                if ($hook['Hook'] == $WebHook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        $found = true;
                        $index = $key;
                        break;
                    }
                }
            }
            if ($found === true && !is_null($index)) {
                array_splice($hooks, $index, 1);
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                $this->SendDebug(__FUNCTION__, 'WebHook was successfully unregistered.', 0);
            }
        }
    }
}