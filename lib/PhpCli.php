<?php

date_default_timezone_set('America/Denver');

require dirname(__FILE__) . '/Colors.php';

defined('TIME_NOW') or define('TIME_NOW', time());
defined('DATE_FORMAT') or define('DATE_FORMAT', 'Y-m-d H:i:s');

define('REGEX_VALID_OPTION', '(\-+)?([a-zA-Z0-9\_][a-zA-Z0-9\_\-]*)');
define('REGEX_COMBINED_OPTION', REGEX_VALID_OPTION . '\=([^\s]+)');

abstract class PhpCli {
	protected $strVersion = '1.0.0';
	protected $arrAllowed = [];

	private $objColors;

	private $arrArgs = [];
	private $arrInfo = [];
	private $arrAliases = [];
	private $arrOptions = [];
	private $arrRequired = [];

	private $arrFlagTypes = [
		'action',
		'boolean'
	];

	private $arrFlagTrue = ['true', 'yes', 'on', '1'];

	private $arrDefaults = [
		'verbose' => [
			'type' => 'boolean',
			'default' => false,
			'description' => 'Extra debugging output'
		],
		'help' => [
			'type' => 'action',
			'function' => 'showHelp',
			'description' => 'Show help info'
		],
		'version' => [
			'type' => 'action',
			'function' => 'showVersion',
			'description' => 'Show version info'
		]
	];

	private $arrMsgTypeColors = [
    'log' => null, 
    'warn' => 'yellow',
    'error' => 'red',
    'debug' => 'cyan',
    'success' => 'green'
  ];

	public function __construct($arrArgs) {
		$this->objColors = new Colors();
		$this->setArgs($arrArgs);
		$this->setInfo();
		$this->initOptions();
		$this->parseArgs();
	}

	abstract public function run();

	private function initOptions() {
		$this->arrAllowed = array_merge_recursive($this->arrDefaults, $this->arrAllowed);

		foreach ($this->arrAllowed as $strOption => $arrOptionInfo) {
			$this->arrAllowed[$strOption]['type'] = $this->reduceOptionType($this->arrAllowed[$strOption]['type']);

			if (isset($arrOptionInfo['default'])) {
				$this->setOption($strOption, $arrOptionInfo['default']);
			}

			$intCount = 0;
			$strAlias = isset($arrOptionInfo['alias']) ? $arrOptionInfo['alias'] : null;

			while (is_null($strAlias) || isset($this->arrAliases[$strAlias])) {
				$intCount++;

				if ($intCount < strlen($strOption)) {
						$strAlias = substr($strOption, 0, $intCount);						
					} else {
						$strAlias = $strOption . $intCount;
					}
			}

			$this->arrAllowed[$strOption]['alias'] = $strAlias;
			$this->arrAliases[$strAlias] = $strOption;

			if (isset($arrOptionInfo['required']) && $arrOptionInfo['required'] === true) {
				$this->arrRequired[] = $strOption;
			}
		}
	}

	private function reduceOptionType($strType) {
		switch ($strType) {
			case 'dir':
			case 'folder':
			case 'directory':
				return 'directory';

			case 'file':
			case 'filename':
				return 'filename';

			case 'str':
			case 'word':
			case 'string':
				return 'string';

			case 'num':
			case 'int':
			case 'float':
			case 'double':
			case 'digit':
			case 'digits':
			case 'numeric':
			case 'number':
				return 'number';

			case 'bool':
			case 'yesno':
			case 'truefalse':
			case 'boolean':
				return 'boolean';

			case 'run':
			case 'exec':
			case 'action':
			case 'function':
				return 'action';

			default:
				return 'any';
		}
	}

	private function setArgs($arrArgs = []) {
		$this->arrArgs = $arrArgs;
	}

	private function setInfo() {
		$strFilename = $this->arrArgs[0];
		$strFilepath = realpath($strFilename);
		$strBasename = basename($strFilepath);
		$strDirectory = dirname($strFilepath);

		$this->arrInfo = [
			'filename' => $strFilename,
			'filepath' => $strFilepath,
			'basename' => $strBasename,
			'directory' => $strDirectory,
			'version' => $this->strVersion
		];
	}

	protected function getInfo($strKey = nul) {
		if (!is_null($strKey) && isset($this->arrInfo[$strKey])) {
			return $this->arrInfo[$strKey];
		} else {
			return $this->arrInfo;
		}
	}

	private function parseArgs() {
		for ($i=1; $i<count($this->arrArgs); $i++) {
			$strArg = $this->arrArgs[$i];

			$strOption = $mixValue = null;

			if (preg_match($this->getPattern(REGEX_COMBINED_OPTION), $strArg, $arrMatches)) {
				if (isset($arrMatches[2]) && isset($arrMatches[3])) {
					$strOption = trim(strtolower($arrMatches[2]));
					$mixValue = trim($arrMatches[3]);

					if (in_array($this->getOptionInfo($strOption, 'type'), $this->arrFlagTypes)) {
						$mixValue = in_array(strtolower($mixValue), $this->arrFlagTrue);
					}
				}
			} else if (preg_match($this->getPattern(REGEX_VALID_OPTION), $strArg, $arrMatches)) {
				if (isset($arrMatches[2])) {
					$strOption = trim(strtolower($arrMatches[2]));

					if (in_array($this->getOptionInfo($strOption, 'type'), $this->arrFlagTypes)) {
						$mixValue = true;
					} else if (isset($this->arrArgs[$i+1])) {
						$mixValue = trim($this->arrArgs[$i+1]);
						$i++;
					} else {
						$this->warn("Unable to set option: '{$strOption}' without a value!");
					}
				}
			} else {
				$this->error("Invalid argument: '{$strArg}'");
			}

			if (isset($this->arrAliases[$strOption])) {
				$strOption = $this->arrAliases[$strOption];
			}

			if (!is_null($strOption) && !is_null($mixValue)) {
				$this->setOption($strOption, $mixValue);
			}
		}

		foreach($this->arrRequired as $strRequired) {
			if (is_null($this->getOption($strRequired))) {
				$this->error("Missing required option: '{$strRequired}'!");
				$this->setOption('help');
			}
		}
	}

	private function validateOptionValue($strOption, $mixValue) {
		$arrOptionInfo = $this->getOptionInfo($strOption);	

		if ($arrOptionInfo) {
			if (isset($arrOptionInfo['validate']) && is_callable($arrOptionInfo['validate'])) {
				return !!$arrOptionInfo['validate']($mixValue);
			} else {
				$strType = isset($arrOptionInfo['type']) ? $arrOptionInfo['type'] : 'any';

				switch ($strType) {
					case 'action':
						return method_exists($this, $arrOptionInfo['function']);

					case 'directory':
						return is_dir($mixValue);

					case 'filename':
						return file_exists($mixValue);

					case 'string':
						return is_string($mixValue);

					case 'number':
						return is_numeric($mixValue);

					case 'boolean':
						return is_bool($mixValue) || (strtolower($mixValue) == 'true');

					default:
						$this->warn("Unable to validate option: '{$strOption}' for type: '{$strType}'");
						return true;
				}
			}
		} else {
			$this->warn("Unexpected option: '{$strOption}'");
		}
	}

	private function getOptionInfo($strOption, $strKey = null) {
		if (isset($this->arrAllowed[$strOption])) {
			$arrOptionInfo = $this->arrAllowed[$strOption];

			if ($strKey && isset($arrOptionInfo[$strKey])) {
				return $arrOptionInfo[$strKey];
			} else {
				return $arrOptionInfo;
			}
		} else if (isset($this->arrAliases[$strOption])) {
			return $this->getOptionInfo($this->arrAliases[$strOption], $strKey);
		}

		return null;
	}

	private function getPattern($strRegex) {
		return "/{$strRegex}/";
	}

	protected function getOption($strOption) {
		$strOption = trim(strtolower($strOption));

		return (isset($this->arrOptions[$strOption]) ? $this->arrOptions[$strOption] : null);
	}

	protected function setOption($strOption, $mixValue = null) {
		if ($this->validateOptionValue($strOption, $mixValue)) {
			$arrInfo = $this->getOptionInfo($strOption);

			if ($arrInfo['type'] === 'action') {
				$strFunction = $arrInfo['function'];
				$this->$strFunction();
			} else {
				$this->arrOptions[$strOption] = $mixValue;
			}			
		} else {
			$this->error("Invalid option: '{$strOption}'!");
		}
	}

	protected function log($strMsg = '', $strType = 'log') {
    $strType = trim(strtolower($strType));

    if ($strType !== 'debug' || $this->getOption('verbose')) {
    	$strPrefix = '';

    	if ($strType !== 'log') {
    		$strPrefix = strtoupper($strType) . "\t| ";
    	}

      $strColor = isset($this->arrMsgTypeColors[$strType]) ? $this->arrMsgTypeColors[$strType] : null;
      $strColored = $this->objColors->getColoredString($strPrefix . $strMsg, $strColor);

      echo $strColored . PHP_EOL;
    }
  }

  protected function info($strMsg) {
  	$this->log($this->getInfo('basename'));
  	$this->log();
  	$this->log($strMsg);
  }

  protected function debug($strMsg) {
  	$this->log($strMsg, 'debug');
  }

  protected function warn($strMsg) {
  	$this->log($strMsg, 'warn');
  }

  protected function error($strMsg) {
  	$this->log($strMsg, 'error');
  }
  
  protected function success($strMsg) {
  	$this->log($strMsg, 'success');
  }

  protected function showHelp() {
  	$intSepCount = 4;
  	$strSep = str_repeat("\t", $intSepCount);

  	$this->info('Options:');
  	$this->log("\tAlias\tOption{$strSep}Description");
  	$this->log();

  	foreach ($this->arrAllowed as $strOption => $arrOptionInfo) {
  		$strAlias = isset($arrOptionInfo['alias']) ? '(' . $arrOptionInfo['alias'] . ') ' : '';
  		$strDescription = isset($arrOptionInfo['description']) ? $arrOptionInfo['description'] : 'No description';

  		$intNumTabs = $intSepCount - floor((strlen($strOption)) / 8);

  		$strSep = str_repeat("\t", $intNumTabs);

  		$this->log("\t{$strAlias}\t{$strOption}{$strSep}- {$strDescription}");
  	}

  	exit;
  }

  protected function showVersion() {
  	$this->info('Version: ' . $this->getInfo('version'));
  	exit;
  }
}