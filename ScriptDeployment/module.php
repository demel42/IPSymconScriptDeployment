<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class ScriptDeployment extends IPSModule
{
    use ScriptDeployment\StubsCommonLib;
    use ScriptDeploymentLocalLib;

    private static $semaphoreTM = 5 * 1000;

    private static $TOP_DIR = 'top';
    private static $CUR_DIR = 'cur';
    private static $CHG_DIR = 'chg';
    private static $DICTIONARY_FILE = 'dictionary.json';
    private static $FILE_DIR = 'files';

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

        $this->RegisterPropertyString('mapping_function', 'GetLocalConfig');

        $this->RegisterAttributeString('commit', '');
        $this->RegisterAttributeString('branches', json_encode([]));

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

    private function SetReferences()
    {
        $this->MaintainReferences();

        $files = $this->ReadFileList();
        foreach ($files as $file) {
            $scriptID = $file['id'];
            if ($this->IsValidID($scriptID)) {
                $this->RegisterReference($scriptID);
            }
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetReferences();

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

        $this->MaintainVariable('State', $this->Translate('State'), VARIABLETYPE_INTEGER, 'ScriptDeployment.State', $vpos++, true);
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

        $formElements[] = [
            'type'    => 'ValidationTextBox',
            'name'    => 'mapping_function',
            'width'   => '300px',
            'caption' => 'Function for mapping of keyword to value',
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
            $formActions[] = $this->GetModuleActivityFormAction();

            return $formActions;
        }

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Perform check',
            'onClick' => 'IPS_RequestAction($id, "PerformCheck", ""); IPS_RequestAction($id, "ReloadForm", "");',
        ];

        $stateFields = [
            'removed'  => 'deleted in repository',
            'added'    => 'added to repository',
            'lost'     => 'missing in repository',
            'moved'    => 'local moved',
            'orphan'   => 'parent missing',
            'missing'  => 'local missing',
            'modified' => 'local modified',
            'outdated' => 'updateable',
            'unknown'  => 'keyword/value missing',
        ];

        $topPath = $this->getSubPath(self::$TOP_DIR);
        $topDict = $this->readDictonary($topPath);
        $curPath = $this->getSubPath(self::$CUR_DIR);
        $curDict = $this->readDictonary($curPath);

        $files = $this->ReadFileList();
        $values = [];
        foreach ($files as $file) {
            $state = [];
            foreach ($stateFields as $fld => $msg) {
                if (isset($file[$fld]) && $file[$fld]) {
                    $state[] = $this->Translate($msg);
                }
            }
            if ($state == []) {
                $state[] = 'ok';
            }
            $values[] = [
                'filename'    => $file['filename'],
                'name'        => $file['name'],
                'location'    => $file['location'],
                'id'          => $this->IsValidID($file['id']) ? ('#' . $file['id']) : '',
                'state'       => implode(', ', $state),
            ];
        }

        $curVersion = isset($curDict['version']) ? $curDict['version'] : '';
        $curTimestamp = isset($curDict['tstamp']) ? date('d.m.Y H:i:s', (int) $curDict['tstamp']) : '';
        $topVersion = isset($topDict['version']) ? $topDict['version'] : '';
        $topTimestamp = isset($topDict['tstamp']) ? date('d.m.Y H:i:s', (int) $topDict['tstamp']) : '';

        $onClick_FileList = 'IPS_RequestAction($id, "UpdateFormField_FileList", json_encode($FileList));';
        $formActions[] = [
            'type'    => 'RowLayout',
            'items'   => [
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'     => 'ValidationTextBox',
                            'value'    => $curVersion,
                            'enabled'  => false,
                            'caption'  => 'Installed version',
                        ],
                        [
                            'type'     => 'ValidationTextBox',
                            'value'    => $curTimestamp,
                            'enabled'  => false,
                            'caption'  => 'Timestamp',
                        ],
                    ],
                ],
                [
                    'type'    => 'Label',
                ],
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'     => 'ValidationTextBox',
                            'value'    => $topVersion,
                            'enabled'  => false,
                            'caption'  => 'Repository version',
                        ],
                        [
                            'type'     => 'ValidationTextBox',
                            'value'    => $topTimestamp,
                            'enabled'  => false,
                            'caption'  => 'Timestamp',
                        ],
                    ],
                ],
            ],
        ];
        $formActions[] = [
            'type'     => 'List',
            'name'     => 'FileList',
            'columns'  => [
                [
                    'name'     => 'filename',
                    'width'    => '250px',
                    'caption'  => 'Filename',
                    'onClick'  => $onClick_FileList,
                ],
                [
                    'name'     => 'name',
                    'width'    => '350px',
                    'caption'  => 'Name',
                    'onClick'  => $onClick_FileList,
                ],
                [
                    'name'     => 'location',
                    'width'    => 'auto',
                    'caption'  => 'Target location',
                    'onClick'  => $onClick_FileList,
                ],
                [
                    'name'     => 'state',
                    'width'    => '250px',
                    'caption'  => 'State',
                    'onClick'  => $onClick_FileList,
                ],
                [
                    'name'     => 'id',
                    'width'    => '80px',
                    'caption'  => 'ObjectID',
                    'onClick'  => $onClick_FileList,
                ],
            ],
            'add'      => false,
            'delete'   => false,
            'values'   => $values,
            'rowCount' => count($values) > 0 ? count($values) : 1,
            'caption'  => 'Deployed scripts',
        ];
        $formActions[] = [
            'type'    => 'RowLayout',
            'items'   => [
                [
                    'type'     => 'OpenObjectButton',
                    'objectID' => 0,
                    'visible'  => false,
                    'name'     => 'openObject_FileList',
                    'caption'  => 'Open script',
                ],
                [
                    'type'     => 'PopupButton',
                    'name'     => 'connectScript_Popup',
                    'visible'  => false,
                    'popup'    => [
                        'caption'   => 'Connect script',
                        'items'     => [
                            [
                                'type'     => 'ValidationTextBox',
                                'name'     => 'connectScript_Filename',
                                'value'    => '',
                                'width'    => '100px',
                                'enabled'  => false,
                                'caption'  => 'Filename',
                            ],
                            [
                                'type'     => 'ValidationTextBox',
                                'name'     => 'connectScript_Name',
                                'value'    => '',
                                'width'    => '200px',
                                'enabled'  => false,
                                'caption'  => 'Name',
                            ],
                            [
                                'type'     => 'ValidationTextBox',
                                'name'     => 'connectScript_Location',
                                'value'    => '',
                                'width'    => '600px',
                                'enabled'  => false,
                                'caption'  => 'Target location',
                            ],
                            [
                                'type'     => 'SelectScript',
                                'name'     => 'connectScript_ScriptID',
                                'value'    => 0,
                                'width'    => '600px',
                                'caption'  => 'Script'
                            ],
                            [
                                'type'     => 'CheckBox',
                                'name'     => 'connectScript_AdjustLocation',
                                'value'    => true,
                                'caption'  => 'Adjust location of script'
                            ],
                        ],
                        'buttons' => [
                            [
                                'type'    => 'Button',
                                'caption' => 'Connect',
                                'onClick' => 'IPS_RequestAction($id, "ConnectScript", json_encode(["scriptID" => $connectScript_ScriptID, "filename" => $connectScript_Filename, "location" => $connectScript_Location, "adjustLocation" => $connectScript_AdjustLocation]));',
                            ],
                        ],
                        'closeCaption' => 'Cancel',
                    ],
                    'caption' => 'Connect script',
                ],
                [
                    'type'     => 'Button',
                    'caption'  => 'Delete item',
                    'name'     => 'DeleteItem',
                    'visible'  => false,
                    'onClick'  => 'IPS_RequestAction($id, "DeleteItem", json_encode(["filename" => $FileList["filename"]]));',
                ],
            ],
        ];
        $formActions[] = [
            'type'    => 'RowLayout',
            'items'   => [
                [
                    'type'    => 'Button',
                    'caption' => 'Search missing',
                    'onClick' => 'IPS_RequestAction($id, "SearchMissing", ""); IPS_RequestAction($id, "ReloadForm", "");',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Perform adjustment',
                    'onClick' => 'IPS_RequestAction($id, "PerformAdjustment", ""); IPS_RequestAction($id, "ReloadForm", "");',
                ],
            ],
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded'  => false,
            'items'     => [
                $this->GetInstallVarProfilesFormItem(),
            ],
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();
        $formActions[] = $this->GetModuleActivityFormAction();

        return $formActions;
    }

    private function DeleteItem(string $filename)
    {
        $msgFileV = [];

        $newFiles = [];
        $files = $this->ReadFileList();
        foreach ($files as $index => $file) {
            $msgV = isset($msgFileV[$file['filename']]['msgV']) ? $msgFileV[$file['filename']]['msgV'] : [];

            if ($file['filename'] != $filename) {
                $newFiles[] = $file;
            } else {
                $msgV[] = 'delete item';
                $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
            }
        }
        foreach ($msgFileV as $msgFile) {
            $this->SendDebug(__FUNCTION__, $this->printFile($msgFile['file']) . ' => ' . implode(', ', $msgFile['msgV']), 0);
        }

        $this->WriteFileList($newFiles);

        $this->ReloadForm();
        return true;
    }

    private function ConnectScript(int $scriptID, string $filename, string $location, bool $adjustLocation)
    {
        $this->SendDebug(__FUNCTION__, 'scriptID=' . $scriptID . ', filename=' . $filename . ', adjustLocation=' . $this->bool2str($adjustLocation) . ', location=' . $location, 0);

        if ($scriptID != 1) {
            if ($this->IsValidID($scriptID) == false) {
                $this->SendDebug(__FUNCTION__, 'ID ' . $scriptID . ' is invalid', 0);
                $msg = $this->TranslateFormat('ID "{$scriptID}" is invalid', ['{$scriptID}' => $scriptID]);
                $this->PopupMessage($msg);
                return false;
            }
            if (IPS_ObjectExists($scriptID) == false) {
                $this->SendDebug(__FUNCTION__, 'no object with ID ' . $scriptID, 0);
                $msg = $this->TranslateFormat('No object with ID "{$scriptID}"', ['{$scriptID}' => $scriptID]);
                $this->PopupMessage($msg);
                return false;
            }
            if (IPS_ScriptExists($scriptID) == false) {
                $this->SendDebug(__FUNCTION__, 'object with ID ' . $scriptID . ' is not a script', 0);
                $msg = $this->TranslateFormat('Object with ID "{$scriptID}" is not a script', ['{$scriptID}' => $scriptID]);
                $this->PopupMessage($msg);
                return false;
            }

            if ($adjustLocation) {
                $parents = $this->Location2ParentChain($location, true);
                if ($parents === false) {
                    $this->SendDebug(__FUNCTION__, 'unable to resolve location "' . $location . '"', 0);
                    $msg = $this->TranslateFormat('Unable to resolve location "{$location}"', ['{$location}' => $location]);
                    $this->PopupMessage($msg);
                    return false;
                }
                $parID = $parents[0];
                if (IPS_SetParent($scriptID, $parID) == false) {
                    $this->SendDebug(__FUNCTION__, 'unable to set parent ' . $parID . ' to script ' . $scriptID, 0);
                    $msg = $this->Translate('Unable to set parent');
                    $this->PopupMessage($msg);
                    return false;
                }
            }
        }

        $msgFileV = [];

        $files = $this->ReadFileList();
        foreach ($files as $index => $file) {
            $msgV = isset($msgFileV[$file['filename']]['msgV']) ? $msgFileV[$file['filename']]['msgV'] : [];

            if ($file['filename'] != $filename) {
                continue;
            }
            if ($this->IsValidID($scriptID) == false) {
                $file['id'] = 0;
                $file['missing'] = true;
                $msgV[] = 'disconnect';
                $this->AddModuleActivity('manual disconnected "' . $name . '" from script ' . $scriptID, 0);
            } else {
                $file['id'] = $scriptID;
                $file['missing'] = false;
                $msgV[] = 'connect';
                if ($adjustLocation) {
                    $file['orphan'] = false;
                    $file['moved'] = false;
                    $msgV[] = 'adjust location';
                }
                $this->AddModuleActivity('manual connected "' . $name . '" to script ' . $scriptID, 0);
            }
            $file['added'] = false;
            $files[$index] = $file;

            $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
        }

        foreach ($msgFileV as $msgFile) {
            $this->SendDebug(__FUNCTION__, $this->printFile($msgFile['file']) . ' => ' . implode(', ', $msgFile['msgV']), 0);
        }

        $this->WriteFileList($files);

        $this->ReloadForm();
        return true;
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

    private function SyncRepository($subdir, $branch, $commit)
    {
        $this->SendDebug(__FUNCTION__, 'subdir=' . $subdir . ', branch=' . $branch . ', commit=' . $commit, 0);

        $basePath = $this->getBasePath();
        $path = $this->getSubPath($subdir);

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

        $basePath = $this->getBasePath();

        $dirs = [$path, $basePath];
        foreach ($dirs as $dir) {
            if ($this->checkDir($dir, true) == false) {
                $this->SetValue('State', self::$STATE_FAULTY);
                IPS_SemaphoreLeave($this->SemaphoreID);
                return false;
            }
        }

        $branch = $this->getBranch();
        $commit = $this->getCommit();

        $topPath = $this->getSubPath(self::$TOP_DIR);
        $curPath = $this->getSubPath(self::$CUR_DIR);

        if ($this->SyncRepository(self::$TOP_DIR, $branch, '') == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($this->changeDir($topPath) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }

        if ($this->execute('git branch -r 2>&1', $output) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
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
            $this->SendDebug(__FUNCTION__, 'unknown branch "' . $branch . '"', 0);
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }

        if ($this->SyncRepository(self::$CUR_DIR, $branch, $commit) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($this->changeDir($curPath) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($commit == '') {
            if ($this->execute('git rev-parse HEAD 2>&1', $output) == false) {
                $this->SetValue('State', self::$STATE_FAULTY);
                IPS_SemaphoreLeave($this->SemaphoreID);
                return false;
            }
            $curCommit = $output[0];
            $this->SendDebug(__FUNCTION__, 'curCommit=' . $curCommit, 0);
            $this->WriteAttributeString('commit', $curCommit);
        }

        $msgFileV = [];
        $ret = $this->CheckFileList($msgFileV);
        foreach ($msgFileV as $msgFile) {
            $this->SendDebug(__FUNCTION__, $this->printFile($msgFile['file']) . ' => ' . implode(', ', $msgFile['msgV']), 0);
        }

        $state = $ret ? $this->GetState4FileList() : self::$STATE_FAULTY;
        $this->SetValue('State', $state);

        $this->SetValue('Timestamp', time());

        IPS_SemaphoreLeave($this->SemaphoreID);

        $this->SetCheckTimer();

        return $ret;
    }

    private function GetState4FileList()
    {
        $files = $this->ReadFileList();

        $added = 0;
        $missing = 0;
        $lost = 0;
        $modified = 0;
        $moved = 0;
        $orphan = 0;
        $outdated = 0;
        $removed = 0;
        $unknown = 0;
        foreach ($files as $file) {
            if ($file['added']) {
                $added++;
            }
            if ($file['missing']) {
                $missing++;
            }
            if ($file['lost']) {
                $lost++;
            }
            if ($file['modified']) {
                $modified++;
            }
            if ($file['moved']) {
                $moved++;
            }
            if ($file['orphan']) {
                $orphan++;
            }
            if ($file['outdated']) {
                $outdated++;
            }
            if ($file['removed']) {
                $removed++;
            }
            if ($file['unknown']) {
                $unknown++;
            }
        }

        if ($lost || $missing || $moved || $orphan || $unknown) {
            $state = self::$STATE_UNCLEAR;
        } elseif ($modified) {
            $state = self::$STATE_MODIFIED;
        } elseif ($added || $removed || $outdated) {
            $state = self::$STATE_UPDATEABLE;
        } else {
            $state = self::$STATE_SYNCED;
        }

        return $state;
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'PerformCheck':
                $this->PerformCheck();
                break;
            case 'SearchMissing':
                $this->SearchMissing();
                break;
            case 'PerformAdjustment':
                $this->PerformAdjustment();
                break;
            case 'ConnectScript':
                $jparams = json_decode($value, true);
                $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', params=' . print_r($jparams, true), 0);
                $scriptID = $jparams['scriptID'];
                $filename = $jparams['filename'];
                $location = $jparams['location'];
                $adjustLocation = $jparams['adjustLocation'];
                $this->ConnectScript($scriptID, $filename, $location, $adjustLocation);
                break;
            case 'DeleteItem':
                $jparams = json_decode($value, true);
                $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', params=' . print_r($jparams, true), 0);
                $filename = $jparams['filename'];
                $this->DeleteItem($filename);
                break;
            case 'ReloadForm':
                $this->ReloadForm();
                break;
            case 'UpdateFormField_FileList':
                $jparams = json_decode($value, true);
                $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', params=' . print_r($jparams, true), 0);
                $id = $jparams['id'] != '' ? (int) substr($jparams['id'], 1) : 0;
                $filename = $jparams['filename'];
                $name = $jparams['name'];
                $location = $jparams['location'];

                $this->UpdateFormField('openObject_FileList', 'objectID', $id);
                $this->UpdateFormField('openObject_FileList', 'visible', $id ? true : false);

                $this->UpdateFormField('connectScript_Popup', 'visible', true);
                $this->UpdateFormField('connectScript_Filename', 'value', $filename);
                $this->UpdateFormField('connectScript_Name', 'value', $name);
                $this->UpdateFormField('connectScript_Location', 'value', $location);
                $this->UpdateFormField('connectScript_ScriptID', 'value', $id);

                $this->UpdateFormField('DeleteItem', 'visible', $id ? true : false);

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

    private function readFile($fname, &$data, &$err)
    {
        $data = '';
        $err = '';

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
        return true;
    }

    private function readDictonary($path)
    {
        $fname = $path . DIRECTORY_SEPARATOR . self::$DICTIONARY_FILE;
        if ($this->readFile($fname, $data, $err) == false) {
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
        if ($this->readFile($fname, $data, $err) == false) {
            if ($err != '') {
                echo $err . PHP_EOL;
            }
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
        $ret = $this->writeFile($fname, $data, $overwrite, $err);
        if ($err != '') {
            echo $err . PHP_EOL;
        }
        return $ret;
    }

    private function getBasePath()
    {
        $url = $this->ReadPropertyString('url');
        $path = $this->ReadPropertyString('path');
        return $path . DIRECTORY_SEPARATOR . basename($url, '.git');
    }

    private function getSubPath($subPath)
    {
        return $this->getBasePath() . DIRECTORY_SEPARATOR . $subPath;
    }

    private function Location2ParentChain($location, $createParents)
    {
        $path = explode('\\', $location);
        $objID = 0;
        $parents = [$objID];
        foreach ($path as $part) {
            $parID = $objID;
            @$objID = IPS_GetObjectIDByName($part, $parID);
            if ($objID == false) {
                if ($createParents == false) {
                    $parents = [];
                    break;
                }
                $objID = IPS_CreateCategory();
                if ($objID == false) {
                    $this->SendDebug(__FUNCTION__, 'unable to create category', 0);
                    return false;
                }
                IPS_SetName($objID, $part);
                IPS_SetParent($objID, $parID);
            }
            $parents[] = $objID;
        }
        return array_reverse($parents);
    }

    private function getBranch()
    {
        $branch = $this->ReadPropertyString('branch');
        if ($branch == '') {
            $branch = 'main';
        }
        return $branch;
    }

    private function getCommit()
    {
        $commit = $this->ReadAttributeString('commit');
        return $commit;
    }

    private function CheckFileList(&$msgFileV)
    {
        $branch = $this->getBranch();
        $commit = $this->getCommit();

        $basePath = $this->getBasePath();
        $topPath = $this->getSubPath(self::$TOP_DIR);
        $curPath = $this->getSubPath(self::$CUR_DIR);

        $topDict = $this->readDictonary($topPath);
        if ($topDict === false) {
            $this->SendDebug(__FUNCTION__, 'no valid top-dictionary', 0);
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'top-dictionary=' . print_r($topDict, true), 0);

        if ($this->changeDir($topPath) == false) {
            return false;
        }
        if ($this->execute('git diff --stat ' . $commit . ' 2>&1', $output) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        $updateableFiles = [];
        foreach ($output as $s) {
            if (preg_match('/[ ]*files\/([^ ]*)[ ]*\| .*/', $s, $r)) {
                $updateableFiles[] = $r[1];
            }
        }
        $this->SendDebug(__FUNCTION__, 'updateableFiles=' . print_r($updateableFiles, true), 0);

        $curDict = $this->readDictonary($curPath);
        if ($curDict === false) {
            $this->SendDebug(__FUNCTION__, 'no valid cur-dictionary', 0);
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'cur-dictionary=' . print_r($curDict, true), 0);

        $curFiles = $curDict['files'];
        $topFiles = $topDict['files'];

        $oldFiles = $this->ReadFileList();

        $dflt = [
            'filename' => '',
            'name'     => '',
            'location' => '',
            'id'       => 0,
            'requires' => [],
            'removed'  => false,
            'added'    => false,
            'lost'     => false,
            'moved'    => false,
            'orphan'   => false,
            'missing'  => false,
            'modified' => false,
            'outdated' => false,
            'unknown'  => false,
        ];

        $newFiles = [];
        foreach ($curFiles as $curFile) {
            $msgV = isset($msgFileV[$curFile['filename']]['msgV']) ? $msgFileV[$curFile['filename']]['msgV'] : [];

            $fnd = false;
            foreach ($oldFiles as $oldFile) {
                if ($curFile['filename'] == $oldFile['filename']) {
                    $fnd = true;
                    break;
                }
            }
            $newFile = $dflt;
            $newFile['filename'] = $curFile['filename'];
            $newFile['name'] = $curFile['name'];
            $newFile['location'] = $curFile['location'];
            if ($fnd) {
                $msgV[] = 'found in prev';
                $scriptID = $oldFile['id'];
                if ($this->IsValidID($scriptID) == false) {
                    $scriptID = 0;
                } elseif (IPS_ObjectExists($scriptID) == false) {
                    $this->SendDebug(__FUNCTION__, 'script ' . $scriptID . ' disappeared', 0);
                    $this->AddModuleActivity('script ' . $scriptID . ' "' . $newFile['filename'] . '" disappeared', 0);
                    $scriptID = 0;
                }
                $newFile['id'] = $scriptID;
            } else {
                $scriptID = 0;
                $msgV[] = 'new file';
            }

            $fname = $curPath . DIRECTORY_SEPARATOR . self::$FILE_DIR . DIRECTORY_SEPARATOR . $newFile['filename'];
            if ($this->readFile($fname, $curContent, $err) == false) {
                if ($scriptID == 0 && $oldFile['lost']) {
                    $msgV = 'not longer in repository';
                    $msgFileV[$curFile['filename']] = ['file' => $curFile, 'msgV' => $msgV];
                    // $this->SendDebug(__FUNCTION__, '1:'.print_r($msgFileV[$curFile['filename']], true),0);
                    continue;
                }
                $newFile['lost'] = true;
                $msgV[] = 'not in repository';
            } else {
                if ($scriptID == 0) {
                    $newFile['missing'] = true;
                    $msgV[] = 'script is missing';
                } else {
                    $ipsContent = IPS_GetScriptContent($scriptID);
                    if (strcmp($curContent, $ipsContent) != 0) {
                        $newFile['modified'] = true;
                        $msgV[] = 'script-content is changed';
                    } else {
                        $msgV[] = 'script unchanged';
                    }
                    if (in_array($curFile['filename'], $updateableFiles)) {
                        $newFile['outdated'] = true;
                        $msgV[] = 'script is outdateable';
                    }
                }
            }

            $newFile['requires'] = isset($curFile['requires']) ? $curFile['requires'] : [];
            $newFiles[] = $newFile;

            $msgFileV[$newFile['filename']] = ['file' => $newFile, 'msgV' => $msgV];
            // $this->SendDebug(__FUNCTION__, '2:'.print_r($msgFileV[$newFile['filename']], true),0);
        }
        foreach ($oldFiles as $oldFile) {
            $msgV = isset($msgFileV[$oldFile['filename']]['msgV']) ? $msgFileV[$oldFile['filename']]['msgV'] : [];

            if ($oldFile['id'] == 0) {
                continue;
            }

            $fnd = false;
            foreach ($curFiles as $curFile) {
                if ($curFile['filename'] == $oldFile['filename']) {
                    $fnd = true;
                    break;
                }
            }
            if ($fnd) {
                continue;
            }

            $newFile = $dflt;
            $newFile['filename'] = $oldFile['filename'];
            $newFile['name'] = $oldFile['name'];
            $newFile['location'] = $oldFile['location'];
            $newFile['id'] = $oldFile['id'];
            $newFile['requires'] = isset($oldFile['requires']) ? $oldFile['requires'] : [];
            $newFile['removed'] = true;
            $newFiles[] = $newFile;

            $msgV[] = 'removed in repository';
            $msgFileV[$newFile['filename']] = ['file' => $newFile, 'msgV' => $msgV];
            // $this->SendDebug(__FUNCTION__, '3:'.print_r($msgFileV[$newFile['filename']], true),0);
        }
        foreach ($topFiles as $topFile) {
            $msgV = isset($msgFileV[$topFile['filename']]['msgV']) ? $msgFileV[$topFile['filename']]['msgV'] : [];

            $fnd = false;
            foreach ($newFiles as $newFile) {
                if ($topFile['filename'] == $newFile['filename']) {
                    $fnd = true;
                    break;
                }
            }
            if ($fnd) {
                continue;
            }

            $newFile = $dflt;
            $newFile['filename'] = $topFile['filename'];
            $newFile['name'] = $topFile['name'];
            $newFile['location'] = $topFile['location'];
            $newFile['requires'] = isset($topFile['requires']) ? $topFile['requires'] : [];
            $newFile['added'] = true;
            $newFiles[] = $newFile;

            $msgV[] = 'added to repository';
            $msgFileV[$newFile['filename']] = ['file' => $newFile, 'msgV' => $msgV];
            // $this->SendDebug(__FUNCTION__, '4:'.print_r($msgFileV[$newFile['filename']], true),0);
        }
        foreach ($newFiles as $index => $newFile) {
            $msgV = isset($msgFileV[$newFile['filename']]['msgV']) ? $msgFileV[$newFile['filename']]['msgV'] : [];

            $scriptID = $newFile['id'];
            if ($scriptID == 0) {
                continue;
            }

            if (IPS_ObjectExists($scriptID) == false) {
                $newFile['id'] = 0;
                $newFile['missing'] = true;
                $newFiles[$index] = $newFile;

                $msgV[] = 'script is missing';
                $msgFileV[$newFile['filename']] = ['file' => $newFile, 'msgV' => $msgV];
                // $this->SendDebug(__FUNCTION__, '5:'.print_r($msgFileV[$newFile['filename']], true),0);
                continue;
            }

            $parID = IPS_GetParent($scriptID);
            $location = $newFile['location'];
            $parents = $this->Location2ParentChain($location, false);
            if (count($parents) == 0) {
                $newFile['orphan'] = true;
                $newFiles[$index] = $newFile;
                $msgV[] = 'script has no parent';
            } elseif ($parents[0] != $parID) {
                $newFile['moved'] = true;
                $newFiles[$index] = $newFile;
                $msgV[] = 'script is moved to other location';
            }

            $msgFileV[$newFile['filename']] = ['file' => $newFile, 'msgV' => $msgV];
            // $this->SendDebug(__FUNCTION__, '6:'.print_r($msgFileV[$newFile['filename']], true),0);
        }
        $mapping_function = $this->ReadPropertyString('mapping_function');
        if ($mapping_function != '') {
            foreach ($newFiles as $index => $newFile) {
                $msgV = isset($msgFileV[$newFile['filename']]['msgV']) ? $msgFileV[$newFile['filename']]['msgV'] : [];

                $scriptID = $newFile['id'];
                if ($scriptID == 0) {
                    continue;
                }
                $unknown_keywords = [];
                $requires = $newFile['requires'];
                foreach ($requires as $indent) {
                    $r = $mapping_function($indent);
                    if ($r === false) {
                        $unknown_keywords[] = $indent;
                        break;
                    }
                }
                if ($unknown_keywords != []) {
                    $msgV[] = 'unknown keyword(s)=[' . implode(',', $unknown_keywords) . ']';
                } else {
                    $newFile['unknown'] = false;
                }
                $newFiles[$index] = $newFile;

                $msgFileV[$newFile['filename']] = ['file' => $newFile, 'msgV' => $msgV];
                // $this->SendDebug(__FUNCTION__, '7:'.print_r($msgFileV[$newFile['filename']], true),0);
            }
        }

        $this->WriteFileList($newFiles);

        $chgPath = $this->getSubPath(self::$CHG_DIR);
        if ($this->SyncRepository(self::$CHG_DIR, $branch, $commit) == false) {
            return false;
        }

        foreach ($newFiles as $index => $newFile) {
            $scriptID = $newFile['id'];
            if ($scriptID == 0) {
                continue;
            }
            if ($newFile['modified']) {
                $ipsContent = IPS_GetScriptContent($scriptID);
                $fname = $chgPath . DIRECTORY_SEPARATOR . self::$FILE_DIR . DIRECTORY_SEPARATOR . $newFile['filename'];
                if ($this->writeFile($fname, $ipsContent, true, $err) == false) {
                    return false;
                }
            }
        }

        if ($this->changeDir($chgPath) == false) {
            return false;
        }

        $patchFile = $basePath . DIRECTORY_SEPARATOR . 'cur.patch';
        if ($this->execute('git diff > ' . $patchFile . ' 2>&1', $output) == false) {
            return false;
        }
        if ($this->readFile($patchFile, $patchContent, $err) == false) {
            return false;
        }
        $this->SetMediaData('DifferenceToCurrent', $patchContent, MEDIATYPE_DOCUMENT, '.txt', false);
        if (unlink($patchFile) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to delete file ' . $patchFile, 0);
            return false;
        }
        if ($this->execute('git diff -R ' . $branch . ' > ' . $patchFile . ' 2>&1', $output) == false) {
            return false;
        }
        if ($this->readFile($patchFile, $patchContent, $err) == false) {
            return false;
        }
        $this->SetMediaData('DifferenceToTop', $patchContent, MEDIATYPE_DOCUMENT, '.txt', false);
        if (unlink($patchFile) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to delete file ' . $patchFile, 0);
            return false;
        }

        if ($this->changeDir($basePath) == false) {
            return false;
        }
        if ($this->rmDir($chgPath) == false) {
            return false;
        }

        return true;
    }

    private function SearchMissing()
    {
        $msgFileV = [];
        $files = $this->ReadFileList();
        foreach ($files as $index => $file) {
            if ($file['id'] != 0) {
                continue;
            }

            $msgV = isset($msgFileV[$file['filename']]['msgV']) ? $msgFileV[$file['filename']]['msgV'] : [];

            $location = $file['location'];
            $parents = $this->Location2ParentChain($location, false);
            if (count($parents) == 0) {
                $this->SendDebug(__FUNCTION__, 'unable to resolve location "' . $location . '"', 0);
                $msgV[] = 'unable to resolve location "' . $location . '"';
                $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                continue;
            }
            $parID = $parents[0];
            $name = $file['name'];
            $scriptID = @IPS_GetObjectIDByName($name, $parID);
            if ($scriptID) {
                $this->SendDebug(__FUNCTION__, 'script ' . $scriptID . ' found by name "' . $name . '"', 0);
                $file['id'] = $scriptID;
                $file['missing'] = false;
                $files[$index] = $file;
                $msgV[] = 'found script by name';
                $this->AddModuleActivity('found existing script ' . $scriptID . ' by name "' . $name . '"', 0);
            } else {
                $msgV[] = 'script with name "' . $name . '" not found';
            }

            $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
        }

        $this->WriteFileList($files);

        foreach ($msgFileV as $msgFile) {
            $this->SendDebug(__FUNCTION__, $this->printFile($msgFile['file']) . ' => ' . implode(', ', $msgFile['msgV']), 0);
        }

        return true;
    }

    private function PerformAdjustment()
    {
        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'repository is locked', 0);
            return;
        }

        $branch = $this->getBranch();
        $commit = $this->getCommit();

        $basePath = $this->getBasePath();
        $curPath = $this->getSubPath(self::$CUR_DIR);

        if ($this->SyncRepository(self::$CUR_DIR, $branch, $commit) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($this->changeDir($curPath) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($this->execute('git checkout ' . $branch . ' 2>&1', $output) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($this->execute('git pull 2>&1', $output) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($this->execute('git rev-parse HEAD 2>&1', $output) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        $commit = $output[0];
        $this->SendDebug(__FUNCTION__, 'new commit=' . $commit, 0);
        $this->WriteAttributeString('commit', $commit);
        $this->execute('git config advice.detachedHead false', $output);
        if ($this->execute('git checkout ' . $commit . ' 2>&1', $output) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }

        $msgFileV = [];
        $this->CheckFileList($msgFileV);

        $files = $this->ReadFileList();
        foreach ($files as $index => $file) {
            $msgV = isset($msgFileV[$file['filename']]['msgV']) ? $msgFileV[$file['filename']]['msgV'] : [];

            $curContent = '';
            if ($file['missing'] || $file['modified']) {
                $fname = $curPath . DIRECTORY_SEPARATOR . self::$FILE_DIR . DIRECTORY_SEPARATOR . $file['filename'];
                if ($this->readFile($fname, $curContent, $err) == false) {
                    $msgV[] = 'can\'t read file from repository';
                    $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                    $this->AddModuleActivity('unable to read file "' . $file['filename'] . '"', 0);
                    continue;
                }
            }

            if ($file['missing']) {
                $scriptID = IPS_CreateScript(SCRIPTTYPE_PHP);
                if ($scriptID == false) {
                    $this->SendDebug(__FUNCTION__, 'unable to create script', 0);
                    $msgV[] = 'unable to set script content';
                    $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                    $this->AddModuleActivity('failure occured while creating script ' . $scriptID . ' from "' . $file['filename'] . '" (create)', 0);
                    continue;
                }
                if (IPS_SetScriptContent($scriptID, $curContent) == false) {
                    $this->SendDebug(__FUNCTION__, 'unable to set content to script ' . $scriptID, 0);
                    $msgV[] = 'unable to set script content';
                    $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                    $this->AddModuleActivity('failure occured while creating script ' . $scriptID . ' from "' . $file['filename'] . '" (content)', 0);
                    continue;
                }
                $name = $file['name'];
                if (IPS_SetName($scriptID, $name) == false) {
                    $this->SendDebug(__FUNCTION__, 'unable to set name "' . $name . '" to script ' . $scriptID, 0);
                    $msgV[] = 'unable to set script name "' . $name . '"';
                    $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                    $this->AddModuleActivity('failure occured while creating script ' . $scriptID . ' from "' . $file['filename'] . '" (name)', 0);
                    continue;
                }
                $location = $file['location'];
                $parents = $this->Location2ParentChain($location, true);
                if ($parents === false) {
                    $this->SendDebug(__FUNCTION__, 'unable to resolve location "' . $location . '"', 0);
                    $msgV[] = 'unable to resolve location "' . $location . '"';
                    $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                    $this->AddModuleActivity('failure occured while creating script ' . $scriptID . ' from "' . $file['filename'] . '" (location)', 0);
                    continue;
                }
                $parID = $parents[0];
                if (IPS_SetParent($scriptID, $parID) == false) {
                    $this->SendDebug(__FUNCTION__, 'unable to set parent ' . $parID . ' to script ' . $scriptID, 0);
                    $msgV[] = 'unable to set parent ' . $parID;
                    $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                    $this->AddModuleActivity('failure occured while creating script ' . $scriptID . ' from "' . $file['filename'] . '" (parent)', 0);
                    continue;
                }

                $file['id'] = $scriptID;
                $file['missing'] = false;
                $files[$index] = $file;
                $this->SendDebug(__FUNCTION__, 'create script ' . $scriptID . ', file=' . print_r($file, true), 0);
                $msgV[] = 'create script';
                $msg = 'create script ' . $scriptID . ' from "' . $file['filename'] . '"';
                if ($parID) {
                    $msg .= ' with parent ' . $parID;
                }
                $this->AddModuleActivity($msg, 0);
            }

            if ($file['modified']) {
                $scriptID = $file['id'];
                if (IPS_SetScriptContent($scriptID, $curContent) == false) {
                    $this->SendDebug(__FUNCTION__, 'unable to set content to script ' . $scriptID, 0);
                    $msgV[] = 'unable to set script content';
                    $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                    $this->AddModuleActivity('failure occured while creating script ' . $scriptID . ' from "' . $file['filename'] . '" (content)', 0);
                    continue;
                }
                $file['modified'] = false;
                $files[$index] = $file;
                $this->SendDebug(__FUNCTION__, 'update script ' . $scriptID . ', file=' . print_r($file, true), 0);
                $msgV[] = 'update script';
                $this->AddModuleActivity('update script ' . $scriptID . ' from "' . $file['filename'] . '"', 0);
            }

            $scriptID = $file['id'];
            if ($scriptID != 0) {
                $this->SetObjectInfo($scriptID, $file['filename'], $file['location'], $file['name']);
            }

            $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
        }

        foreach ($msgFileV as $msgFile) {
            $this->SendDebug(__FUNCTION__, $this->printFile($msgFile['file']) . ' => ' . implode(', ', $msgFile['msgV']), 0);
        }

        $this->WriteFileList($files);

        $state = $this->GetState4FileList();
        $this->SetValue('State', $state);

        IPS_SemaphoreLeave($this->SemaphoreID);

        return true;
    }

    private function SetObjectInfo($scriptID, $filename, $location, $name)
    {
        if ($this->IsValidID($scriptID) == false) {
            $this->SendDebug(__FUNCTION__, 'ID ' . $scriptID . ' is invalid', 0);
            return false;
        }
        if (IPS_ObjectExists($scriptID) == false) {
            $this->SendDebug(__FUNCTION__, 'no object with ID ' . $scriptID, 0);
            return false;
        }

        $url = $this->ReadPropertyString('url');
        if (preg_match('/^([^:]*):\/\/[^@]*@(.*)$/', $url, $p)) {
            $url = $p[1] . '://' . $p[2];
        }

        $keywords = [
            'Source',
            'Source file',
            'Target location',
            'Designation',
        ];

        $new_lines = [];
        $new_lines = [
            $this->Translate('Source') . ': ' . $url,
            $this->Translate('Source file') . ': ' . $filename,
            $this->Translate('Target location') . ': ' . $location,
            $this->Translate('Designation') . ': ' . $name,
        ];
        $new_lines[] = '';

        $obj = IPS_GetObject($scriptID);
        $info = $obj['ObjectInfo'];
        $lines = explode(PHP_EOL, $info);
        foreach ($lines as $line) {
            foreach ($keywords as $keyword) {
                if (preg_match('/^' . $this->Translate($keyword) . ': /', $line)) {
                    $line = '';
                    break;
                }
            }
            if ($line != '') {
                $new_lines[] = $line;
            }
        }

        $info = implode(PHP_EOL, $new_lines);
        if (IPS_SetInfo($scriptID, $info) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to set info "' . $info . '" to object with ID ' . $scriptID, 0);
            return false;
        }

        return true;
    }

    private function ReadFileList()
    {
        $s = $this->GetMediaData('FileList');
        @$files = json_decode((string) $s, true);
        if ($files == false) {
            $files = [];
        }
        return $files;
    }

    private function cmp_fileList($a, $b)
    {
        if ($a['filename'] != $b['filename']) {
            return (strcmp($a['filename'], $b['filename']) < 0) ? -1 : 1;
        }
        return ($a['id'] < $b['id']) ? -1 : 1;
    }

    private function WriteFileList($files)
    {
        usort($files, [__CLASS__, 'cmp_fileList']);
        $s = json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->GetMediaData('FileList') != $s) {
            $this->SetMediaData('FileList', $s, MEDIATYPE_DOCUMENT, '.txt', false);
        }

        $this->SetReferences();
    }

    private function GetAllChildenIDs($objID, &$objIDs)
    {
        $cIDs = IPS_GetChildrenIDs($objID);
        if ($cIDs != []) {
            $objIDs = array_merge($objIDs, $cIDs);
            foreach ($cIDs as $cID) {
                $this->GetAllChildenIDs($cID, $objIDs);
            }
        }
    }

    public function BuildExport(array $objIDs, bool $with_childs)
    {
        $expPath = $this->getSubPath('export');
        $filePath = $expPath . DIRECTORY_SEPARATOR . self::$FILE_DIR;

        if (file_exists($expPath)) {
            if ($this->rmDir($expPath) == false) {
                return false;
            }
        }

        if ($this->makeDir($expPath) == false) {
            return false;
        }
        if ($this->makeDir($filePath) == false) {
            return false;
        }

        $_objIDs = [];
        foreach ($objIDs as $objID) {
            $_objIDs[] = $objID;
            if ($with_childs) {
                $this->GetAllChildenIDs($objID, $_objIDs);
            }
        }

        $files = [];
        foreach ($_objIDs as $objID) {
            $obj = IPS_GetObject($objID);
            if ($obj['ObjectType'] != OBJECTTYPE_SCRIPT) {
                continue;
            }
            $script = IPS_GetScript($objID);
            if ($script['ScriptType'] != SCRIPTTYPE_PHP) {
                continue;
            }
            $name = $obj['ObjectName'];
            $location = IPS_GetLocation($objID);
            $n = strrpos($location, '\\');
            $location = substr($location, 0, $n);
            $filename = str_replace(['.', ' ', DIRECTORY_SEPARATOR, ':'], '-', $name) . '.php';
            $filename = strtr($filename, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
            $files[] = [
                'filename' => $filename,
                'location' => $location,
                'name'     => $name,
            ];
            $fname = $filePath . DIRECTORY_SEPARATOR . $filename;
            $content = IPS_GetScriptContent($objID);
            if ($this->writeFile($fname, $content, true, $err) == false) {
                return false;
            }
        }

        $dict = [
            'Version' => 0,
            'tstamp'  => time(),
            'files'   => $files,
        ];

        $fname = $expPath . DIRECTORY_SEPARATOR . self::$DICTIONARY_FILE;
        $data = json_encode($dict, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->writeFile($fname, $data, true, $err) == false) {
            return false;
        }
        return true;
    }

    private function printFile($file)
    {
        $stateFields = [
            'removed',
            'added',
            'lost',
            'moved',
            'orphan',
            'missing',
            'modified',
            'outdated',
            'unknown',
        ];

        $state = [];
        foreach ($stateFields as $fld) {
            if (isset($file[$fld]) && $file[$fld]) {
                $state[] = $fld;
            }
        }

        $s = 'filename=' . $file['filename'] . ', scriptID=' . $file['id'] . ', states=' . implode(',', $state);
        return $s;
    }
}
