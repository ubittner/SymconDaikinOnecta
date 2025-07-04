<?php

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpMissingReturnTypeInspection */
/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';

class DaikinOnectaCloudAPI extends IPSModule
{
    // Helper
    use onectaCloudAPI;
    use webHook;
    use webOAuth;

    // Constants
    private const LIBRARY_GUID = '{7439E825-F2B1-FE03-A087-FD46E147847F}';
    private const MODULE_PREFIX = 'DOCLOUD';
    private const CORE_CONNECT_GUID = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';
    private const CORE_WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    // Identifier
    private string $identifier = 'daikin_onecta';

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Properties
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('Scope', 'openid onecta:basic.integration');
        $this->RegisterPropertyInteger('Timeout', 5000);

        // Attributes
        $this->RegisterAttributeString('RedirectURI', '');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeInteger('AccessTokenValidUntil', 0);
        $this->RegisterAttributeString('RefreshToken', '');
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Never delete this line!
        parent::ApplyChanges();

        // Check kernel runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        $this->RegisterWebHook('/hook/' . $this->identifier); // Prepared for multiple user accounts // . '_' . $this->InstanceID);
        $this->CreateRedirectURI();
        $this->ValidateConfiguration();
    }

    public function Destroy()
    {
        // Unregister WebHook
        if (!IPS_InstanceExists($this->InstanceID)) {
            $this->UnregisterWebhook('/hook/' . $this->identifier); // Prepared for multiple user accounts // . '_' . $this->InstanceID);
        }

        // Never delete this line!
        parent::Destroy();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        $this->SendDebug('MessageSink', 'SenderID: ' . $SenderID . ', Message: ' . $Message, 0);
        if (!empty($Data)) {
            foreach ($Data as $key => $value) {
                $this->SendDebug(__FUNCTION__, 'Data[' . $key . '] = ' . json_encode($value), 0);
            }
        }
        if ($Message == IPS_KERNELSTARTED) {
            $this->KernelReady();
        }
    }

    public function GetConfigurationForm(): string
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        // Info
        $library = IPS_GetLibrary(self::LIBRARY_GUID);
        $formData['elements'][2]['caption'] = 'ID: ' . $this->InstanceID . ', Version: ' . $library['Version'] . '-' . $library['Build'] . ', ' . date('d.m.Y', $library['Date']);
        // Developer
        $formData['actions'][2]['items'][0]['caption'] = 'Redirect URI: ' . $this->ReadAttributeString('RedirectURI');
        $accessToken = $this->ReadAttributeString('AccessToken');
        $formData['actions'][2]['items'][1]['caption'] = $accessToken ? 'Access Token: ' . substr($accessToken, 0, 20) . '.....' : 'Access Token: ' . $this->Translate('not available') . '!';
        $validUntil = $this->ReadAttributeInteger('AccessTokenValidUntil');
        $formData['actions'][2]['items'][2]['caption'] = ($validUntil === 0) ? $this->Translate('Valid until') . ': ' . $this->Translate('not available') . '!' : $this->Translate('Valid until') . ': ' . date('d.m.y H:i:s', $validUntil);
        $refreshToken = $this->ReadAttributeString('RefreshToken');
        $formData['actions'][2]['items'][3]['caption'] = $refreshToken ? 'Refresh Token: ' . substr($refreshToken, 0, 20) . '.....' : 'Refresh Token: ' . $this->Translate('not available') . '!';

        return json_encode($formData);
    }

    public function ForwardData($JSONString): string
    {
        $this->SendDebug(__FUNCTION__, $JSONString, 0);
        $data = json_decode($JSONString);
        switch ($data->Buffer->Command) {
            case 'GetSites':
                $response = $this->GetSites();
                break;

            case 'GetDevices':
                $response = $this->GetDevices();
                break;

            case 'GetDeviceManagementPoints':
                $params = (array) $data->Buffer->Params;
                $response = $this->GetDeviceManagementPoints($params['deviceID']);
                break;

            case 'UpdateCharacteristic':
                $params = (array) $data->Buffer->Params;
                $response = $this->UpdateCharacteristic($params['deviceID'], $params['embeddedID'], $params['name'], $params['body']);
                break;

            default:
                $this->SendDebug(__FUNCTION__, 'Invalid Command: ' . $data->Buffer->Command, 0);
                $response = '';
        }
        return $response;
    }

    ##### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    private function ValidateConfiguration(): void
    {
        $status = 102;
        if ($this->ReadAttributeString('AccessToken') == '' || $this->ReadAttributeString('RefreshToken') == '') {
            $this->SendDebug(__FUNCTION__, 'Tokens are missing, please register!', 0);
            $status = 204;
        }
        if ($this->ReadPropertyString('Scope') == '') {
            $this->SendDebug(__FUNCTION__, 'Scope is missing!', 0);
            $status = 203;
        }
        if ($this->ReadPropertyString('ClientSecret') == '') {
            $this->SendDebug(__FUNCTION__, 'Client Secret is missing!', 0);
            $status = 202;
        }
        if ($this->ReadPropertyString('ClientID') == '') {
            $this->SendDebug(__FUNCTION__, 'Client ID is missing!', 0);
            $status = 201;
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            $status = 104;
        }
        $this->SetStatus($status);
    }
}