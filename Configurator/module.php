<?php

/** @noinspection PhpMissingReturnTypeInspection */
/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

include_once __DIR__ . '/../libs/helper/globalHelper.php';

class DaikinOnectaConfigurator extends IPSModule
{
    // Helper
    use globalHelper;

    // Constants
    private const LIBRARY_GUID = '{7439E825-F2B1-FE03-A087-FD46E147847F}';
    private const DAIKIN_ONECTA_CLOUD_API_MODULE_GUID = '{6627F277-44ED-F99C-DC20-0D15465153AF}';
    private const DAIKIN_ONECTA_CLOUD_API_DATA_GUID = '{8C90E068-C3A8-F683-8FD7-29943EA18004}';
    private const DAIKIN_ONECTA_DEVICE_MODULE_GUID = '{3C588DDA-3D42-C1E5-402E-3BFF077468D6}';

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        ##### Splitter

        // Connect to parent (DAIKIN ONECTA Cloud API Splitter)
        $this->ConnectParent(self::DAIKIN_ONECTA_CLOUD_API_MODULE_GUID);
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Never delete this line!
        parent::ApplyChanges();
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
        // Devices
        $formData['actions'][0]['values'] = $this->GetDevices();
        return json_encode($formData);
    }

    ##### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    private function GetDevices(): array
    {
        $values = [];
        if (!$this->HasActiveParent()) {
            return $values;
        }
        // Get the devices from the DAIKIN ONECTA Cloud API
        $foundDevices = [];
        $data = [];
        $buffer = [];
        $data['DataID'] = self::DAIKIN_ONECTA_CLOUD_API_DATA_GUID;
        $buffer['Command'] = 'GetDevices';
        $buffer['Params'] = '';
        $data['Buffer'] = $buffer;
        $data = json_encode($data);
        $devices = @$this->SendDataToParent($data);
        $this->SendDebug(__FUNCTION__, 'Result: ' . $devices, 0);
        if ($this->IsStringJsonEncoded($devices)) {
            $devices = json_decode($devices, true);
            if (isset($devices['body'])) {
                $devices = $devices['body'];
            }
        }
        // Built up the array for found devices
        foreach ($devices as $device) {
            $deviceID = $this->Translate('unknown');
            if (isset($device['id'])) {
                $deviceID = $device['id'];
            }
            $deviceModel = $this->Translate('unknown');
            if (isset($device['deviceModel'])) {
                $deviceModel = $device['deviceModel'];
            }
            $modelInfo = $this->Translate('unknown');
            if (isset($device['managementPoints'][0]['modelInfo']['value'])) {
                $modelInfo = $device['managementPoints'][0]['modelInfo']['value'];
            }
            $serialNumber = $this->Translate('unknown');
            if (isset($device['managementPoints'][0]['serialNumber']['value'])) {
                $serialNumber = $device['managementPoints'][0]['serialNumber']['value'];
            }
            $foundDevices[] = ['deviceID' => $deviceID, 'deviceModel' => $deviceModel, 'modelInfo' => $modelInfo, 'serialNumber' => $serialNumber];
        }
        $this->SendDebug(__FUNCTION__, 'Found devices: ' . json_encode($foundDevices), 0);
        // Get all the instances that are connected to the configurator
        $connectedInstanceIDs = [];
        foreach (IPS_GetInstanceListByModuleID(self::DAIKIN_ONECTA_DEVICE_MODULE_GUID) as $instanceID) {
            if (IPS_GetInstance($instanceID)['ConnectionID'] === IPS_GetInstance($this->InstanceID)['ConnectionID']) {
                // Add the instance ID to a list for the given device id.
                // Even though addresses should be unique, users could break things by manually editing the settings
                $connectedInstanceIDs[IPS_GetProperty($instanceID, 'DeviceID')][] = $instanceID;
            }
        }
        $this->SendDebug(__FUNCTION__, 'Connected instance IDs: ' . json_encode($connectedInstanceIDs), 0);
        foreach ($foundDevices as $foundDevice) {
            $value = [
                'DeviceID'            => $foundDevice['deviceID'],
                'DeviceModel'         => $foundDevice['deviceModel'],
                'ModelInfo'           => $foundDevice['modelInfo'],
                'SerialNumber'        => $foundDevice['serialNumber'],
                'create'              => [
                    'moduleID'      => self::DAIKIN_ONECTA_DEVICE_MODULE_GUID,
                    'name'          => $this->Translate('DAIKIN ONECTA Device') . ' (SN: ' . $foundDevice['serialNumber'] . ')',
                    'configuration' => [
                        'DeviceID'     => (string) $foundDevice['deviceID'],
                        'DeviceModel'  => (string) $foundDevice['deviceModel'],
                        'ModelInfo'    => (string) $foundDevice['modelInfo'],
                        'SerialNumber' => (string) $foundDevice['serialNumber'],
                    ]
                ]
            ];
            if (isset($connectedInstanceIDs[$foundDevice['deviceID']])) {
                $value['instanceID'] = $connectedInstanceIDs[$foundDevice['deviceID']][0];
            }
            else {
                $value['instanceID'] = 0;
            }
            $values[] = $value;
        }
        foreach ($connectedInstanceIDs as $deviceID => $instanceIDs) {
            foreach ($instanceIDs as $index => $instanceID) {
                // The first entry for each found device id was already added as a valid value
                if (($index === 0) && (in_array($deviceID, array_column($foundDevices, 'deviceID')))) {
                    continue;
                }
                // However, if a device id is not a found device id or a device id has multiple instances, they are erroneous
                $values[] = [
                    'DeviceID'     => $deviceID,
                    'DeviceModel'  => IPS_GetProperty($instanceID, 'DeviceModel'),
                    'ModelInfo'    => IPS_GetProperty($instanceID, 'ModelInfo'),
                    'SerialNumber' => IPS_GetProperty($instanceID, 'SerialNumber'),
                    'instanceID'   => $instanceID
                ];
            }
        }
        return $values;
    }
}