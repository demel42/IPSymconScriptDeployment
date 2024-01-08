<?php

declare(strict_types=1);

$instID = xxxxx; // ID einer ScriptDeployment-Instanz

$autoload_content = <<<'EOL'
<?php
  
declare(strict_types=1);

$scriptID = xxxxx; // Script "Basisfunktionen"

if (IPS_ObjectExists($scriptID)) {
    require_once IPS_GetScriptFile($scriptID);
}

EOL;

ScriptDeployment_WriteAutoload($instID, $autoload_content, true);

$content = ScriptDeployment_ReadAutoload($instID);
echo '==== current content of "__autoload.php" ==========' . PHP_EOL . $content . '===' . PHP_EOL;
