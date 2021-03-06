<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer;

use Composer\Util\IniHelper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class XdebugHandler
{
    const ENV_ALLOW = 'COMPOSER_ALLOW_XDEBUG';
    const ENV_VERSION = 'COMPOSER_XDEBUG_VERSION';
    const RESTART_ID = 'internal';

    private $output;
    private $loaded;
    private $envScanDir;
    private $version;
    private $tmpIni;

    /**
     * Constructor
     */
    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $this->loaded = extension_loaded('xdebug');
        $this->envScanDir = getenv('PHP_INI_SCAN_DIR');

        if ($this->loaded) {
            $ext = new \ReflectionExtension('xdebug');
            $this->version = strval($ext->getVersion());
        }
    }

    /**
     * Checks if xdebug is loaded and composer needs to be restarted
     *
     * If so, then a tmp ini is created with the xdebug ini entry commented out.
     * If additional inis have been loaded, these are combined into the tmp ini
     * and PHP_INI_SCAN_DIR is set to an empty value. Current ini locations are
     * are stored in COMPOSER_ORIGINAL_INIS, for use in the restarted process.
     *
     * This behaviour can be disabled by setting the COMPOSER_ALLOW_XDEBUG
     * environment variable to 1. This variable is used internally so that the
     * restarted process is created only once and PHP_INI_SCAN_DIR can be
     * restored to its original value.
     */
    public function check()
    {
        $args = explode('|', strval(getenv(self::ENV_ALLOW)), 2);

        if ($this->needsRestart($args[0])) {
            $this->prepareRestart($command) && $this->restart($command);
            return;
        }

        // Restore environment variables if we are restarting
        if (self::RESTART_ID === $args[0]) {
            putenv(self::ENV_ALLOW);

            if (false !== $this->envScanDir) {
                // $args[1] contains the original value
                if (isset($args[1])) {
                    putenv('PHP_INI_SCAN_DIR='.$args[1]);
                } else {
                    putenv('PHP_INI_SCAN_DIR');
                }
            }
        }
    }

    /**
     * Executes the restarted command then deletes the tmp ini
     *
     * @param string $command
     */
    protected function restart($command)
    {
        passthru($command, $exitCode);

        if (!empty($this->tmpIni)) {
            @unlink($this->tmpIni);
        }

        exit($exitCode);
    }

    /**
     * Returns true if a restart is needed
     *
     * @param string $allow Environment value
     *
     * @return bool
     */
    private function needsRestart($allow)
    {
        if (PHP_SAPI !== 'cli' || !defined('PHP_BINARY')) {
            return false;
        }

        return empty($allow) && $this->loaded;
    }

    /**
     * Returns true if everything was written for the restart
     *
     * If any of the following fails (however unlikely) we must return false to
     * stop potential recursion:
     *   - tmp ini file creation
     *   - environment variable creation
     *
     * @param null|string $command The command to run, set by method
     *
     * @return bool
     */
    private function prepareRestart(&$command)
    {
        $this->tmpIni = '';
        $iniPaths = IniHelper::getAll();
        $files = $this->getWorkingSet($iniPaths, $replace);

        if ($this->writeTmpIni($files, $replace)) {
            $command = $this->getCommand();
            return $this->setEnvironment($iniPaths);
        }

        return false;
    }

    /**
     * Returns true if the tmp ini file was written
     *
     * The filename is passed as the -c option when the process restarts.
     *
     * @param array $iniFiles The php.ini locations
     * @param bool $replace Whether the files need modifying
     *
     * @return bool
     */
    private function writeTmpIni(array $iniFiles, $replace)
    {
        if (empty($iniFiles)) {
            // Unlikely, maybe xdebug was loaded through a command line option.
            return true;
        }

        if (!$this->tmpIni = tempnam(sys_get_temp_dir(), '')) {
            return false;
        }

        $content = '';
        foreach ($iniFiles as $file) {
            $content .= $this->getIniData($file, $replace);
        }

        return @file_put_contents($this->tmpIni, $content);
    }

    /**
     * Returns an array of ini files to use
     *
     * @param array $iniPaths Locations used by the current prcoess
     * @param null|bool $replace Whether the files need modifying, set by method
     *
     * @return array
     */
    private function getWorkingSet(array $iniPaths, &$replace)
    {
        $replace = true;
        $result = array();

        if (empty($iniPaths[0])) {
            // There is no loaded ini
            array_shift($iniPaths);
        }

        foreach ($iniPaths as $file) {
            if (preg_match('/xdebug.ini$/', $file)) {
                // Skip the file, no need for regex replacing
                $replace = false;
            } else {
                $result[] = $file;
            }
        }

        return $result;
    }

    /**
     * Returns formatted ini file data
     *
     * @param string $iniFile The location of the ini file
     * @param bool $replace Whether to regex replace content
     *
     * @return string The ini data
     */
    private function getIniData($iniFile, $replace)
    {
        $contents = file_get_contents($iniFile);
        $data = PHP_EOL;

        if ($replace) {
            // Comment out xdebug config
            $regex = '/^\s*(zend_extension\s*=.*xdebug.*)$/mi';
            $data .= preg_replace($regex, ';$1', $contents);
        } else {
            $data .= $contents;
        }

        return $data;
    }

    /**
     * Returns the restart command line
     *
     * @return string
     */
    private function getCommand()
    {
        $phpArgs = array(PHP_BINARY, '-c', $this->tmpIni);
        $params = array_merge($phpArgs, $this->getScriptArgs($_SERVER['argv']));

        return implode(' ', array_map(array($this, 'escape'), $params));
    }

    /**
     * Returns true if the restart environment variables were set
     *
     * @param array $iniPaths Locations used by the current prcoess
     *
     * @return bool
     */
    private function setEnvironment(array $iniPaths)
    {
        // Set scan dir to an empty value if additional ini files were used
        $additional = count($iniPaths) > 1;

        if ($additional && !putenv('PHP_INI_SCAN_DIR=')) {
            return false;
        }

        // Make original inis available to restarted process
        if (!putenv(IniHelper::ENV_ORIGINAL.'='.implode(PATH_SEPARATOR, $iniPaths))) {
            return false;
        }

        // Make xdebug version available to restarted process
        if (!putenv(self::ENV_VERSION.'='.$this->version)) {
            return false;
        }

        // Flag restarted process and save env scan dir state
        $args = array(self::RESTART_ID);

        if (false !== $this->envScanDir) {
            // Save current PHP_INI_SCAN_DIR
            $args[] = $this->envScanDir;
        }

        return putenv(self::ENV_ALLOW.'='.implode('|', $args));
    }

    /**
     * Returns the restart script arguments, adding --ansi if required
     *
     * If we are a terminal with color support we must ensure that the --ansi
     * option is set, because the restarted output is piped.
     *
     * @param array $args The argv array
     *
     * @return array
     */
    private function getScriptArgs(array $args)
    {
        if (in_array('--no-ansi', $args) || in_array('--ansi', $args)) {
            return $args;
        }

        if ($this->output->isDecorated()) {
            $offset = count($args) > 1 ? 2: 1;
            array_splice($args, $offset, 0, '--ansi');
        }

        return $args;
    }

    /**
     * Escapes a string to be used as a shell argument.
     *
     * From https://github.com/johnstevenson/winbox-args
     * MIT Licensed (c) John Stevenson <john-stevenson@blueyonder.co.uk>
     *
     * @param string $arg The argument to be escaped
     * @param bool $meta Additionally escape cmd.exe meta characters
     *
     * @return string The escaped argument
     */
    private function escape($arg, $meta = true)
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            return escapeshellarg($arg);
        }

        $quote = strpbrk($arg, " \t") !== false || $arg === '';
        $arg = preg_replace('/(\\\\*)"/', '$1$1\\"', $arg, -1, $dquotes);

        if ($meta) {
            $meta = $dquotes || preg_match('/%[^%]+%/', $arg);

            if (!$meta && !$quote) {
                $quote = strpbrk($arg, '^&|<>()') !== false;
            }
        }

        if ($quote) {
            $arg = preg_replace('/(\\\\*)$/', '$1$1', $arg);
            $arg = '"'.$arg.'"';
        }

        if ($meta) {
            $arg = preg_replace('/(["^&|<>()%])/', '^$1', $arg);
        }

        return $arg;
    }
}
