<?php

namespace makroxyz\fileupload;

use Yii;

class DeleteAction extends BaseAction
{
    /**
     * @var string temporary upload destination
     */
    public $uploadDest;
    /**
     * @var string thumbnail destination
     */
    public $thumbDest;

    /**
     * @var boolean whether to use thumbs directory
     */
    public $useThumbs = true;

    public function run($filename)
    {
        if ($this->uploadDest === null) {
            $this->uploadDest = \Yii::$app->runtimePath . DIRECTORY_SEPARATOR . 'temp';
        } else {
            $this->uploadDest = \Yii::getAlias($this->uploadDest);
        }
        if ($this->thumbDest === null) {
            $this->thumbDest = $this->uploadDest . DIRECTORY_SEPARATOR . 'thumb';
        } else {
            $this->thumbDest = \Yii::getAlias($this->thumbDest);
        }

        $filename = $this->cleanFilename($filename);

        $path = $this->uploadDest . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($path)) {
            throw new \yii\web\NotFoundHttpException();
        }
        
        if ($this->useThumbs) {
            @unlink($this->thumbDest . DIRECTORY_SEPARATOR . $filename);
        }
        @unlink($this->uploadDest . DIRECTORY_SEPARATOR . $filename);
        
        Yii::$app->getResponse()->format = \yii\web\Response::FORMAT_JSON;
        return true;
    }
}
