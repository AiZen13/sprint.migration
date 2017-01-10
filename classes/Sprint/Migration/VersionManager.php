<?php

namespace Sprint\Migration;

use Sprint\Migration\Exceptions\RestartException;
use Sprint\Migration\Exceptions\MigrationException;

class VersionManager
{


    /** @var VersionConfig */
    private $versionConfig = null;

    /** @var VersionTable */
    private $versionTable = null;

    private $restarts = array();


    public function __construct($configName = '') {
        $configName = empty($configName) ? Module::getDbOption('config_name', '') : $configName;

        $this->versionConfig = new VersionConfig(
            $configName
        );

        $this->versionTable = new VersionTable(
            $this->getConfigVal('migration_table')
        );

        Module::setDbOption('config_name', $this->versionConfig->getConfigName());
    }

    public function startMigration($versionName, $action = 'up', $params = array(), $force = false) {
        /* @global $APPLICATION \CMain */
        global $APPLICATION;

        if (isset($this->restarts[$versionName])) {
            unset($this->restarts[$versionName]);
        }

        try {

            $action = ($action == 'up') ? 'up' : 'down';

            $meta = $this->getVersionByName($versionName);

            if (!$meta || empty($meta['class'])) {
                throw new MigrationException('failed to initialize migration');
            }

            if (!$force) {
                if ($action == 'up' && $meta['status'] != 'new') {
                    throw new MigrationException('migration already up');
                }

                if ($action == 'down' && $meta['status'] != 'installed') {
                    throw new MigrationException('migration already down');
                }
            }

            /** @var $versionInstance Version */
            $versionInstance = new $meta['class'];
            $versionInstance->setParams($params);

            if ($action == 'up') {
                $ok = $versionInstance->up();
            } else {
                $ok = $versionInstance->down();
            }

            if ($APPLICATION->GetException()) {
                throw new MigrationException($APPLICATION->GetException()->GetString());
            }

            if ($ok === false) {
                throw new MigrationException('migration returns false');
            }

            if ($action == 'up') {
                $ok = $this->addRecord($versionName);
            } else {
                $ok = $this->removeRecord($versionName);
            }

            if ($ok === false) {
                throw new MigrationException('unable to write migration to the database');
            }

            Out::out('%s (%s) success', $versionName, $action);
            return true;

        } catch (RestartException $e) {
            $this->restarts[$versionName] = isset($versionInstance) ? $versionInstance->getParams() : array();

        } catch (MigrationException $e) {
            Out::outError('%s (%s) error: %s', $versionName, $action, $e->getMessage());

        } catch (\Exception $e) {
            Out::outError('%s (%s) error: %s', $versionName, $action, $e->getMessage());
        }

        return false;
    }


    public function needRestart($version) {
        return (isset($this->restarts[$version])) ? 1 : 0;
    }

    public function getRestartParams($version) {
        return $this->restarts[$version];
    }

    public function createVersionFile($description = '', $prefix = '') {
        $description = $this->purifyDescriptionForFile($description);
        $prefix = $this->preparePrefix($prefix);

        $originTz = date_default_timezone_get();
        date_default_timezone_set('Europe/Moscow');
        $ts = date('YmdHis');
        date_default_timezone_set($originTz);

        $versionName = $prefix . $ts;

        list($extendUse, $extendClass) = explode(' as ', $this->getConfigVal('migration_extend_class'));
        $extendUse = trim($extendUse);
        $extendClass = trim($extendClass);

        if (!empty($extendClass)) {
            $extendUse = 'use ' . $extendUse . ' as ' . $extendClass . ';' . PHP_EOL;
        } else {
            $extendClass = $extendUse;
            $extendUse = '';
        }

        $str = $this->renderFile($this->getConfigVal('migration_template'), array(
            'version' => $versionName,
            'description' => $description,
            'extendUse' => $extendUse,
            'extendClass' => $extendClass,
        ));

        $file = $this->getVersionFile($versionName);
        file_put_contents($file, $str);

        if (!is_file($file)) {
            Out::outError('%s, error: can\'t create a file "%s"', $versionName, $file);
            return false;
        }

        return $this->getVersionByName($versionName);
    }

    public function markMigration($search, $status) {
        // $search - VersionName | new | installed | unknown
        // $status - new | installed

        $search = trim($search);
        $status = trim($status);

        $result = array();
        if (in_array($status, array('new', 'installed'))){
            if ($this->checkVersionName($search)) {
                $meta = $this->getVersionByName($search);
                $meta = !empty($meta) ? $meta : array('version' => $search);
                $result[] = $this->markMigrationByMeta($meta, $status);

            } elseif (in_array($search, array('new', 'installed', 'unknown'))) {
                $metas = $this->getVersions(array('status' => $search));
                foreach ($metas as $meta) {
                    $result[] = $this->markMigrationByMeta($meta, $status);
                }
            }
        }

        if (empty($result)){
            $result[] = array(
                'message' => GetMessage('SPRINT_MIGRATION_MARK_ERROR4'),
                'success' => false,
            );
        }

        return $result;
    }

    protected function markMigrationByMeta($meta, $status) {
        $msg = 'SPRINT_MIGRATION_MARK_ERROR3';
        $success = false;

        if ($status == 'new') {
            if ($meta['is_record']) {
                $this->removeRecord($meta['version']);
                $msg = 'SPRINT_MIGRATION_MARK_SUCCESS1';
                $success = true;
            } else {
                $msg = 'SPRINT_MIGRATION_MARK_ERROR1';
            }
        } elseif ($status == 'installed') {
            if (!$meta['is_record']) {
                $this->addRecord($meta['version']);
                $msg = 'SPRINT_MIGRATION_MARK_SUCCESS2';
                $success = true;
            } else {
                $msg = 'SPRINT_MIGRATION_MARK_ERROR2';
            }
        }

        return array(
            'message' => GetMessage($msg,array('#VERSION#' => $meta['version'])),
            'success' => $success,
        );
    }

    public function getVersionByName($versionName) {
        if ($this->checkVersionName($versionName)) {
            $isRecord = $this->isRecordExists($versionName);
            $isFile = $this->isFileExists($versionName);
            return $this->prepVersionMeta($versionName, $isFile, $isRecord);
        }
        return false;
    }

    public function getVersions($filter = array()) {
        $filter = array_merge(array('status' => '', 'search' => ''), $filter);

        $records = array();
        /* @var $dbres \CDBResult */
        $dbres = $this->getRecords();
        while ($aItem = $dbres->Fetch()) {
            $ts = $this->getVersionTimestamp($aItem['version']);
            if ($ts) {
                $records[$aItem['version']] = $ts;
            }
        }

        $files = array();
        /* @var $item \SplFileInfo */
        $directory = new \DirectoryIterator($this->getConfigVal('migration_dir'));
        foreach ($directory as $item) {
            if ($item->isFile()) {
                $fileName = pathinfo($item->getPathname(), PATHINFO_FILENAME);
                $ts = $this->getVersionTimestamp($fileName);
                if ($ts) {
                    $files[$fileName] = $ts;
                }
            }

        }

        $merge = array_merge($records, $files);
        if ($filter['status'] == 'installed' || $filter['status'] == 'unknown') {
            arsort($merge);
        } else {
            asort($merge);
        }

        $result = array();
        foreach ($merge as $version => $ts) {
            $isRecord = array_key_exists($version, $records);
            $isFile = array_key_exists($version, $files);

            $meta = $this->prepVersionMeta($version, $isFile, $isRecord);

            if (empty($filter['status']) || $filter['status'] == $meta['status']) {

                if (!empty($filter['search'])) {
                    $textindex = $meta['version'] . $meta['description'];
                    $searchword = $filter['search'];

                    $textindex = Locale::convertToUtf8IfNeed($textindex);
                    $searchword = Locale::convertToUtf8IfNeed($searchword);

                    $searchword = trim($searchword);

                    if (false !== mb_stripos($textindex, $searchword, null, 'utf-8')) {
                        $result[] = $meta;
                    }

                } else {
                    $result[] = $meta;
                }


            }
        }
        return $result;
    }

    protected function prepVersionMeta($versionName, $isFile, $isRecord) {
        $meta = array(
            'is_file' => $isFile,
            'is_record' => $isRecord,
            'version' => $versionName,
        );

        if ($isRecord && $isFile) {
            $meta['status'] = 'installed';
        } elseif (!$isRecord && $isFile) {
            $meta['status'] = 'new';
        } elseif ($isRecord && !$isFile) {
            $meta['status'] = 'unknown';
        } else {
            return false;
        }

        if (!$isFile) {
            return $meta;
        }

        $file = $this->getVersionFile($versionName);
        $meta['location'] = $file;

        ob_start();
        /** @noinspection PhpIncludeInspection */
        require_once($file);
        ob_end_clean();

        $class = 'Sprint\Migration\\' . $versionName;
        if (!class_exists($class)) {
            return $meta;
        }

        $descr = '';
        if (!method_exists($class, '__construct')) {
            /** @var $versionInstance Version */
            $versionInstance = new $class;
            $descr = $versionInstance->getDescription();
        } elseif (class_exists('\ReflectionClass')) {
            $reflect = new \ReflectionClass($class);
            $props = $reflect->getDefaultProperties();
            $descr = $props['description'];
        }

        $meta['class'] = $class;
        $meta['description'] = $this->purifyDescriptionForMeta($descr);

        return $meta;

    }


    protected function getVersionFile($versionName) {
        return $this->getConfigVal('migration_dir') . '/' . $versionName . '.php';
    }

    protected function isFileExists($versionName) {
        $file = $this->getVersionFile($versionName);
        return file_exists($file) ? 1 : 0;
    }

    protected function isRecordExists($versionName) {
        $record = $this->getRecordByName($versionName)->Fetch();
        return (empty($record)) ? 0 : 1;
    }


    public function checkVersionName($versionName) {
        return $this->getVersionTimestamp($versionName) ? true : false;
    }

    public function getVersionTimestamp($versionName) {
        $matches = array();
        if (preg_match('/[0-9]{14}$/', $versionName, $matches)) {
            return $matches[0];
        } else {
            return false;
        }
    }

    protected function renderFile($file, $vars = array()) {
        if (is_array($vars)) {
            extract($vars, EXTR_SKIP);
        }

        ob_start();

        if (is_file($file)) {
            /** @noinspection PhpIncludeInspection */
            include $file;
        }

        $html = ob_get_clean();

        return $html;
    }

    protected function preparePrefix($prefix = '') {
        $prefix = trim($prefix);
        if (empty($prefix)) {
            $prefix = $this->getConfigVal('version_prefix');
            $prefix = trim($prefix);
        }

        $default = 'Version';
        if (empty($prefix)) {
            return $default;
        }

        $prefix = preg_replace("/[^a-z0-9_]/i", '', $prefix);
        if (empty($prefix)) {
            return $default;
        }

        if (preg_match('/^\d/', $prefix)) {
            return $default;
        }

        return $prefix;
    }

    protected function purifyDescriptionForFile($descr = '') {
        $descr = strval($descr);
        $descr = str_replace(array("\n\r", "\r\n", "\n", "\r"), ' ', $descr);
        $descr = strip_tags($descr);
        $descr = addslashes($descr);
        return $descr;
    }

    protected function purifyDescriptionForMeta($descr = '') {
        $descr = strval($descr);
        $descr = str_replace(array("\n\r", "\r\n", "\n", "\r"), ' ', $descr);
        $descr = strip_tags($descr);
        $descr = stripslashes($descr);
        return $descr;
    }


    //config
    public function getConfigName() {
        return $this->versionConfig->getConfigName();
    }

    public function getConfigVal($val, $default = '') {
        return $this->versionConfig->getConfigVal($val, $default);
    }

    public function getConfigInfo() {
        return $this->versionConfig->getConfigInfo();
    }

    //table
    protected function getRecords() {
        return $this->versionTable->getRecords();
    }

    protected function getRecordByName($versionName) {
        return $this->versionTable->getRecordByName($versionName);
    }

    protected function addRecord($versionName) {
        return $this->versionTable->addRecord($versionName);
    }

    protected function removeRecord($versionName) {
        return $this->versionTable->removeRecord($versionName);
    }
}
