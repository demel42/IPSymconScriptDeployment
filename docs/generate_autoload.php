<?php

declare(strict_types=1);

$instID = xxxxx; // ID einer ScriptDeployment-Instanz

$autoload_content = <<<'EOL'
<?php
  
declare(strict_types=1);

$scriptID = yyyyy; // Script "Basisfunktionen"

if (IPS_ObjectExists($scriptID)) {
    require_once IPS_GetScriptFile($scriptID);
}

EOL;

$err = '';
ScriptDeployment_WriteAutoload($instID, $autoload_content, true, $err);
if ($err != '') {
    echo 'ScriptDeployment_WriteAutoload() failed: ' . $err;
}

$content = ScriptDeployment_ReadAutoload($instID, $err);
if ($err != '') {
    echo 'ScriptDeployment_ReadAutoload() failed: ' . $err;
}

echo '==== current content of "__autoload.php" ==========' . PHP_EOL . $content . '===' . PHP_EOL;
