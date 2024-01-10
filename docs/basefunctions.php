<?php

declare(strict_types=1);

if (function_exists('MapLocalConstant') == false) {
	function MapLocalConstant(string $ident)
	{
		$script_map = [
			'HELPER_GLOBAL' => xxxxx, // z.B. Hilfsfunktionen
		];
		$object_map = [
		];
		$guid2inst_map = [
			'Archive Control'  => '{43192F0B-135B-4CE7-A0A7-1475603F3060}',
			'Connect Control'  => '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}',
			'Location Control' => '{45E97A63-F870-408A-B259-2933F7EABF74}',
			'Module Control'   => '{B8A5067A-AFC2-3798-FEDC-BCD02A45615E}',
			'Store Control'    => '{F45B5D1F-56AE-4C61-9AB2-C87C63149EC3}',
			'Util Control'     => '{B69010EA-96D5-46DF-B885-24821B8C8DBD}',
		];
		$other_map = [
		];

		$ident = strtoupper($ident);
		$ret = false;
		if (isset($script_map[$ident])) {
			if (IPS_ScriptExists($script_map[$ident])) {
				$ret = $script_map[$ident];
			}
		} elseif (isset($object_map[$ident])) {
			if (IPS_ObjectExists($object_map[$ident])) {
				$ret = $object_map[$ident];
			}
		} elseif (isset($guid2inst_map[$ident])) {
			$ids = IPS_GetInstanceListByModuleID($guid2inst_map[$ident]);
			if (count($ids) > 0) {
				$ret = $ids[0];
			}
		} elseif (isset($other_map[$ident])) {
			$ret = $other_map[$ident];
		}
		return $ret;
	}
}
