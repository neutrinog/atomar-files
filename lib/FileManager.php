<?php

namespace files;

use atomar\Atomar;
use atomar\core\Auth;
use atomar\core\Logger;
use atomar\core\Templator;
use model\File;
use model\Fileupload;

/**
 * Class Files
 * @package files\controller
 */
class FileManager {

    /**
     * @var DataStore|null
     */
    private static $default_ds = null;

    /**
     * Initializes a new Files class
     * Files constructor.
     * @param DataStore $dataStore
     */
    public function __construct(DataStore $dataStore=null) {
        if($dataStore === null) {
            self::$default_ds = new LocalDataStore();
        } else {
            self::$default_ds = $dataStore;
        }
        self::$default_ds->init();
    }

    /**
     * Returns an initialized instance of the DataStore used to store the given file.
     *
     * @param File $file
     * @returns DataStore
     */
    public function getFileDataStore(File $file) {
        if($file && $file->id) {
            $class_name = $file->data_store;
            try {
                $instance = new $class_name;
                if ($instance instanceof DataStore) {
                    $instance->init();
                    return $instance;
                }
            } catch (\Exception $e) {
                Logger::log_error('Failed to load the DataStore for file ' . $file->id);
            }
        }
        return null;
    }

    /**
     * Much like fetch_file except it only returns the meta data without the body content
     *
     * @param File $file The file to read
     * @return mixed Returns the file object or null if the file is corrupt or missing.
     */
    public function fetchFileMeta(File $file) {
        $ds = $this->getFileDataStore($file);
        if($ds != null) {
            return $ds->getMeta($file);
        }
        return false;
    }

    /**
     * Retrieves a download link for the file
     *
     * @param File $file the file to be downloaded
     * @param int $ttl the length of time until the download link expires
     * @return string the download link
     */
    public function generateDownloadUrl(File $file, int $ttl = 300) {
        $ds = $this->getFileDataStore($file);
        if($ds != null) {
            return $ds->getDownloadURL($file, $ttl);
        }
        return false;
    }

    /**
     * Downloads the file
     * this differs from get_download_url in that this method prints the raw file data directly and exits the script
     *
     * @param File $file the file to be downloaded
     * @param boolean $view_in_browser if set to true the server will attempt to tell the browser to display the file instead of downloading it.
     */
    public function downloadFile(File $file, bool $view_in_browser = false) {
        $ds = $this->getFileDataStore($file);
        if($ds != null) {
            $ds->download($file, $view_in_browser);
        } else if (!$file || $file->id) {
            header('HTTP/1.1 404 Not Found', true, 404);
        } else {
            // due to the DataStore not getting loaded
            header('HTTP/1.1 500 Server Error', true, 500);
        }
        exit;
    }

    /**
     * Imports a file into the system.
     * Use this method if you already have a file on the server that you would like to import.
     *
     * @param string $file_name the name of the file including extension
     * @param string $file_path the path to the file that will be imported
     * @param string $destination_dir the relative file destination directory
     * @return File
     */
    public function importFile(string $file_name, string $file_path, string $destination_dir) {
        $hash = md5_file($file_path);
        $file = self::getFileByHash($hash);
        if (!$file || $file->is_uploaded == '0') {
            // trash broken file
            if (!$file) $file = \R::dispense('file');

            // build new file object
            $file->size = filesize($file_path); // file size in bytes
            $file->is_uploaded = '1';
            $file->hash = $hash;
            $file->name = $file_name;
            $file->ext = strtolower(end(explode('.', $file->name)));
            $file->file_path = trim($destination_dir, '/') . '/' . $file->hash . '.' . $file->ext;
            $file->name_searchable = str_replace('_', ' ', $file->name);
            $file->created_at = db_date();
            $file->created_by = Auth::$user;
            $file->data_store = $this->dataStoreName();

            if (store($file)) {
                // move file
                $upload = $this->generateUpload($file->box(), 10);
                if ($upload) {
                    // move the file
                    $dest_path = Atomar::$config['files'] . $upload->file->file_path;
                    $dir = dirname($dest_path);
                    if (!is_dir($dir)) mkdir($dir, 0770, true);
                    rename($file_path, $dest_path);
                    \R::trash($upload);

                    // finish
                    if ($this->postProcessUpload($file->box())) {
                        return $file->box();
                    }
                }
            } else {
                Logger::log_error($file->errors());
                return null;
            }
        } else {
            return $file;
        }
        return null;
    }

    /**
     * Looks for an existing file by it's content hash.
     * Note: if you want to check if the file content actually exists on the disk you should use fetchFileMeta()
     *
     * @param string $hash md5 sum of the file
     * @return File the file or null;
     */
    public function getFileByHash(string $hash) {
        $file = \R::findOne('file', 'hash=?', array($hash));
        if($file) {
            return $file->box();
        } else {
            return null;
        }
    }

    /**
     * Looks up an upload request by it's token
     * @param string $token the upload token
     * @return Fileupload
     */
    public function getUpload(string $token) {
        // TRICKY: in case tokens are not unique selected the most recent
        $upload = \R::findOne('fileupload', 'token=? order by id desc', array($token));
        if($upload) {
            return $upload->box();
        } else {
            return null;
        }
    }

    /**
     * Returns the name of the data store
     *
     * @return string
     */
    public function dataStoreName() {
        return get_class(self::$default_ds);
    }

    /**
     * Generate a temporary upload object
     *
     * @param File $file The file bean that will be uploaded
     * @param int $ttl The length of time the upload url will be active. Default is 5 minutes.
     * @return null|Fileupload The temporary upload object
     */
    public function generateUpload(File $file, int $ttl = 300) {
        $ds = $this->getFileDataStore($file);
        if($ds != null) {
            return $ds->generateUpload($file, $ttl);
        }
        return null;
    }

    /**
     * Performs extra operations on the file after it has been uploaded.
     *
     * @param File $file the file that was uploaded.
     * @return bool
     */
    public function postProcessUpload(File $file) {
        $ds = $this->getFileDataStore($file);
        if($ds != null) {
            try {
                return $ds->postProcessUpload($file);
            } catch (\Exception $e) {
                Logger::log_error($e->getMessage(), $e->getTrace());
            }
        }
        return false;
    }

    /**
     * deploys the drop zone onto the page and includes any necessary files
     *
     * @param string $drop_zone the selector that will be used as the drop zone
     * @param array $options javascript options to configure how the drop zone operates
     */
    public function deploy(string $drop_zone, array $options = array()) {
        // TODO: extension assets are now handled by referencing the namespace
        Templator::$js[] = '/assets/files/js/spark-md5.min.js';
        Templator::$js[] = '/assets/files/js/jquery.ui.widget.js';
        Templator::$js[] = '/assets/files/js/jquery.iframe-transport.js';
        Templator::$js[] = '/assets/files/js/jquery.fileupload.js';
        Templator::$js[] = '/assets/files/js/filedrop.js';
        Templator::$css[] = '/assets/files/css/filedrop.css';

        // set forced defaults
        $options['initUploadUrl'] = '/files/api/init';
        $options['confirmUploadUrl'] = '/files/api/confirm';

        // build option list
        $js_options = array();
        foreach ($options as $key => $value) {
            if (strpos($key, 'callback') === 0) {
                $js_options[] = $key . ':' . $value;
            } else {
                $js_options[] = $key . ':"' . $value . '"';
            }
        }
        $js_options = '{' . implode(',', $js_options) . '}';

        Templator::$js_onload[] = <<<JAVASCRIPT
$('$drop_zone').filedrop($js_options);
JAVASCRIPT;
    }
}