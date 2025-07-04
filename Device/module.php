<?php

/** @noinspection PhpRedundantMethodOverrideInspection */
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpMissingReturnTypeInspection */
/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';
include_once __DIR__ . '/../libs/helper/globalHelper.php';

class DaikinOnectaDevice extends IPSModule
{
    // Helper
    use globalHelper;
    use control;

    // Constants
    private const LIBRARY_GUID = '{7439E825-F2B1-FE03-A087-FD46E147847F}';
    private const MODULE_PREFIX = 'DODEV';
    private const DAIKIN_ONECTA_CLOUD_API_MODULE_GUID = '{6627F277-44ED-F99C-DC20-0D15465153AF}';
    private const DAIKIN_ONECTA_CLOUD_API_DATA_GUID = '{8C90E068-C3A8-F683-8FD7-29943EA18004}';

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        ##### Properties

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('DeviceModel', '');
        $this->RegisterPropertyString('ModelInfo', '');
        $this->RegisterPropertyString('SerialNumber', '');
        $this->RegisterPropertyInteger('StatusUpdateInterval', 1800);

        ##### Variables

        // Power
        $this->RegisterVariableBoolean('Power', $this->Translate('Power'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'USAGE_TYPE'   => 0,
            'ICON'         => 'power-off'], 10);
        $this->EnableAction('Power');

        // Temperature control
        $id = @$this->GetIDForIdent('TemperatureControl');
        $this->RegisterVariableFloat('TemperatureControl', $this->Translate('Temperature'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_SLIDER,
            'MIN'           => 18,
            'MAX'           => 32,
            'STEP_SIZE'     => 0.5,
            'GRADIENT_TYPE' => 1,
            'USAGE_TYPE'    => 0,
            'DIGITS'        => 1,
            'SUFFIX'        => ' °C',
            'ICON'          => 'temperature-high'], 20);
        if (!$id) {
            $this->SetValue('TemperatureControl', 18);
        }
        $this->EnableAction('TemperatureControl');

        // Device mode
        $id = @$this->GetIDForIdent('DeviceMode');
        $this->RegisterVariableInteger('DeviceMode', $this->Translate('Device mode'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => '[
                {"Value":0,"Caption":"' . $this->Translate('Cool') . '","IconActive":true,"IconValue":"snowflake","Color":' . hexdec('0000FF') . '},
                {"Value":1,"Caption":"' . $this->Translate('Heat') . '","IconActive":true,"IconValue":"heat","Color":' . hexdec('FF0000') . '},
                {"Value":2,"Caption":"' . $this->Translate('Dry') . '","IconActive":true,"IconValue":"raindrops","Color":' . hexdec('FFFFC0') . '},
                {"Value":3,"Caption":"' . $this->Translate('Fan') . '","IconActive":true,"IconValue":"fan","Color":' . hexdec('C0C0FF') . '},
                {"Value":4,"Caption":"Auto","IconActive":true,"IconValue":"air-conditioner","Color":' . hexdec('00FF00') . '}]',
            'LAYOUT' => 1,
            'ICON'   => 'gear'], 30);
        if (!$id) {
            $this->SetValue('DeviceMode', 0);
        }
        $this->EnableAction('DeviceMode');

        // Room temperature
        $this->RegisterVariableFloat('RoomTemperature', $this->Translate('Room temperature'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'USAGE_TYPE'   => 1,
            'DIGITS'       => 1,
            'SUFFIX'       => ' °C',
            'ICON'         => 'temperature-low'], 40);

        // Outdoor temperature
        $this->RegisterVariableFloat('OutdoorTemperature', $this->Translate('Outdoor temperature'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'USAGE_TYPE'   => 1,
            'DIGITS'       => 1,
            'SUFFIX'       => ' °C',
            'ICON'         => 'temperature-high'], 50);

        ##### Timer

        $this->RegisterTimer('StatusUpdate', 0, self::MODULE_PREFIX . '_UpdateStatus(' . $this->InstanceID . ');');

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

        // Check kernel runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        $this->ValidateConfiguration();
        $this->UpdateStatus();
    }

    public function Destroy()
    {
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
        return json_encode($formData);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Power':
                $this->TogglePower($Value);
                break;

            case 'TemperatureControl':
                $this->SetTemperature($Value);
                break;

            case 'DeviceMode':
                $this->SetDeviceMode($Value);
                break;
        }
    }

    ##### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    private function ValidateConfiguration(): void
    {
        $status = 102;
        if ($this->ReadPropertyString('DeviceID') == '') {
            $this->SendDebug(__FUNCTION__, 'Device ID is missing!', 0);
            $status = 201;
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SendDebug(__FUNCTION__, 'Gateway Device is inactive!', 0);
            $status = 104;
        }
        $this->SetStatus($status);
    }
}