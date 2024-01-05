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
        $this->RegisterAttributeString('branches', json_encode([]));
        $this->RegisterAttributeString('files', json_encode([]));

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

        $fmt = sprintf('d.m.Y %02d:%02d:%02d', (int) $update_time['hour'], (int) $update_time['minute'], (int) $update_time['second']);
        $next_tstamp = strtotime(date($fmt, $now));
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

    private function SyncRepository($basePath, $subdir, $branch, $commit)
    {
        $this->SendDebug(__FUNCTION__, 'subdir=' . $subdir . ', branch=' . $branch . ', commit=' . $commit, 0);

        $path = $basePath . DIRECTORY_SEPARATOR . $subdir;

        $repo_delete = false;
        $repo_clone = true;

        if (file_exists($path)) {
            if (is_dir($path) == false) {
                $repo_delete = true;
            } elseif ($this->changeDir($path) == false) {
                $repo_delete = true;
            } elseif ($this->execute('git status --short 2>&1', $output) == false || count($output) > 0) {
                $repo_delete = true;
            } else {
                $repo_clone = false;
            }
        }

        $this->SendDebug(__FUNCTION__, 'repo_delete=' . $this->bool2str($repo_delete) . ', repo_clone=' . $this->bool2str($repo_clone), 0);

        if ($this->changeDir($basePath) == false) {
            return false;
        }

        if ($repo_delete) {
            $this->SendDebug(__FUNCTION__, 'remove directory ' . $path, 0);
            if ($this->rmDir($path) == false) {
                return false;
            }
        }

        if (file_exists($path) == false) {
            if ($this->makeDir($path) == false) {
                return false;
            }
        }

        if ($repo_clone) {
            if ($this->execute('git clone ' . $this->BuildGitUrl() . ' ' . $path . ' 2>&1', $output) == false) {
                return false;
            }
        }

        if ($this->changeDir($path) == false) {
            return false;
        }

        if ($commit != '') {
            if ($this->execute('git rev-parse HEAD 2>&1', $output) == false) {
                return false;
            }
            $curCommit = $output[0];
            if ($curCommit != $commit) {
                $this->execute('git config advice.detachedHead false', $output);
                if ($this->execute('git checkout ' . $commit . ' 2>&1', $output) == false) {
                    return false;
                }
            }
        } else {
            if ($this->execute('git symbolic-ref --short HEAD 2>&1', $output) == false) {
                return false;
            }
            $curBranch = $output[0];
            if ($curBranch != $branch) {
                if ($this->execute('git checkout ' . $branch . ' 2>&1', $output) == false) {
                    return false;
                }
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

        $topPath = $basePath . DIRECTORY_SEPARATOR . 'top';
        $curPath = $basePath . DIRECTORY_SEPARATOR . 'cur';
        $branch = $this->ReadPropertyString('branch');
        if ($branch == '') {
            $branch = 'main';
        }
        $commit = $this->ReadAttributeString('commit');

        if ($this->SyncRepository($basePath, 'top', $branch, '') == false) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($this->changeDir($topPath) == false) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }

        // xxxx das muss noch woanders in (ApplyChanges?)
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
        $this->WriteAttributeString('branches', json_encode($allBranch));
        $this->SendDebug(__FUNCTION__, 'allBranch=' . implode(', ', $allBranch), 0);
        if (in_array($branch, $allBranch) == false) {
            $this->SendDebug(__FUNCTION__, 'unknown branch "' . $branch . '" -> ignore', 0);
            $branch == '';
        }
        // xxxx

        if ($this->execute('git diff --stat ' . $commit . ' 2>&1', $output) == false) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        $changedFiles = [];
        foreach ($output as $s) {
            if (preg_match('/[ ]*files\/([^ ]*)[ ]*\| .*/', $s, $r)) {
                $changedFiles[] = $r[1];
            }
        }
        $this->SendDebug(__FUNCTION__, 'changedFiles=' . print_r($changedFiles, true), 0);

        $topDict = $this->readDictonary($topPath);
        if ($topDict === false) {
            $this->SendDebug(__FUNCTION__, 'no valid top-dictionary', 0);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'top-dictionary=' . print_r($topDict, true), 0);

        if ($this->SyncRepository($basePath, 'cur', $branch, $commit) == false) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($this->changeDir($curPath) == false) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($commit == '') {
            if ($this->execute('git rev-parse HEAD 2>&1', $output) == false) {
                IPS_SemaphoreLeave($this->SemaphoreID);
                return false;
            }
            $curCommit = $output[0];
            $this->SendDebug(__FUNCTION__, 'curCommit=' . $curCommit, 0);
            $this->WriteAttributeString('commit', $curCommit);
        }

        $curDict = $this->readDictonary($curPath);
        if ($curDict === false) {
            $this->SendDebug(__FUNCTION__, 'no valid cur-dictionary', 0);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'cur-dictionary=' . print_r($curDict, true), 0);

        $curFiles = $curDict['files'];
        $topFiles = $topDict['files'];

        $s = $this->ReadAttributeString('files');
        $oldFiles = json_decode($s, true);

        $dflt = [
            'ident'    => '',
            'name'     => '',
            'location' => '',
            'id'       => 0,
            'removed'  => false,
            'added'    => false,
            'moved'    => false,
            'orphan'   => false,
            'missing'  => false,
            'modified' => false,
            'outdated' => false,
        ];

        $newFiles = [];
        foreach ($curFiles as $curFile) {
            $fnd = false;
            foreach ($oldFiles as $oldFile) {
                if ($curFile['ident'] == $oldFile['ident']) {
                    $fnd = true;
                    break;
                }
            }
            $newFile = $dflt;
            $newFile['ident'] = $curFile['ident'];
            $newFile['name'] = $curFile['name'];
            $newFile['location'] = $curFile['location'];
            if ($fnd) {
                $newFile['id'] = $oldFile['id'];
                if (in_array($curFile['ident'], $changedFiles)) {
                    $newFile['outdated'] = true;
                }
            } else {
                $newFile['missing'] = true;
            }
            $newFiles[] = $newFile;
        }
        foreach ($oldFiles as $oldFile) {
            $objID = $oldFile['id'];
            if ($objID == 0) {
                continue;
            }

            $fnd = false;
            foreach ($curFiles as $curFile) {
                if ($curFile['ident'] == $oldFile['ident']) {
                    $fnd = true;
                    break;
                }
            }
            if ($fnd == false) {
                $newFile = $dflt;
                $newFile['ident'] = $oldFile['ident'];
                $newFile['name'] = $oldFile['name'];
                $newFile['location'] = $oldFile['location'];
                $newFile['id'] = $oldFile['id'];
                $newFile['removed'] = true;
                $newFiles[] = $newFile;
            }
        }
        foreach ($topFiles as $topFile) {
            $fnd = false;
            foreach ($newFiles as $newFile) {
                if ($topFile['ident'] == $newFile['ident']) {
                    $fnd = true;
                    break;
                }
            }
            if ($fnd == false) {
                $newFile = $dflt;
                $newFile['ident'] = $topFile['ident'];
                $newFile['name'] = $topFile['name'];
                $newFile['location'] = $topFile['location'];
                $newFile['added'] = true;
                $newFiles[] = $newFile;
            }
        }

        $location2parents = [];
        foreach ($newFiles as $newFile) {
            $location = $newFile['location'];
            $path = explode('\\', $location);
            $objID = 0;
            $parents = [$objID];
            foreach ($path as $part) {
                $objID = IPS_GetObjectIDByName($part, $objID);
                if ($objID == false) {
                    $parents = [];
                    break;
                }
                $parents[] = $objID;
            }
            $location2parents[$location] = array_reverse($parents);
        }
        $this->SendDebug(__FUNCTION__, 'location2parents=' . print_r($location2parents, true), 0);

        foreach ($newFiles as $index => $newFile) {
            if ($newFile['missing'] == false) {
                continue;
            }

            $location = $newFile['location'];
            $parents = $location2parents[$location];
            if (count($parents) == 0) {
                continue;
            }

            $parID = $parents[0];
            $ident = $newFile['ident'];
            $objID = @IPS_GetObjectIDByIdent($ident, $parID);
            if ($objID == false) {
                $name = $newFile['name'];
                $objID = @IPS_GetObjectIDByName($name, $parID);
            }
            if ($objID) {
                $newFile['id'] = $objID;
                $newFile['missing'] = false;
                $newFiles[$index] = $newFile;
                continue;
            }
        }

        foreach ($newFiles as $index => $newFile) {
            $objID = $newFile['id'];
            if ($objID == 0) {
                continue;
            }

            if (IPS_ObjectExists($objID) == false) {
                $newFile['id'] = 0;
                $newFile['missing'] = true;
                $newFiles[$index] = $newFile;
                continue;
            }

            $parID = IPS_GetParent($objID);

            $location = $newFile['location'];
            $parents = $location2parents[$location];
            if (count($parents) == 0) {
                $newFile['orphan'] = true;
                $newFiles[$index] = $newFile;
                continue;
            }
            if ($parents[0] != $parID) {
                $newFile['moved'] = true;
                $newFiles[$index] = $newFile;
                continue;
            }
        }

        $this->SendDebug(__FUNCTION__, 'newFiles=' . print_r($newFiles, true), 0);
        $this->WriteAttributeString('files', json_encode($newFiles));

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

        $s = '';
        foreach ($out as $s) {
            $this->SendDebug(__FUNCTION__, '  ' . $s, 0);
        }
        if ($s != $data) {
            $this->SendDebug(__FUNCTION__, '  ' . $data, 0);
        }

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
        if (is_dir($path)) {
            $files = scandir($path);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $_path = $path . '/' . $file;
                if (is_dir($_path)) {
                    if ($this->rmDir($_path) == false) {
                        return false;
                    }
                } else {
                    if (unlink($_path) == false) {
                        $this->SendDebug(__FUNCTION__, 'unable to delete file ' . $_path, 0);
                        return false;
                    }
                }
            }
            if (rmdir($path) == false) {
                $this->SendDebug(__FUNCTION__, 'unable to delete directory ' . $path, 0);
                return false;
            }
        } elseif (unlink($path) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to delete file ' . $path, 0);
            return false;
        }
        return true;
    }

    private function readFile($fname, &$err)
    {
        if (file_exists($fname) == false) {
            $this->SendDebug(__FUNCTION__, 'missing file ' . $fname, 0);
            $err = 'missing file ' . $fname;
            return false;
        }
        $fp = fopen($fname, 'r');
        if ($fp == false) {
            $this->SendDebug(__FUNCTION__, 'unable to open file ' . $fname, 0);
            $err = 'unable to open file ' . $fname;
            return false;
        }
        $n = filesize($fname);
        $data = $n > 0 ? fread($fp, $n) : '';
        if ($data === false) {
            $this->SendDebug(__FUNCTION__, 'unable to read ' . $n . ' bytes from  file ' . $fname, 0);
            $err = 'unable to read ' . $n . ' bytes from file ' . $fname;
            return false;
        }
        if (fclose($fp) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to close file ' . $fname, 0);
            $err = 'unable to close file ' . $fname;
            return false;
        }
        return $data;
    }

    private function readDictonary($path)
    {
        $fname = $path . DIRECTORY_SEPARATOR . 'dictionary.json';
        $data = $this->readFile($fname, $err);
        if ($data == false) {
            return false;
        }
        @$dict = json_decode($data, true);
        if ($dict == false) {
            $this->SendDebug(__FUNCTION__, 'invalid json (' . json_last_error_msg() . ') in file ' . $fname . ', data=' . $data, 0);
            return false;
        }
        return $dict;
    }

    public function ReadAutoload(string &$err)
    {
        $fname = IPS_GetKernelDir() . 'scripts' . DIRECTORY_SEPARATOR . '__autoload.php';
        $data = $this->readFile($fname, $err);
        if ($err != '') {
            echo $err . PHP_EOL;
        }
        return $data;
    }

    private function writeFile($fname, $data, $overwrite, &$err)
    {
        $err = '';
        if ($overwrite == false && file_exists($fname)) {
            $this->SendDebug(__FUNCTION__, 'file ' . $fname . ' already exists', 0);
            $err = 'file ' . $fname . ' already exists';
            return false;
        }
        $fp = fopen($fname, 'w');
        if ($fp == false) {
            $this->SendDebug(__FUNCTION__, 'unable to create file ' . $fname, 0);
            $err = 'unable to create file ' . $fname;
            return false;
        }
        $n = strlen($data);
        if (fwrite($fp, $data, $n) === false) {
            $this->SendDebug(__FUNCTION__, 'unable to write ' . $n . ' bytes to file ' . $fname, 0);
            $err = 'unable to write ' . $n . ' bytes to file ' . $fname;
            return false;
        }
        if (fclose($fp) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to close file ' . $fname, 0);
            $err = 'unable to close file ' . $fname;
            return false;
        }
        return true;
    }

    public function WriteAutoload(string $data, bool $overwrite, string &$err)
    {
        $fname = IPS_GetKernelDir() . 'scripts' . DIRECTORY_SEPARATOR . '__autoload.php';
        $ret = $$his->writeFile($data, $overwrite, $err);
        if ($err != '') {
            echo $err . PHP_EOL;
        }
        return $ret;
    }
}

// git pull --dry-run
// 03.01.2024, 09:22:26 |              execute |   HEAD detached at 765c973
// git switch -
// git diff --shortstat $commit / git diff --shortstat $branch
// git diff --shortstat 765c973118ef70bafad2cb305ed6b6b16209eefc files/shelly_pro3em.php
