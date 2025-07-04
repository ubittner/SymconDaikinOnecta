<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

trait control
{
    ##### Public

    public function TogglePower(bool $State): void
    {
        $this->SendDebug(__FUNCTION__, 'State: ' . json_encode($State), 0);
        if (!$this->CheckExecution()) {
            return;
        }
        $actualState = $this->GetValue('Power');
        $this->SetValue('Power', $State);
        $value = 'off';
        if ($State) {
            $value = 'on';
        }
        $body = json_encode(['value' => $value]);
        $result = $this->UpdateCharacteristics('climateControl', 'onOffMode', $body);
        $successful = $this->IsUpdateCharacteristicSuccessful($result);
        if (!$successful) {
            $this->SetValue('Power', $actualState);
        }
    }

    public function SetTemperature(float $Temperature): void
    {
        $this->SendDebug(__FUNCTION__, 'Temperature: ' . json_encode($Temperature), 0);
        if (!$this->CheckExecution()) {
            return;
        }
        $actualTemperature = $this->GetValue('TemperatureControl');
        $this->SetValue('TemperatureControl', $Temperature);
        switch ($this->GetValue('DeviceMode')) {
            case 0: // Cool
                $body = json_encode(['path' => '/operationModes/cooling/setpoints/roomTemperature', 'value' => $Temperature]);
                break;

            case 1: // Heat
                $body = json_encode(['path' => '/operationModes/heating/setpoints/roomTemperature', 'value' => $Temperature]);
                break;

            case 4: // Auto
                $body = json_encode(['path' => '/operationModes/heating/setpoints/roomTemperature', 'value' => $Temperature]);
                break;

        }
        if (isset($body)) {
            $result = $this->UpdateCharacteristics('climateControl', 'temperatureControl', $body);
            $successful = $this->IsUpdateCharacteristicSuccessful($result);
            if (!$successful) {
                $this->SetValue('TemperatureControl', $actualTemperature);
            }
        } else {
            $this->SetValue('TemperatureControl', $actualTemperature);
        }
    }

    public function SetDeviceMode(int $Mode): void
    {
        $this->SendDebug(__FUNCTION__, 'Device mode: ' . json_encode($Mode), 0);
        if (!$this->CheckExecution()) {
            return;
        }
        $actualMode = $this->GetValue('DeviceMode');
        $this->SetValue('DeviceMode', $Mode);
        switch ($Mode) {
            case 0: // Cool
                $body = json_encode(['value' => 'cooling']);
                break;

            case 1: // Heat
                $body = json_encode(['value' => 'heating']);
                break;

            case 2: // Dry
                $body = json_encode(['value' => 'dry']);
                break;

            case 3: // Fan
                $body = json_encode(['value' => 'fanOnly']);
                break;

            case 4: // Auto
                $body = json_encode(['value' => 'auto']);
                break;
        }
        if (isset($body)) {
            $result = $this->UpdateCharacteristics('climateControl', 'operationMode', $body);
            $successful = $this->IsUpdateCharacteristicSuccessful($result);
            if (!$successful) {
                $this->SetValue('DeviceMode', $actualMode);
            }
        } else {
            $this->SetValue('DeviceMode', $actualMode);
        }
    }

    public function UpdateStatus(): void
    {
        $this->SetTimerInterval('StatusUpdate', $this->ReadPropertyInteger('StatusUpdateInterval') * 1000);

        $managementPoints = $this->GetManagementPoints();
        if ($managementPoints == '' || !$this->IsStringJsonEncoded($managementPoints)) {
            return;
        }

        $result = json_decode($managementPoints, true);
        if (!isset($result['body'])) {
            return;
        }

        $data = $result['body'];

        // [0][embeddedId] => gateway
        // [1][embeddedId] => climateControl
        // [2][embeddedId] => indoorUnit
        // [3][embeddedId] => outdoorUnit

        // Check if [1][embeddedId] is climateControl
        if (isset($data['managementPoints'][1]['embeddedId'])) {
            if ($data['managementPoints'][1]['embeddedId'] != 'climateControl') {
                $this->SendDebug(__FUNCTION__, 'Management point [1][embeddedId] is not climateControl!', 0);
                return;
            }
        }

        // Power [onOffMode]
        if (isset($data['managementPoints'][1]['onOffMode']['value'])) {
            $state = false;
            if ($data['managementPoints'][1]['onOffMode']['value'] == 'on') {
                $state = true;
            }
            $this->SendDebug(__FUNCTION__, 'Power: ' . json_encode($state), 0);
            $this->SetValue('Power', $state);
        }

        // Device mode [operationMode]
        if (isset($data['managementPoints'][1]['operationMode']['value'])) {
            $operationMode = $data['managementPoints'][1]['operationMode']['value'];
            switch ($operationMode) {
                case 'cooling':
                    $this->SetValue('DeviceMode', 0);
                    break;

                case 'heating':
                    $this->SetValue('DeviceMode', 1);
                    break;

                case 'dry':
                    $this->SetValue('DeviceMode', 2);
                    break;

                case 'fanOnly':
                    $this->SetValue('DeviceMode', 3);
                    break;

                case 'auto':
                    $this->SetValue('DeviceMode', 4);
                    break;

                default:
                    $this->SendDebug(__FUNCTION__, 'Device mode not supported!', 0);

            }
        }

        // Temperature [temperatureControl]
        if (isset($data['managementPoints'][1]['temperatureControl']['value']['operationModes'])) {
            switch ($this->GetValue('DeviceMode')) {
                case 0: // Cool [cooling]
                    if (isset($data['managementPoints'][1]['temperatureControl']['value']['operationModes']['cooling']['setpoints']['roomTemperature']['value'])) {
                        $temperature = $data['managementPoints'][1]['temperatureControl']['value']['operationModes']['cooling']['setpoints']['roomTemperature']['value'];
                        $this->SendDebug(__FUNCTION__, 'Temperature: ' . json_encode($temperature), 0);
                        $this->SetValue('TemperatureControl', $temperature);
                    }
                    break;

                case 1: // Heat [heating]
                    if (isset($data['managementPoints'][1]['temperatureControl']['value']['operationModes']['heating']['setpoints']['roomTemperature']['value'])) {
                        $temperature = $data['managementPoints'][1]['temperatureControl']['value']['operationModes']['heating']['setpoints']['roomTemperature']['value'];
                        $this->SendDebug(__FUNCTION__, 'Temperature: ' . json_encode($temperature), 0);
                        $this->SetValue('TemperatureControl', $temperature);
                    }
                    break;

                case 2: // Dry [dry]
                    $this->SendDebug(__FUNCTION__, 'Temperature for device mode [dry] is not supported!', 0);
                    break;

                case 3: // Fan [fanOnly]
                    $this->SendDebug(__FUNCTION__, 'Temperature for device mode [fanOnly] is not supported!', 0);
                    break;

                case 4: // Auto [auto]
                    if (isset($data['managementPoints'][1]['temperatureControl']['value']['operationModes']['auto']['setpoints']['roomTemperature']['value'])) {
                        $temperature = $data['managementPoints'][1]['temperatureControl']['value']['operationModes']['auto']['setpoints']['roomTemperature']['value'];
                        $this->SendDebug(__FUNCTION__, 'Temperature: ' . json_encode($temperature), 0);
                        $this->SetValue('TemperatureControl', $temperature);
                    }
                    break;

                default:
                    $this->SendDebug(__FUNCTION__, 'Device mode not supported!', 0);

            }
        }

        // Room temperature [roomTemperature]
        if (isset($data['managementPoints'][1]['sensoryData']['value']['roomTemperature']['value'])) {
            $temperature = $data['managementPoints'][1]['sensoryData']['value']['roomTemperature']['value'];
            $this->SendDebug(__FUNCTION__, 'Room temperature: ' . json_encode($temperature), 0);
            $this->SetValue('RoomTemperature', $temperature);
        }

        // Outdoor temperature [outdoorTemperature]
        if (isset($data['managementPoints'][1]['sensoryData']['value']['outdoorTemperature']['value'])) {
            $temperature = $data['managementPoints'][1]['sensoryData']['value']['outdoorTemperature']['value'];
            $this->SendDebug(__FUNCTION__, 'Outdoor temperature: ' . json_encode($temperature), 0);
            $this->SetValue('OutdoorTemperature', $temperature);
        }
    }

    public function ShowManagementPoints(): void
    {
        $managementPoints = $this->GetManagementPoints();
        if ($managementPoints == '' || !$this->IsStringJsonEncoded($managementPoints)) {
            echo 'No management points found!';
        }
        print_r(json_decode($managementPoints, true));
    }

    ##### Private

    private function GetManagementPoints(): string
    {
        $deviceID = $this->GetDeviceID();
        if ($deviceID == '') {
            return '';
        }
        return $this->SendData('GetDeviceManagementPoints', json_encode(['deviceID' => $deviceID]));
    }

    private function UpdateCharacteristics(string $EmbeddedID, string $Name, string $Body): string
    {
        $deviceID = $this->GetDeviceID();
        if ($deviceID == '') {
            return '';
        }
        return $this->SendData('UpdateCharacteristic', json_encode(['deviceID' => $deviceID, 'embeddedID' => $EmbeddedID, 'name' => $Name, 'body' => $Body]));
    }

    private function GetDeviceID(): string
    {
        return $this->ReadPropertyString('DeviceID');
    }

    private function SendData(string $Command, string $Buffer): string
    {
        if (!$this->CheckExecution()) {
            return '';
        }
        $deviceID = $this->ReadPropertyString('DeviceID');
        if ($deviceID == '' || !$this->HasActiveParent()) {
            return '';
        }
        $data = [];
        $buffer = [];
        $data['DataID'] = self::DAIKIN_ONECTA_CLOUD_API_DATA_GUID;
        $buffer['Command'] = $Command;
        $buffer['Params'] = json_decode($Buffer, true);
        $data['Buffer'] = $buffer;
        $data = json_encode($data);
        $result = @$this->SendDataToParent($data);
        $this->SendDebug(__FUNCTION__, 'Result: ' . $result, 0);
        return $result;
    }

    private function CheckExecution(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SendDebug(__FUNCTION__, 'Abort, instance is inactive', 0);
            return false;
        }
        if (!$this->HasActiveParent() || $this->GetStatus() != 102) {
            $this->SendDebug(__FUNCTION__, 'Abort, execution not valid!', 0);
            return false;
        }
        return true;
    }

    private function IsUpdateCharacteristicSuccessful(string $Result): bool
    {
        $successful = true;
        if ($Result == '' || !$this->IsStringJsonEncoded($Result)) {
            $successful = false;
        }
        $result = json_decode($Result, true);
        if (!isset($result['http_code'])) {
            $successful = false;
        }
        if ($result['http_code'] != 204) { # 204 = Resource updated successfully
            $successful = false;
        }
        return $successful;
    }
}