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

    private static $FILE_LIST = 'FileList';

    private static $DIFF_TO_CUR = 'DifferenceToCurrent';
    private static $DIFF_TO_TOP = 'DifferenceToTop';

    private static $TOP_ARCHIVE = 'TopArchive';
    private static $CUR_ARCHIVE = 'CurrentArchive';

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

        $this->RegisterPropertyString('package_name', '');
        $this->RegisterPropertyString('path', '');

        $this->RegisterPropertyString('url', '');
        $this->RegisterPropertyString('update_time', '{"hour":0,"minute":0,"second":0}');

        $this->RegisterPropertyString('mapping_function', 'GetLocalConfig');

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

        $package_name = $this->ReadPropertyString('package_name');
        if ($package_name == '') {
            $this->SendDebug(__FUNCTION__, '"package_name" is missing', 0);
            $r[] = $this->Translate('Package name must be specified');
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

        $vpos = 100;
        $this->MaintainMedia(self::$FILE_LIST, $this->Translate('List of scripts'), MEDIATYPE_DOCUMENT, '.txt', false, $vpos++, true);
        $this->MaintainMedia(self::$DIFF_TO_CUR, $this->Translate('Local changes'), MEDIATYPE_DOCUMENT, '.txt', false, $vpos++, true);
        $this->MaintainMedia(self::$DIFF_TO_TOP, $this->Translate('Changes to the latest version'), MEDIATYPE_DOCUMENT, '.txt', false, $vpos++, true);
        $this->MaintainMedia(self::$CUR_ARCHIVE, $this->Translate('Archive of the local version'), MEDIATYPE_DOCUMENT, '.zip', false, $vpos++, true);
        $this->MaintainMedia(self::$TOP_ARCHIVE, $this->Translate('Archive of the new version'), MEDIATYPE_DOCUMENT, '.zip', false, $vpos++, true);

        $package_name = $this->ReadPropertyString('package_name');
        $this->SetSummary($package_name);

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
            'name'    => 'package_name',
            'type'    => 'ValidationTextBox',
            'caption' => 'Package name',
        ];

        $formElements[] = [
            'name'    => 'path',
            'type'    => 'ValidationTextBox',
            'caption' => 'Local path'
        ];

        $formElements[] = [
            'name'    => 'url',
            'type'    => 'ValidationTextBox',
            'width'   => '80%',
            'caption' => 'URL to download zip archive',
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

    private function state_names()
    {
        $names = [
            'added',
            'removed',
            'lost',
            'moved',
            'orphan',
            'missing',
            'modified',
            'renamed',
            'outdated',
            'unknown',
        ];

        return $names;
    }

    private function state2text($state)
    {
        $map = [
            'added'    => 'added to repository',
            'removed'  => 'deleted in repository',
            'lost'     => 'missing in repository',
            'moved'    => 'local moved',
            'orphan'   => 'parent missing',
            'missing'  => 'local missing',
            'modified' => 'local modified',
            'renamed'  => 'local renamed',
            'outdated' => 'updateable',
            'unknown'  => 'keyword/value missing',
        ];

        if (isset($map[$state])) {
            return $this->Translate($map[$state]);
        }
        return $state;
    }

    private function build_state($file)
    {
        $state = [];
        foreach ($this->state_names() as $name) {
            if (isset($file[$name]) && $file[$name] !== false) {
                $state[] = $this->state2text($name);
            }
        }
        if ($state == []) {
            $state[] = 'ok';
        }
        return implode(', ', $state);
    }

    private function build_states($file)
    {
        $states = [];
        foreach ($this->state_names() as $name) {
            if (isset($file[$name]) && $file[$name] !== false) {
                $states[] = [
                    'name'  => $this->state2text($name),
                    'info'  => ($file[$name] !== true ? $file[$name] : ''),
                ];
            }
        }
        if ($states == []) {
            $states[] = [
                'name'  => 'ok',
                'info'  => '',
            ];
        }
        return $states;
    }

    private function build_values_FileList()
    {
        $files = $this->ReadFileList();

        $values = [];
        foreach ($files as $file) {
            $values[] = [
                'filename' => $file['filename'],
                'name'     => $file['name'],
                'location' => $file['location'],
                'path'     => $file['location'] . '\\' . $file['name'],
                'id'       => $this->IsValidID($file['id']) ? ('#' . $file['id']) : '',
                'state'    => $this->build_state($file),
                'states'   => $this->build_states($file),
            ];
        }
        return $values;
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

        $topPath = $this->getSubPath(self::$TOP_DIR);
        $topDict = $this->readDictonary($topPath);
        $curPath = $this->getSubPath(self::$CUR_DIR);
        $curDict = $this->readDictonary($curPath);

        $values = $this->build_values_FileList();
        $n_values = count($values) > 0 ? min(count($values), 20) : 1;

        $curVersion = isset($curDict['version']) ? $curDict['version'] : '';
        $curTimestamp = isset($curDict['tstamp']) ? date('d.m.Y H:i:s', (int) $curDict['tstamp']) : '';
        $topVersion = isset($topDict['version']) ? $topDict['version'] : '';
        $topTimestamp = isset($topDict['tstamp']) ? date('d.m.Y H:i:s', (int) $topDict['tstamp']) : '';

        $files = $this->ReadFileList();
        $counts = $this->count_state($files);
        $cs = [];
        foreach ($this->state_names() as $name) {
            if (isset($counts[$name]) && $counts[$name] > 0) {
                $cs[] = $this->state2text($name) . '=' . $counts[$name];
            }
        }
        $s = count($cs) ? implode(', ', $cs) : 'ok';

        $formActions[] = [
            'type'    => 'RowLayout',
            'items'   => [
                [
                    'type'    => 'ColumnLayout',
                    'items'   => [
                        [
                            'type'    => 'Label',
                            'caption' => 'Installed version',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'Repository version',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'Total state',
                        ],
                    ],
                ],
                [
                    'type'    => 'ColumnLayout',
                    'items'   => [
                        [
                            'type'    => 'Label',
                            'name'    => 'CurVersion',
                            'caption' => $curVersion . ' (' . $curTimestamp . ')',
                        ],
                        [
                            'type'    => 'Label',
                            'name'    => 'TopVersion',
                            'caption' => $topVersion . ' (' . $topTimestamp . ')',
                        ],
                        [
                            'type'    => 'Label',
                            'name'    => 'TotalState',
                            'caption' => $s,
                        ],
                    ],
                ],
            ],
        ];

        $onClick_FileList = 'IPS_RequestAction($id, "UpdateFormField_FileList", json_encode($FileList));';
        $formActions[] = [
            'type'     => 'List',
            'name'     => self::$FILE_LIST,
            'columns'  => [
                [
                    'name'     => 'path',
                    'width'    => 'auto',
                    'caption'  => 'Path',
                    'onClick'  => $onClick_FileList,
                ],
                [
                    'name'     => 'state',
                    'width'    => '250px',
                    'caption'  => 'State',
                    'onClick'  => $onClick_FileList,
                ],
                [
                    'name'     => 'filename',
                    'width'    => '300px',
                    'caption'  => 'Filename',
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
            'rowCount' => $n_values,
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
                    'caption'  => 'Show item',
                    'name'     => 'ShowItem',
                    'visible'  => false,
                    'popup'    => [
                        'caption'   => 'Show item',
                        'items'     => [
                            [
                                'type'     => 'List',
                                'name'     => 'ShowItem_List',
                                'columns'  => [
                                    [
                                        'name'     => 'title',
                                        'width'    => '100px',
                                        'caption'  => 'Title',
                                    ],
                                    [
                                        'name'     => 'value',
                                        'width'    => 'auto',
                                        'caption'  => 'Value',
                                    ],
                                ],
                                'add'      => false,
                                'delete'   => false,
                                'values'   => [],
                                'rowCount' => 1
                            ],
                        ],
                        'closeCaption' => 'Cancel',
                    ],
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
                    'onClick' => 'IPS_RequestAction($id, "SearchMissing", ""); IPS_RequestAction($id, "Refresh_FileList", "");',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Perform adjustment',
                    'onClick' => 'IPS_RequestAction($id, "PerformAdjustment", ""); IPS_RequestAction($id, "Refresh_FileList", "");',
                ],
            ],
        ];

        $opts = [
            [
                'caption' => 'content of top level',
                'value'   => self::$TOP_DIR,
            ],
        ];
        @$mediaID = $this->GetIDForIdent('CurrentArchive');
        if ($mediaID == false) {
            $opts[] = [
                'caption' => 'content of current used version',
                'value'   => self::$CUR_DIR,
            ];
        }

        $formActions[] = [
            'type'     => 'PopupButton',
            'caption'  => 'Load zip archive',
            'popup'    => [
                'caption'   => 'Load zip archive to media object',
                'items'     => [
                    [
                        'type'    => 'Select',
                        'name'    => 'Load_Destination',
                        'caption' => 'Destination',
                        'options' => $opts,
                    ],
                    [
                        'type'    => 'SelectFile',
                        'caption' => 'ZIP archive',
                        'name'    => 'Load_Content',
                    ],
                ],
                'buttons' => [
                    [
                        'caption' => 'Load',
                        'onClick' => 'IPS_RequestAction($id, "Save2Media", json_encode([ "ident" => $Load_Destination, "content" => $Load_Content ]));',
                    ],
                ],
                'closeCaption' => 'Cancel',
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

        IPS_RequestAction($this->InstanceID, 'Refresh_FileList', '');
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
                $this->AddModuleActivity('manual disconnected "' . $filename . '" from script ' . $scriptID, 0);
            } else {
                $file['id'] = $scriptID;
                $file['missing'] = false;
                $msgV[] = 'connect';
                if ($adjustLocation) {
                    $file['orphan'] = false;
                    $file['moved'] = false;
                    $msgV[] = 'adjust location';
                }
                $this->AddModuleActivity('manual connected "' . $filename . '" to script ' . $scriptID, 0);
            }
            $file['added'] = false;
            $files[$index] = $file;

            $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
        }

        foreach ($msgFileV as $msgFile) {
            $this->SendDebug(__FUNCTION__, $this->printFile($msgFile['file']) . ' => ' . implode(', ', $msgFile['msgV']), 0);
        }

        $this->WriteFileList($files);

        IPS_RequestAction($this->InstanceID, 'Refresh_FileList', '');
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

    private function SyncRepository($subdir)
    {
        $this->SendDebug(__FUNCTION__, 'subdir=' . $subdir, 0);

        $basePath = $this->getBasePath();
        $path = $this->getSubPath($subdir);

        switch ($subdir) {
            case self::$TOP_DIR:
                $ident = self::$TOP_ARCHIVE;
                break;
            case self::$CUR_DIR:
                $ident = self::$CUR_ARCHIVE;
                break;
            case self::$CHG_DIR:
                $ident = self::$CUR_ARCHIVE;
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'unsupported subdir ' . $subdir, 0);
                return false;
        }

        @$mediaID = $this->GetIDForIdent($ident);
        if ($mediaID != false) {
            $media = IPS_GetMedia($mediaID);
            $zipPath = IPS_GetKernelDir() . $media['MediaFile'];
            if (file_exists($zipPath) == false) {
                @$mediaID = false;
            }
        }
        if ($mediaID == false && $subdir != self::$TOP_DIR) {
            @$mediaID = $this->GetIDForIdent(self::$TOP_ARCHIVE);
        }
        if ($mediaID == false) {
            $this->SendDebug(__FUNCTION__, 'no archive found for subdir ' . $subdir, 0);
            return false;
        }
        $media = IPS_GetMedia($mediaID);
        $zipPath = IPS_GetKernelDir() . $media['MediaFile'];

        if ($this->changeDir($basePath) == false) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to open zip archive "' . $zipPath . '"', 0);
            return false;
        }

        $filename = $zip->getNameIndex(0);
        $targetPath = $basePath . DIRECTORY_SEPARATOR . basename($filename);

        if (file_exists($targetPath)) {
            $this->SendDebug(__FUNCTION__, 'remove directory ' . $targetPath, 0);
            if ($this->rmDir($targetPath) == false) {
                $zip->close();
                return false;
            }
        }

        if (file_exists($path)) {
            $this->SendDebug(__FUNCTION__, 'remove directory ' . $path, 0);
            if ($this->rmDir($path) == false) {
                $zip->close();
                return false;
            }
        }

        $this->SendDebug(__FUNCTION__, 'extract ' . $zipPath . ' (' . $subdir . ')', 0);
        $zip->extractTo($basePath);
        $zip->close();

        if (rename($targetPath, $path) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to rename directory ' . $targetPath . ' to ' . $path, 0);
            return false;
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
        if ($url != '') {
            $this->SendDebug(__FUNCTION__, 'update from ' . $url, 0);
            $content = file_get_contents($url);
            if ($content != false) {
                $this->SetMediaContent(self::$TOP_ARCHIVE, $content);
            }
        }

        $path = $this->ReadPropertyString('path');
        $basePath = $this->getBasePath();
        $topPath = $this->getSubPath(self::$TOP_DIR);
        $curPath = $this->getSubPath(self::$CUR_DIR);

        $dirs = [$path, $basePath, $topPath, $curPath];
        foreach ($dirs as $dir) {
            if ($this->checkDir($dir, true) == false) {
                $this->SetValue('State', self::$STATE_FAULTY);
                IPS_SemaphoreLeave($this->SemaphoreID);
                return false;
            }
        }

        if ($this->SyncRepository(self::$TOP_DIR) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($this->changeDir($topPath) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }

        if ($this->SyncRepository(self::$CUR_DIR) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
        }
        if ($this->changeDir($curPath) == false) {
            $this->SetValue('State', self::$STATE_FAULTY);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return false;
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

        $counts = $this->count_state($files);
        if ($counts['lost'] || $counts['missing'] || $counts['moved'] || $counts['renamed'] || $counts['orphan'] || $counts['unknown']) {
            $state = self::$STATE_UNCLEAR;
        } elseif ($counts['modified']) {
            $state = self::$STATE_MODIFIED;
        } elseif ($counts['added'] || $counts['removed'] || $counts['outdated']) {
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
            case 'Refresh_FileList':
                $values = $this->build_values_FileList();
                $n_values = count($values) > 0 ? min(count($values), 20) : 1;

                $this->UpdateFormField(self::$FILE_LIST, 'values', json_encode($values));
                $this->UpdateFormField(self::$FILE_LIST, 'rowCount', $n_values);

                $files = $this->ReadFileList();
                $counts = $this->count_state($files);
                $cs = [];
                foreach ($this->state_names() as $name) {
                    if (isset($counts[$name]) && $counts[$name] > 0) {
                        $cs[] = $this->state2text($name) . '=' . $counts[$name];
                    }
                }
                $s = count($cs) ? implode(', ', $cs) : 'ok';

                $this->UpdateFormField('TotalState', 'caption', $s);

                $curPath = $this->getSubPath(self::$CUR_DIR);
                $curDict = $this->readDictonary($curPath);
                $curVersion = isset($curDict['version']) ? $curDict['version'] : '';
                $curTimestamp = isset($curDict['tstamp']) ? date('d.m.Y H:i:s', (int) $curDict['tstamp']) : '';
                $this->UpdateFormField('CurVersion', 'caption', $curVersion . ' (' . $curTimestamp . ')');

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

                $this->UpdateFormField('DeleteItem', 'visible', true);

                $values = [
                    [
                        'title' => $this->Translate('Filename'),
                        'value' => $filename,
                    ],
                    [
                        'title' => $this->Translate('Name'),
                        'value' => $name,
                    ],
                    [
                        'title' => $this->Translate('Location'),
                        'value' => $location,
                    ],
                ];
                $title = $this->Translate('State');
                foreach ($jparams['states'] as $state) {
                    $values[] = [
                        'title' => $title,
                        'value' => $state['name'],
                    ];
                    $title = '';
                    if ($state['info'] != '') {
                        $v = is_array($state['info']) ? implode(', ', $state['info']) : $state['info'];
                        $values[] = [
                            'title' => '',
                            'value' => '(' . $v . ')',
                        ];
                    }
                }
                $n_values = count($values);
                $this->UpdateFormField('ShowItem', 'visible', true);
                $this->UpdateFormField('ShowItem_List', 'values', json_encode($values));
                $this->UpdateFormField('ShowItem_List', 'rowCount', $n_values);
                break;
            case 'Save2Media':
                $jparams = json_decode($value, true);
                $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', params=' . print_r($jparams, true), 0);
                $ident = $jparams['ident'];
                $content = $jparams['content'];
                $this->Save2Media($ident, $content);
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
        $path = $this->ReadPropertyString('path');
        $package_name = $this->ReadPropertyString('package_name');
        return $path . DIRECTORY_SEPARATOR . $package_name;
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

    private function CheckFileList(&$msgFileV)
    {
        $basePath = $this->getBasePath();
        $topPath = $this->getSubPath(self::$TOP_DIR);
        $curPath = $this->getSubPath(self::$CUR_DIR);

        $topDict = $this->readDictonary($topPath);
        if ($topDict === false) {
            $this->SendDebug(__FUNCTION__, 'no valid top-dictionary', 0);
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'top-dictionary=' . print_r($topDict, true), 0);

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
            'added'    => false,
            'removed'  => false,
            'lost'     => false,
            'moved'    => false,
            'orphan'   => false,
            'missing'  => false,
            'modified' => false,
            'renamed'  => false,
            'outdated' => false,
            'unknown'  => false,
        ];

        $updateableFiles = [];
        foreach ($curFiles as $curFile) {
            $topFname = $topPath . DIRECTORY_SEPARATOR . self::$FILE_DIR . DIRECTORY_SEPARATOR . $curFile['filename'];
            $curFname = $curPath . DIRECTORY_SEPARATOR . self::$FILE_DIR . DIRECTORY_SEPARATOR . $curFile['filename'];
            if ($this->readFile($topFname, $topContent, $err) == false) {
                continue;
            }
            if ($this->readFile($curFname, $curContent, $err) == false) {
                continue;
            }
            if (strcmp($curContent, $topContent) != 0) {
                $updateableFiles[] = $curFile['filename'];
            }
        }
        $this->SendDebug(__FUNCTION__, 'updateableFiles=' . print_r($updateableFiles, true), 0);

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
                if ($scriptID == 0) {
                    $msgV[] = 'not longer in repository';
                    $msgFileV[$curFile['filename']] = ['file' => $curFile, 'msgV' => $msgV];
                    // $this->SendDebug(__FUNCTION__, '1:'.print_r($msgFileV[$curFile['filename']], true),0);
                    continue;
                }
                $newFile['lost'] = $err;
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
                    if (IPS_GetName($scriptID) != $newFile['name']) {
                        $newFile['renamed'] = IPS_GetName($scriptID);
                        $msgV[] = 'script is renamed';
                    } else {
                        $newFile['renamed'] = false;
                    }
                }
            }

            $newFile['requires'] = [];
            foreach ($topFiles as $topFile) {
                if ($topFile['filename'] == $curFile['filename']) {
                    $newFile['requires'] = isset($topFile['requires']) ? $topFile['requires'] : [];
                    break;
                }
            }

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
                $newFile['moved'] = IPS_GetLocation($scriptID);
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
                    }
                }
                if ($unknown_keywords != []) {
                    $newFile['unknown'] = $unknown_keywords;
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

        $sys = IPS_GetKernelPlatform();
        switch ($sys) {
            case 'Ubuntu':
            case 'Raspberry Pi':
            case 'Docker':
            case 'Ubuntu (Docker)':
            case 'Raspberry Pi (Docker)':
                $diff_cmd = 'diff -ru';
                break;
            case 'SymBox':
                $diff_cmd = 'diff -r';
                break;
            default:
                $diff_cmd = '';
                break;
        }

        if ($diff_cmd == '') {
            $this->SendDebug(__FUNCTION__, 'no diff command, no patch file', 0);
            return true;
        }

        $chgPath = $this->getSubPath(self::$CHG_DIR);
        if ($this->SyncRepository(self::$CHG_DIR) == false) {
            return false;
        }

        foreach ($newFiles as $index => $newFile) {
            $scriptID = $newFile['id'];
            if ($scriptID == 0) {
                continue;
            }
            if ($newFile['modified'] !== false) {
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

        if ($this->diffFiles($chgPath, $curPath, $patchContent) == false) {
            return false;
        }
        $this->SetMediaContent(self::$DIFF_TO_CUR, $patchContent);

        if ($this->diffFiles($chgPath, $topPath, $patchContent) == false) {
            return false;
        }
        $this->SetMediaContent(self::$DIFF_TO_TOP, $patchContent);

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
            $msgV = isset($msgFileV[$file['filename']]['msgV']) ? $msgFileV[$file['filename']]['msgV'] : [];

            if ($file['id'] != 0) {
                continue;
            }

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
            return false;
        }

        $content = $this->GetMediaContent(self::$TOP_ARCHIVE);
        $this->SetMediaContent(self::$CUR_ARCHIVE, $content);
        if ($this->SyncRepository(self::$CUR_DIR) == false) {
            return false;
        }

        $basePath = $this->getBasePath();
        $curPath = $this->getSubPath(self::$CUR_DIR);

        $msgFileV = [];
        $this->CheckFileList($msgFileV);

        $files = $this->ReadFileList();
        foreach ($files as $index => $file) {
            $msgV = isset($msgFileV[$file['filename']]['msgV']) ? $msgFileV[$file['filename']]['msgV'] : [];

            $curContent = '';
            if ($file['missing'] !== false || $file['modified'] !== false) {
                $fname = $curPath . DIRECTORY_SEPARATOR . self::$FILE_DIR . DIRECTORY_SEPARATOR . $file['filename'];
                if ($this->readFile($fname, $curContent, $err) == false) {
                    $msgV[] = 'can\'t read file from repository';
                    $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                    $this->AddModuleActivity('unable to read file "' . $file['filename'] . '"', 0);
                    continue;
                }
            }

            if ($file['missing'] !== false) {
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
                $name = $file['name'];
                $nV = [];
                foreach (IPS_GetChildrenIDs($parID) as $cID) {
                    $nV[] = IPS_GetName($cID);
                }
                sort($nV);
                $idx = 0;
                foreach ($nV as $n) {
                    if ($n == $name) {
                        $idx = 1;
                    } elseif (preg_match('/' . $name . ' \(([0-9]+)\)$/', $n, $r)) {
                        $idx = $r[1];
                    }
                }
                if ($idx > 0) {
                    $msgV[] = 'script with name "' . $name . '" already exists';
                    $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                    $this->AddModuleActivity('problem while creating script ' . $scriptID . ' from "' . $file['filename'] . '" (duplicate)', 0);
                    $name .= ' (' . ($idx + 1) . ')';
                }
                if (IPS_SetName($scriptID, $name) == false) {
                    $this->SendDebug(__FUNCTION__, 'unable to set name "' . $name . '" to script ' . $scriptID, 0);
                    $msgV[] = 'unable to set script name "' . $name . '"';
                    $msgFileV[$file['filename']] = ['file' => $file, 'msgV' => $msgV];
                    $this->AddModuleActivity('failure occured while creating script ' . $scriptID . ' from "' . $file['filename'] . '" (name)', 0);
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

            if ($file['modified'] !== false) {
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

        $package_name = $this->ReadPropertyString('package_name');

        $keywords = [
            'Source',
            'Source file',
            'Target location',
            'Designation',
        ];

        $new_lines = [];
        $new_lines = [
            $this->Translate('Source') . ': ' . $package_name,
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
        $s = $this->GetMediaContent(self::$FILE_LIST);
        @$files = json_decode((string) $s, true);
        if ($files == false) {
            $files = [];
        }
        return $files;
    }

    private function cmp_fileList($a, $b)
    {
        $a_fullname = $a['location'] . '\\' . $a['name'];
        $b_fullname = $b['location'] . '\\' . $b['name'];
        if ($a_fullname != $b_fullname) {
            return (strcmp($a_fullname, $b_fullname) < 0) ? -1 : 1;
        }

        if ($a['filename'] != $b['filename']) {
            return (strcmp($a['filename'], $b['filename']) < 0) ? -1 : 1;
        }

        return ($a['id'] < $b['id']) ? -1 : 1;
    }

    private function WriteFileList($files)
    {
        usort($files, [__CLASS__, 'cmp_fileList']);
        $s = json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->GetMediaContent(self::$FILE_LIST) != $s) {
            $this->SetMediaContent(self::$FILE_LIST, $s);
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
            'version' => 0,
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

    private function count_state($files)
    {
        $counts = [
            'added'     => 0,
            'removed'   => 0,
            'lost'      => 0,
            'moved'     => 0,
            'orphan'    => 0,
            'missing'   => 0,
            'modified'  => 0,
            'renamed'   => 0,
            'outdated'  => 0,
            'unknown'   => 0,
        ];

        foreach ($files as $file) {
            if ($file['added'] !== false) {
                $counts['added']++;
            }
            if ($file['removed'] !== false) {
                $counts['removed']++;
            }
            if ($file['lost'] !== false) {
                $counts['lost']++;
            }
            if ($file['moved'] !== false) {
                $counts['moved']++;
            }
            if ($file['orphan'] !== false) {
                $counts['orphan']++;
            }
            if ($file['missing'] !== false) {
                $counts['missing']++;
            }
            if ($file['modified'] !== false) {
                $counts['modified']++;
            }
            if ($file['renamed'] !== false) {
                $counts['renamed']++;
            }
            if ($file['outdated'] !== false) {
                $counts['outdated']++;
            }
            if ($file['unknown'] !== false) {
                $counts['unknown']++;
            }
        }
        return $counts;
    }

    private function printFile($file)
    {
        $state = [];
        foreach ($this->state_names() as $name) {
            if (isset($file[$name]) && $file[$name] !== false) {
                $state[] = $name;
            }
        }
        if ($state == []) {
            $state[] = 'ok';
        }

        $s = 'filename=' . $file['filename'] . ', scriptID=' . $file['id'] . ', states=' . implode(',', $state);
        return $s;
    }

    private function Save2Media($ident, $content)
    {
        if ($content == '') {
            $this->SendDebug(__FUNCTION__, 'no content -> ignore', 0);
            return true;
        }
        $this->SetMediaContent($ident, base64_decode($content));
        return true;
    }

    private function diffFiles($from, $to, &$diff)
    {
        $sys = IPS_GetKernelPlatform();
        switch ($sys) {
            case 'Ubuntu':
            case 'Raspberry Pi':
            case 'Docker':
                $cmd = 'diff -ru ' . $from . ' ' . $to;
                break;
            case 'SymBox':
            case 'Ubuntu (Docker)':
            case 'Raspberry Pi (Docker)':
                $cmd = 'diff -r ' . $from . ' ' . $to;
                break;
            default:
                $cmd = '';
                break;
        }

        $time_start = microtime(true);
        $data = exec($cmd, $out, $exitcode);
        $duration = round(microtime(true) - $time_start, 2);

        if ($exitcode == 0) {
            $this->SendDebug(__FUNCTION__, ' ... equal', 0);
            $diff = '';
            return true;
        }
        if ($exitcode == 1) {
            $this->SendDebug(__FUNCTION__, ' ... differ', 0);
            $diff = implode(PHP_EOL, $out);
            return true;
        }
        $this->SendDebug(__FUNCTION__, ' ... exitcode=' . $exitcode, 0);
        return false;
    }
}
