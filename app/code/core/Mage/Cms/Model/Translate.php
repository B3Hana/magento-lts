<?php

class Mage_Cms_Model_Translate
{
    protected $_lang;
    protected $_module;
    protected $_config;
    protected $_entries;

    protected const PATH_BASE = 'app/code/';
    protected const PATH_DESIGN = 'app/design/';

    public function __construct(string $lang = 'en_US', string $module = null)
    {
        $this->_lang = $lang;
        $this->_module = $module;

        $this->_initConfig();
        $this->_gatherXmlUsages();
        $this->_gatherPhpUsages();
        $this->_gatherPhtmlUsages();
    }

    protected function _initConfig()
    {
        $this->_config = [];

        $pattern = BP . DS . rtrim(self::PATH_BASE, DS) . DS . '*' . DS . '*' . DS . '*' . DS . 'etc' . DS . 'config.xml';
        $configFiles = glob($pattern) ?: [];

        foreach ($configFiles as $configFile) {
            $moduleDir  = dirname(dirname($configFile));
            $moduleName = basename(dirname($moduleDir)) . '_' . basename($moduleDir);

            $xml = simplexml_load_file($configFile);
            if (!$xml) {
                continue;
            }

            $helpersNodes = $xml->xpath('global/helpers');
            $helperAliases = [];
            if (!$helpersNodes) {
                if (!str_starts_with($moduleName, 'Mage_')) {
                    continue;
                }
                $helperAliases[] = strtolower(substr($moduleName, 5));
            } else {
                foreach ($helpersNodes[0]->children() as $helperAlias => $helperConfig) {
                    $helperAliases[] = $helperAlias;
                }
            }

            $files = [];
            foreach (['global', 'frontend', 'adminhtml'] as $area) {
                $filesNodes = $xml->xpath("{$area}/translate/modules/{$moduleName}/files");
                if ($filesNodes) {
                    foreach ($filesNodes[0]->children() as $file) {
                        $fileName = (string) $file;
                        if ($fileName !== '') {
                            $files[] = $fileName;
                        }
                    }
                }
            }
            $files = array_values(array_unique($files));

            $localeBase = BP . DS . 'app' . DS . 'locale' . DS . $this->_lang . DS;
            $parser = new Varien_File_Csv();
            $parser->setDelimiter(',');
            $translations = [];
            foreach ($files as $fileName) {
                $csvPath = $localeBase . $fileName;
                if (file_exists($csvPath)) {
                    $translations = array_merge($translations, array_keys($parser->getDataPairs($csvPath)));
                }
            }
            $translations = array_values(array_unique($translations));

            foreach ($helperAliases as $helperAlias) {
                $this->_config[$helperAlias] = [
                    'module'       => $moduleName,
                    'module_dir'   => $moduleDir,
                    'files'        => $files,
                    'translations' => $translations,
                ];
            }
        }
    }

    protected function _gatherXmlUsages()
    {
        $this->_entries = [];
        foreach ($this->_config as $config) {
            $xmlFiles = glob($config['module_dir'] . DS . 'etc' . DS . '*.xml') ?: [];

            foreach ($xmlFiles as $xmlFile) {
                $contents = file_get_contents($xmlFile);
                if ($contents === false) {
                    continue;
                }

                try {
                    $xml = new SimpleXMLElement($contents);
                } catch (Exception $e) {
                    continue;
                }

                $this->_traverseXmlNode($xml, null);
            }
        }

        foreach ($this->_entries as $module => $strings) {
            $this->_entries[$module] = array_values(array_unique($strings));
        }
    }

    protected function _traverseXmlNode(SimpleXMLElement $node, ?string $currentModule): void
    {
        if (isset($node['module'])) {
            $currentModule = (string) $node['module'];
        }

        if ($currentModule !== null && isset($node['translate'])) {
            $translateChildren = preg_split('/[\s,]+/', (string) $node['translate'], -1, PREG_SPLIT_NO_EMPTY);
            foreach ($node->children() as $child) {
                if (in_array($child->getName(), $translateChildren)) {
                    $str = (string) $child;
                    if ($str !== '') {
                        $this->_entries[$currentModule][] = $str;
                    }
                }
            }
        }

        foreach ($node->children() as $child) {
            $this->_traverseXmlNode($child, $currentModule);
        }
    }

    protected function _gatherPhpUsages()
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(BP . DS . rtrim(self::PATH_BASE, DS), RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $phpFiles = array_keys(iterator_to_array(
            new RegexIterator($iterator, '/\.php$/i', RecursiveRegexIterator::MATCH)
        ));

        foreach ($phpFiles as $phpFile) {
            // Gather direct Mage::helper calls.
            $contents = file_get_contents($phpFile);
            if ($contents === false) {
                continue;
            }

            $pattern = "/Mage::helper\(\s*['\"]([^'\"]+)['\"]\s*\)\s*->__\(\s*['\"]([^'\"]+)['\"]/s";
            if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $scope  = $match[1];
                $string = $match[2];
                if (!isset($this->_entries[$scope]) || !in_array($string, $this->_entries[$scope], true)) {
                    $this->_entries[$scope][] = $string;
                }
            }

            // Gather $this calls
            $scope = null;
            if (preg_match("/\\\$this->setUsedModuleName\(\s*['\"]([^'\"]+)['\"]\s*\)/", $contents, $moduleMatch)) {
                $scope = $moduleMatch[1];
            }

            if ($scope === null) {
                if (str_contains($phpFile, 'adminhtml') || str_contains($phpFile, 'Adminhtml')) {
                    $scope = 'adminhtml';
                } else {
                    if (preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)/', $contents, $classMatch)) {
                        $className = $classMatch[1];
                        $scope = 'undefined';
                        foreach ($this->_config as $configScope => $config) {
                            if (str_starts_with($className, $config['module'])) {
                                $scope = $configScope;
                                break;
                            }
                        }
                    } else {
                        $scope = 'undefined';
                    }
                }
            }

            if (preg_match_all("/\\\$this->__\(\s*['\"]([^'\"]+)['\"]/", $contents, $thisMatches, PREG_SET_ORDER)) {
                foreach ($thisMatches as $match) {
                    $string = $match[1];
                    if (!isset($this->_entries[$scope]) || !in_array($string, $this->_entries[$scope], true)) {
                        $this->_entries[$scope][] = $string;
                    }
                }
            }

            // Gather others
//            $others = [];
//            if (preg_match_all("/([^\n;]+)->__\(\s*['\"]([^'\"]+)['\"]/", $contents, $otherMatches, PREG_SET_ORDER)) {
//                foreach ($otherMatches as $match) {
//                    $fullCall = trim($match[0]);
//                    if (str_contains($fullCall, '$this->__') || preg_match("/Mage::helper\s*\(/", $fullCall)) {
//                        continue;
//                    }
//                    $others[] = $fullCall;
//                }
//            }
        }
    }

    protected function _gatherPhtmlUsages()
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(BP . DS . rtrim(self::PATH_DESIGN, DS), RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $phtmlFiles = array_keys(iterator_to_array(
            new RegexIterator($iterator, '/\.phtml$/i', RecursiveRegexIterator::MATCH)
        ));

        foreach ($phtmlFiles as $phtmlFile) {
            // Gather direct Mage::helper calls.
            $contents = file_get_contents($phtmlFile);
            if ($contents === false) {
                continue;
            }

            $pattern = "/Mage::helper\(\s*['\"]([^'\"]+)['\"]\s*\)\s*->__\(\s*['\"]([^'\"]+)['\"]/s";
            if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $scope  = $match[1];
                $string = $match[2];
                if (!isset($this->_entries[$scope]) || !in_array($string, $this->_entries[$scope], true)) {
                    $this->_entries[$scope][] = $string;
                }
            }
        }
    }

    public function compareAndRender(): string
    {
        $diff = [];

        foreach ($this->_entries as $scope => $strings) {
            $translations = $this->_config[$scope]['translations'] ?? [];
            foreach ($strings as $string) {
                if (!in_array($string, $translations, true)) {
                    $diff[$scope][] = ['translation' => $string, 'type' => 'missing'];
                }
            }
        }

        foreach ($this->_config as $scope => $config) {
            $entries = $this->_entries[$scope] ?? [];
            foreach ($config['translations'] as $translation) {
                if (!in_array($translation, $entries, true)) {
                    $diff[$scope][] = ['translation' => $translation, 'type' => 'obsolete'];
                }
            }
        }

        $result = '';
        foreach ($diff as $scope => $items) {
            $typeWidth        = strlen('type');
            $translationWidth = strlen('translation');
            foreach ($items as $item) {
                $typeWidth        = max($typeWidth, strlen($item['type']));
                $translationWidth = max($translationWidth, strlen($item['translation']));
            }

            $separator = '+' . str_repeat('-', $typeWidth + 2) . '+' . str_repeat('-', $translationWidth + 2) . '+';

            $result .= $scope . "\n";
            $result .= $separator . "\n";
            $result .= '| ' . str_pad('type', $typeWidth) . ' | ' . str_pad('translation', $translationWidth) . " |\n";
            $result .= $separator . "\n";
            foreach ($items as $item) {
                $result .= '| ' . str_pad($item['type'], $typeWidth) . ' | ' . str_pad($item['translation'], $translationWidth) . " |\n";
            }
            $result .= $separator . "\n\n";
        }
        return $result;
    }
}