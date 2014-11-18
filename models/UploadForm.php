<?php
namespace makroxyz\fileupload\models;

use Yii;

class UploadForm extends \yii\base\Model
{
    public $file;
    public $mimeType;
    public $size;
    public $name;
    public $filename;
    
    /**
     * @var boolean dictates whether to use sha1 to hash the file names
     * along with time and the user id to make it much harder for malicious users
     * to attempt to delete another user's file
     */
    public $secureFileNames = false;

    /**
     * Declares the validation rules.
     * The rules state that username and password are required,
     * and password needs to be authenticated.
     */
    public function rules()
    {
        return [
            [['file'], 'file'],
        ];
    }

    /**
     * Declares attribute labels.
     */
    public function attributeLabels()
    {
        return [
            'file' => 'Upload files',
        ];
    }

    /**
     * A stub to allow overrides of thumbnails returned
     * @since 0.5
     * @author acorncom
     * @return string thumbnail name (if blank, thumbnail won't display)
     */
    public function getThumbnailUrl($publicPath)
    {
        return $publicPath . $this->filename;
    }

    /**
     * Change our filename to match our own naming convention
     * @return bool
     */
    public function beforeValidate()
    {
        //(optional) Generate a random name for our file to work on preventing
        // malicious users from determining / deleting other users' files
        if ($this->secureFileNames) {
//            $this->filename = sha1(Yii::app()->user->id . microtime() . $this->name);
            $this->filename = Yii::$app->security->generateRandomString();
            $this->filename .= "." . $this->file->getExtensionName();
        }
        return parent::beforeValidate();
    }
}