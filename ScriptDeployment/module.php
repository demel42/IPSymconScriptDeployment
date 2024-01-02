<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class ScriptDeployment extends IPSModule
{
    use ScriptDeployment\StubsCommonLib;
    use ScriptDeploymentLocalLib;

    private static $semaphoreTM = 5 * 1000;

    private $SemaphoreID;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonContruct(__DIR__);
        $this->SemaphoreID = __CLASS__ . '_' . $InstanceID;
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('url', '');
        $this->RegisterPropertyString('user', '');
        $this->RegisterPropertyString('password', '');
        $this->RegisterPropertyString('token', '');
        $this->RegisterPropertyInteger('port', '22');
        $this->RegisterPropertyString('branch', 'main');
        $this->RegisterPropertyString('git_user_name', 'IP-Symcon');
        $this->RegisterPropertyString('git_user_email', '');
        $this->RegisterPropertyString('path', '');

        $this->RegisterPropertyString('update_time', '{"hour":0,"minute":0,"second":0}');

        $this->RegisterAttributeString('commit', '');

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('CheckTimer', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "PerformCheck", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->SetCheckTimer();
        }
    }

    private function CheckModulePrerequisites()
    {
        $r = [];

        $output = '';
        if ($this->execute('git --version 2>&1', $output) == false) {
            $r[] = $this->Translate('missing "git"');
        }

        return $r;
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $url = $this->ReadPropertyString('url');
        if ($url == '') {
            $this->SendDebug(__FUNCTION__, '"url" is missing', 0);
            $r[] = $this->Translate('Git-Repository must be specified');
        }

        $path = $this->ReadPropertyString('path');
        if ($path == '') {
            $this->SendDebug(__FUNCTION__, '"path" is missing', 0);
            $r[] = $this->Translate('Local path must be specified');
        }

        return $r;
    }

    private function CheckModuleUpdate(array $oldInfo, array $newInfo)
    {
        $r = [];

        return $r;
    }

    private function CompleteModuleUpdate(array $oldInfo, array $newInfo)
    {
        return '';
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainReferences();

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('CheckTimer', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('CheckTimer', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('CheckTimer', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $vpos = 1;

        $this->MaintainVariable('Timestamp', $this->Translate('Timestamp of last adjustment'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainTimer('CheckTimer', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->SetCheckTimer();
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Script deployment');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance',
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'name'    => 'url',
                    'type'    => 'ValidationTextBox',
                    'width'   => '80%',
                    'caption' => 'Git-Repository',
                ],
                [
                    'name'    => 'branch',
                    'type'    => 'ValidationTextBox',
                    'caption' => ' ... Branch',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'for http/https and ssh'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'user',
                    'caption' => ' ... User'
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'token',
                    'caption' => ' ... Personal access token'
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'for http/https only'
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'password',
                    'caption' => ' ... Password'
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'for ssh only'
                ],
                [
                    'name'    => 'port',
                    'type'    => 'NumberSpinner',
                    'minimum' => 0,
                    'caption' => ' ... Port'
                ],
            ],
            'caption' => 'Repository remote configuration'
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'Label',
                    'caption' => 'Informations for git config ...'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'git_user_name',
                    'caption' => ' ... user.name'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'git_user_email',
                    'caption' => ' ... user.email'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'path',
                    'caption' => 'local path'
                ],
            ],
            'caption' => 'Repository local configuration'
        ];

        $formElements[] = [
            'name'    => 'update_time',
            'type'    => 'SelectTime',
            'caption' => 'Time for the cyclical check',
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Perform check',
            'onClick' => 'IPS_RequestAction($id, "PerformCheck", "");',
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded'  => false,
            'items'     => [
                $this->GetInstallVarProfilesFormItem(),
            ],
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Test area',
            'expanded'  => false,
            'items'     => [
                [
                    'type'    => 'TestCenter',
                ],
            ]
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function SetCheckTimer()
    {
        $now = time();
        $update_time = json_decode($this->ReadPropertyString('update_time'), true);
        $next_tstamp = $now + $update_time['hour'] * 3600 + $update_time['minute'] * 60 + $update_time['second'];
        if ($next_tstamp <= $now) {
            $next_tstamp += 86400;
        }
        $sec = $next_tstamp - $now;
        $this->MaintainTimer('CheckTimer', $sec * 1000);
    }

    private function BuildGitUrl()
    {
        $url = $this->ReadPropertyString('url');
        $user = $this->ReadPropertyString('user');
        $token = $this->ReadPropertyString('token');
        $password = $this->ReadPropertyString('password');
        $port = $this->ReadPropertyInteger('port');

        $auth = '';
        if ($user != '') {
            $auth = rawurlencode($user);
            if ($token != '') {
                $auth .= ':';
                $auth .= $token;
            } elseif ($password != '') {
                $auth .= ':';
                $auth .= rawurlencode($password);
            }
        }

        if (substr($url, 0, 8) == 'https://') {
            $s = substr($url, 8);
            $url = 'https://';
            if ($auth != '') {
                $url .= $auth;
                $url .= '@';
            }
            $url .= $s;
        }
        if (substr($url, 0, 7) == 'http://') {
            $s = substr($url, 7);
            $url = 'http://';
            if ($auth != '') {
                $url .= $auth;
                $url .= '@';
            }
            $url .= $s;
        }
        if (substr($url, 0, 6) == 'ssh://' && $port != '') {
            $s = substr($url, 6);
            $pos = strpos($s, '/');
            $srv = substr($s, 0, $pos);
            $path = substr($s, $pos);
            $url = 'ssh://';
            if ($user != '') {
                $url .= rawurlencode($user);
                $url .= '@';
            }
            $url .= $srv;
            if ($port != '') {
                if ($port != '') {
                    $url .= ':';
                    $url .= $port;
                }
            }
            $url .= $path;
        }

        return $url;
    }

    private function SyncRepository($path, $commit)
    {
        $basePath = $this->ReadPropertyString('path');

        $repo_delete = false;
        $repo_clone = true;

        if (file_exists($path)) {
            if (is_dir($path) == false) {
                $repo_delete = true;
            } elseif ($this->changeDir($path) == false) {
                $repo_delete = true;
            } elseif ($this->execute('git status 2>&1', $output) == false) {
                $repo_delete = true;
            } else {
                $repo_clone = false;
            }
        }

        if ($this->changeDir($basePath) == false) {
            return false;
        }

        if ($repo_delete) {
            if ($this->rmeDir($path)) {
                return false;
            }
            if ($this->makeDir($path) == false) {
                return false;
            }
        }

        if ($repo_clone) {
            if ($this->execute('git clone ' . $this->BuildGitUrl() . ' ' . $path . ' 2>&1', $output) == false) {
                return false;
            }
            if ($this->changeDir($path) == false) {
                return false;
            }
        } else {
            if ($this->changeDir($path) == false) {
                return false;
            }
            if ($this->execute('git pull 2>&1', $output) == false) {
                return false;
            }
        }

        return true;
    }

    private function PerformCheck()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'repository is locked', 0);
            return;
        }

        $url = $this->ReadPropertyString('url');
        $path = $this->ReadPropertyString('path');

        $this->SendDebug(__FUNCTION__, 'url=' . $url . ', path=' . $path, 0);

        $basePath = $path . DIRECTORY_SEPARATOR . basename($url, '.git');

        $dirs = [$path, $basePath];
        foreach ($dirs as $dir) {
            if ($this->checkDir($dir, true) == false) {
                return false;
            }
        }

        $headPath = $basePath . DIRECTORY_SEPARATOR . 'head';
        $currentPath = $basePath . DIRECTORY_SEPARATOR . 'current';
        $commit = $this->ReadAttributeString('commit');

        if ($this->SyncRepository($headPath, '') == false) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }

        if ($this->execute('git symbolic-ref --short HEAD 2>&1', $output) == false) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        $curBranch = $output[0];

        if ($this->execute('git branch -r 2>&1', $output) == false) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        $allBranch = [];
        foreach ($output as $s) {
            if (substr($s, 2, 11) == 'origin/HEAD') {
                continue;
            }
            $allBranch[] = substr($s, 9);
        }
        $this->SendDebug(__FUNCTION__, 'curBranch=' . $curBranch . ', allBranch=' . print_r($allBranch, true), 0);

        $branch = $this->ReadPropertyString('branch');
        if ($branch != '' && $curBranch != $branch) {
            if ($this->execute('git checkout ' . $branch . ' 2>&1', $output) == false) {
                IPS_SemaphoreLeave($this->SemaphoreID);
                return false;
            }
        }
        /*
        $name = $this->ReadPropertyString('git_user_name');
        if (!$this->execute('git config user.name "' . $name . '"', $output)) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        $email = $this->ReadPropertyString('git_user_email');
        if (!$this->execute('git config user.email "' . $email . '"', $output)) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
         */

        // commit-id = "git rev-parse HEAD"

        $this->SetValue('Timestamp', time());
        $this->SetCheckTimer();

        IPS_SemaphoreLeave($this->SemaphoreID);
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'PerformCheck':
                $this->PerformCheck();
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', value=' . $value, 0);

        $r = false;
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
        }
    }

    private function execute($cmd, &$output)
    {
        $this->SendDebug(__FUNCTION__, $cmd, 0);

        $time_start = microtime(true);
        $data = exec($cmd, $out, $exitcode);
        $duration = round(microtime(true) - $time_start, 2);

        foreach ($out as $s) {
            $this->SendDebug(__FUNCTION__, '  ' . $s, 0);
        }
        $this->SendDebug(__FUNCTION__, '  ' . $data, 0);

        if ($exitcode) {
            $this->SendDebug(__FUNCTION__, ' ... failed with exitcode=' . $exitcode, 0);

            $output = '';
            return false;
        }

        $output = $out;
        return true;
    }

    private function checkDir($path, $autoCreate)
    {
        if (file_exists($path)) {
            if (is_dir($path) == false) {
                $this->SendDebug(__FUNCTION__, $path . ' is not a directory', 0);
                return false;
            }
        } else {
            if ($autoCreate == false) {
                $this->SendDebug(__FUNCTION__, 'missing directory ' . $path, 0);
                return false;
            }
            if (mkdir($path) == false) {
                $this->SendDebug(__FUNCTION__, 'unable to create directory ' . $path, 0);
                return false;
            }
        }
        return true;
    }

    private function changeDir($path)
    {
        if (chdir($path) == false) {
            $this->SendDebug(__FUNCTION__, 'can\'t change to direactory ' . $path, 0);
            return false;
        }
        return true;
    }

    private function makeDir($path)
    {
        if (mkdir($path) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to create directory ' . $path, 0);
            return false;
        }
        return true;
    }

    private function rmDir($path)
    {
        if ($this->execute('/bin/rm -rf ' . $headPath . ' 2>&1', $output) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to remove complete directory ' . $path, 0);
            return false;
        }
        return true;
    }
}
