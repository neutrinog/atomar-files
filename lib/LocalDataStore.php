<?php

namespace files;


use atomar\Atomar;
use model\File;

/**
 * Stores files on the local disk
 * Class LocalDataStore
 * @package files\controller
 */
class LocalDataStore implements DataStore {
    
    public function generateUpload(File $file, int $ttl) {
        $upload = \R::dispense('fileupload');
        $upload->token = md5($file->file_path . $file->id . time());
        $upload->file = $file;
        $upload->ttl = $ttl;
        $upload->created_at = db_date();
        if (store($upload)) {
            return $upload;
        } else {
            return null;
        }
    }

    public function postProcessUpload(File $file) {
        $meta = $this->getMeta($file);
        $file->size = $meta['content_length'];
        $file->type = $meta['content_type'];
        return store($file);
    }

    public function getMeta(File $file) {
        $path = $this->absolutePath($file);
        if(!file_exists($path)) return null;

        $info = pathinfo($path);
        $meta = array();
        $meta['content_length'] = filesize($path);
        $meta['content_size'] = $info['extension'];
        if (function_exists('mime_content_type')) {
            $meta['content_type'] = mime_content_type($path);
        }
        $meta['path'] = $path;
        return $meta;
    }

    /**
     * Returns the fully qualified path to the file
     *
     * @param File $file
     * @return string the path to the file
     */
    public function absolutePath(File $file) {
        $path = Atomar::$config['files'] . $file->file_path;
        return realpath($path);
    }

    public function download(File $file, bool $view_in_browser = false) {
        if ($file->id) {
            $path = $this->absolutePath($file);
            if (file_exists($path)) {
                if ($view_in_browser) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $path);
                    header('Content-Type: ' . $mime);
                    header('Content-Disposition: inline; filename=' . basename($path));
                    readfile($path);
                } else {
                    stream_file($path, array(
                        'name' => $file->name,
                        'content_type' => $file->type,
                        'download' => true
                    ));
                }
            } else {
                header('HTTP/1.1 404 Not Found');
                echo 'Missing file data';
            }
        } else {
            header('HTTP/1.1 404 Not Found');
        }
        exit;
    }

    /**
     * Performs any initialization required by the data store
     */
    public function init() {
        
    }

    /**
     * returns a download url for the file
     *
     * @param File $file the file to be downloaded
     * @param int $ttl
     * @return string the download link
     */
    public function getDownloadURL(File $file, int $ttl) {
        return '';
    }
}