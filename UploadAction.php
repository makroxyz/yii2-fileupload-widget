<?php

namespace makroxyz\fileupload;

use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\UploadedFile;

class UploadAction extends BaseAction
{
    /**
     * @var boolean whether this action is disabled
     */
    public $disabled = false;
    /**
     * @var string temporary upload destination
     */
    public $uploadDest;
    /**
     * @var string thumbnail destination
     */
    public $thumbDest;
    /**
     * @var boolean whether to create directories if they do not exist
     */
    public $createDirs = false;

    /**
     * @var string parameter name
     */
    public $fileAttribute;

    /**
     * @var array|false accepted file extensions, false to allow any extension
     */
    public $extensions = false;

    /**
     * @var array|false accepted mime types, false to disable mime type check
     */
    public $mimeTypes = false;

    /**
     * @var integer maximum file size, false to allow any size
     */
    public $maxSize = false;

    /**
     * @var callable thumbnailer function, false to disable thumbnail generation
     */
    public $thumbnailCallback;

    /**
     * @var callable savecallBack function, false to disable
     */
    public $saveCallback;

    /**
     * @var string|array thumbnail action route
     */
    public $thumbRoute;

    /**
     * @var string|array delete action route
     */
    public $deleteRoute;
    
    /**
     * @var string delete type request
     */
    public $deleteType = 'DELETE';

    /**
     * @var array error strings
     */
    public $errors = [
        // standard php errors, returned by UploadedFile->getError
        1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
        3 => 'The uploaded file was only partially uploaded',
        4 => 'No file was uploaded',
        6 => 'Missing a temporary folder',
        7 => 'Failed to write file to disk',
        8 => 'A PHP extension stopped the file upload',
        // custom errors
        'append_failed' => 'Failed to append file',
        'save_failed' => 'Failed to save file',
        'abort' => 'Upload aborted',
        'invalid_file_type' => 'Invalid file type',
        'invalid_file_ext' => 'Invalid file extension',
        'invalid_file_size' => 'Invalid file size',
    ];

    public function run()
    {
        /*if (rand(0,1) == 1) {
            throw new \yii\web\HttpException(500);
        }*/

        if ($this->fileAttribute === null) {
            throw new InvalidConfigException("'fileAttribute' property must be specified.");
        }

        if ($this->uploadDest === null) {
            $this->uploadDest = Yii::$app->runtimePath . DIRECTORY_SEPARATOR . 'temp';
        } else {
            $this->uploadDest = Yii::getAlias($this->uploadDest);
        }
        if ($this->thumbDest === null) {
            $this->thumbDest = $this->uploadDest . DIRECTORY_SEPARATOR . 'thumb';
        } else {
            $this->thumbDest = Yii::getAlias($this->thumbDest);
        }

        if ($this->createDirs && !is_dir($this->uploadDest)) {
            mkdir($this->uploadDest, 0777, true);
        }
        if ($this->createDirs && !is_dir($this->thumbDest)) {
            mkdir($this->thumbDest, 0777, true);
        }

        if ($this->thumbnailCallback === null) {
            $this->thumbnailCallback = function ($input, $output) {
                return ImageManipulate::crop($input, $output, 300, 200);
            };
        }

        if ($this->saveCallback === null) {
            $this->saveCallback = function ($input, $output) {
                return ImageManipulate::resize($input, $output, 1920, 1080);
            };
        }

        $this->sendHeaders();

        $this->handleUpload();
    }

    protected function sendHeaders()
    {
        header('Vary: Accept');
        if (isset($_SERVER['HTTP_ACCEPT']) && (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            header('Content-type: application/json');
        } else {
            header('Content-type: text/plain');
        }
    }

    protected function handleUpload()
    {
        $model->{$this->fileAttribute} = UploadedFile::getInstance($model, $this->fileAttribute);
//        $model = $this->formModel;
//        if ($model->{$this->fileAttribute} !== null) {
//            $model->{$this->mimeTypeAttribute} = $model->{$this->fileAttribute}->getType();
//            $model->{$this->sizeAttribute} = $model->{$this->fileAttribute}->getSize();
//            $model->{$this->displayNameAttribute} = $model->{$this->fileAttribute}->getName();
//            $model->{$this->fileNameAttribute} = $model->{$this->displayNameAttribute};
//        
//        $fileAttribute = $this->fileAttribute;
//        $file = UploadedFile::getInstanceByName($this->fileAttribute);
//        if ($file !== null) {
//            $files = [$file];
//        } else {
//            $files = UploadedFile::getInstancesByName($this->fileAttribute);
//            $fileAttribute = $this->fileAttribute . '[]';
//        }

        $response = [];

        // Parse the Content-Range header, which has the following form:
        // Content-Range: bytes 0-524287/2000000
        $contentRange = null;
        $size = null;
        if (isset($_SERVER['HTTP_CONTENT_RANGE'])) {
            $contentRange = preg_split('/[^0-9]+/', $_SERVER['HTTP_CONTENT_RANGE']);
            if (is_array($contentRange) && count($contentRange) > 3) {
                $size = $contentRange[3];
            }
        }

        foreach ($files as $file) {
            $response[] = $this->uploadFile($file, $contentRange, $size);
        }
        
        if (Yii::$app->request->get('ls')) {
            $files = FileHelper::findFiles($this->uploadDest);
            foreach ($files as $file) {
                
                $response[] = $this->uploadFile($file, $contentRange, $size);
            }
        }

        echo Json::encode([
            'files' => $response,
        ]);
    }

    protected function uploadFile($uploadedFile, $contentRange, $realSize)
    {
        $file = new \stdClass();
        
        if (!($uploadedFile instanceof UploadedFile)) {
            $pathinfo = pathinfo($uploadedFile);
            $file->name = $pathinfo['basename'];
            $file->type = $pathinfo['extension'];
            $file->size = filesize($uploadedFile);
            
            $thumbRoute = $this->thumbRoute;
            if ($thumbRoute === null) {
                $thumbRoute = [$this->actionPrefix . 'thumb'];
            }
            if (is_string($thumbRoute)) {
                $thumbRoute = [
                    $thumbRoute,
                ];
            }
            $thumbRoute['filename'] = $file->name;
            $file->thumbnailUrl = Url::toRoute($thumbRoute);
        } else {
            $file->name = $this->getUniqueName($this->cleanUploadFilename($uploadedFile->name), $contentRange);
            if ($file->name === false) {
                return false;
            }
            $file->size = $this->fixIntegerOverflow(intval($realSize ? $realSize : $uploadedFile->size));

            if (($validerr = $this->validateFile($uploadedFile, $file->name, $file->size)) !== true) {
                $file->error = $this->errors[$validerr];
                return $file;
            }

            $file->type = $uploadedFile->type;
            $path = $this->uploadDest . DIRECTORY_SEPARATOR . $file->name;

            $uploadedSize = is_file($path) ? $this->getFileSize($path) : 0;

            if ($uploadedSize > $file->size) {
                @unlink($path);
                $file->error = $this->errors['invalid_file_size'];
                return $file;
            }

            $appendFile = $contentRange && is_file($path) && $file->size > $uploadedSize;

            if ($appendFile) {
                if ($uploadedFile->error == UPLOAD_ERR_OK) {
                    if (file_put_contents(
                        $path,
                        fopen($uploadedFile->tempName, 'r'),
                        FILE_APPEND
                    ) === false) {
                        $file->error = $this->errors['append_failed'];
                        return $file;
                    }
                } else {
                    $file->error = $this->errors[$uploadedFile->error];
                    return $file;
                }
            } else {
                if (!$uploadedFile->saveAs($path)) {
                    $file->error = $this->errors['save_failed'];
                    return $file;
                }
            }

            $resultSize = $this->getFileSize($path, $appendFile);
            if ($resultSize !== $file->size) {
                if (!$contentRange) {
                    unlink($path);
                    $file->error = $this->errors['abort'];
                    return $file;
                }
            } else {
                if (!$this->validateFileType($path)) {
                    $file->error = $this->errors['invalid_file_type'];
                    unlink($path);
                    return $file;
                }

                if ($this->saveCallback !== false) {
                    if (call_user_func($this->saveCallback, $path, $path)) {
                    }
                }

                $thumbRoute = $this->thumbRoute;
                if ($thumbRoute === null) {
                    $thumbRoute = [$this->actionPrefix . 'thumb'];
                }
                if (is_string($thumbRoute)) {
                    $thumbRoute = [
                        $thumbRoute,
                    ];
                }
                $thumbRoute['filename'] = $file->name;

                if ($this->thumbnailCallback !== false) {
                    $thumbPath = $this->thumbDest . DIRECTORY_SEPARATOR . $file->name;
                    if (call_user_func($this->thumbnailCallback, $path, $thumbPath)) {
                        $file->thumbnailUrl = Url::toRoute($thumbRoute);
                    }
                } else {
                    $file->thumbnailUrl = Url::toRoute($thumbRoute);
                }
            }
        }
        
        $deleteRoute = $this->deleteRoute;
        if ($deleteRoute === null) {
            $deleteRoute = [$this->actionPrefix . 'delete'];
        }
        if (is_string($deleteRoute)) {
            $deleteRoute = [
                $deleteRoute,
            ];
        }
        $deleteRoute['filename'] = $file->name;
        $file->deleteUrl = Url::toRoute($deleteRoute);
        $file->deleteType = $this->deleteType;
        return $file;

    }

    protected function upcountNameCallback($matches)
    {
        $index = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
        $ext = isset($matches[2]) ? $matches[2] : '';
        return ' ('.$index.')'.$ext;
    }

    protected function upcountName($name)
    {
        return preg_replace_callback(
            '/(?:(?: \(([\d]+)\))?(\.[^.]+))?$/',
            [$this, 'upcountNameCallback'],
            $name,
            1
        );
    }

    protected function getUniqueName($name, $content_range)
    {
        // rename file if directory with the same name exists
        while (is_dir($this->uploadDest . DIRECTORY_SEPARATOR . $name)) {
            $name = $this->upcountName($name);
        }
        // Keep an existing filename if this is part of a chunked upload:
        $uploaded_bytes = $this->fixIntegerOverflow(intval($content_range[1]));

        while (is_file($this->uploadDest . DIRECTORY_SEPARATOR . $name)) {
            if ($uploaded_bytes === $this->getFileSize($this->uploadDest . DIRECTORY_SEPARATOR . $name)) {
                return $name;
            }
            if ($this->disabled) {
                return false;
            }
            $name = $this->upcountName($name);
        }
        if ($this->disabled) {
            return false;
        }
        return $name;
    }

    // Fix for overflowing signed 32 bit integers,
    // works for sizes up to 2^32-1 bytes (4 GiB - 1):
    protected function fixIntegerOverflow($size)
    {
        if ($size < 0) {
            $size += 2.0 * (PHP_INT_MAX + 1);
        }
        return $size;
    }

    protected function validateFileType($path)
    {
        if (is_array($this->mimeTypes)) {
            $mime = FileHelper::getMimeType($path);
            if (!in_array($mime, $this->mimeTypes)) {
                return false;
            }
        }
        return true;
    }

    protected function validateFile($file, $name, $size)
    {

        if (is_array($this->extensions)) {
            if (($pos = strrpos($name, '.')) === false) {
                return 'invalid_file_ext';
            }
            $ext = substr($name, $pos+1);
            if (!$ext) {
                return 'invalid_file_ext';
            }
            if (!in_array(strtolower($ext), $this->extensions)) {
                return 'invalid_file_ext';
            }
        }
        /*var_dump($this->maxSize);
        var_dump($size);
        die(-1);*/
        if ($this->maxSize !== false && $this->maxSize < $size) {
            return 'invalid_file_size';
        }
        return true;
    }

    protected function getFileSize($path, $clear_stat_cache = false)
    {
        if ($clear_stat_cache) {
            if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
                clearstatcache(true, $path);
            } else {
                clearstatcache();
            }
        }
        return $this->fixIntegerOverflow(filesize($path));
    }
}
