<?php

declare(strict_types=1);

trait ScriptDeploymentLocalLib
{
    private function GetFormStatus()
    {
        $formStatus = $this->GetCommonFormStatus();

        return $formStatus;
    }

    public static $STATUS_INVALID = 0;
    public static $STATUS_VALID = 1;
    public static $STATUS_RETRYABLE = 2;

    private function CheckStatus()
    {
        switch ($this->GetStatus()) {
            case IS_ACTIVE:
                $class = self::$STATUS_VALID;
                break;
            default:
                $class = self::$STATUS_INVALID;
                break;
        }

        return $class;
    }

    public static $STATE_UNKNOWN = 0;
    public static $STATE_SYNCED = 1;
    public static $STATE_UNCLEAR = 2;
    public static $STATE_MODIFIED = 3;
    public static $STATE_UPDATEABLE = 4;
    public static $STATE_FAULTY = 255;

    private function InstallVarProfiles(bool $reInstall = false)
    {
        if ($reInstall) {
            $this->SendDebug(__FUNCTION__, 'reInstall=' . $this->bool2str($reInstall), 0);
        }

        $associations = [
            ['Wert' => self::$STATE_UNKNOWN, 'Name' => $this->Translate('unknown'), 'Farbe' => -1],
            ['Wert' => self::$STATE_SYNCED, 'Name' => $this->Translate('synced'), 'Farbe' => -1],
            ['Wert' => self::$STATE_UNCLEAR, 'Name' => $this->Translate('unclear'), 'Farbe' => -1],
            ['Wert' => self::$STATE_MODIFIED, 'Name' => $this->Translate('local modified'), 'Farbe' => -1],
            ['Wert' => self::$STATE_UPDATEABLE, 'Name' => $this->Translate('updateable'), 'Farbe' => -1],
            ['Wert' => self::$STATE_FAULTY, 'Name' => $this->Translate('faulty'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('ScriptDeployment.State', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);
    }
}
