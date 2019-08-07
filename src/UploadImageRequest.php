<?php

/*
 * This file is part of the jimchen/aliyun-php-sdk-voduploader.
 *
 * (c) JimChen <imjimchen@163.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

namespace JimChen\Voduploader;

use Exception;

/**
 * Class UploadImageRequest.
 *
 * Aliyun VoD's Upload Image Request class, which wraps parameters to upload an image into VoD.
 * Users could pass parameters to AliyunVodUploader, including File Path,Title,etc. via an UploadImageRequest instance.
 * For more details, please check out the VoD API document: https://help.aliyun.com/document_detail/55619.html
 */
class UploadImageRequest
{
    public function __construct($filePath, $title = null)
    {
        $this->setFilePath($filePath, $title);
        $this->imageType = 'default';
    }

    public function getFilePath()
    {
        return $this->filePath;
    }

    public function setFilePath($filePath, $title = null)
    {
        $this->filePath = $filePath;
        $fns = AliyunVodUtils::getFileName($this->filePath);
        $this->fileName = $fns[0];
        $extName = AliyunVodUtils::getFileExtension($this->fileName);
        if (empty($extName)) {
            throw new Exception('filePath has no Extension', 'InvalidParameter');
        }
        $this->imageExt = $extName;

        if (isset($title)) {
            $this->title = $title;
        } else {
            if (!isset($this->title)) {
                $this->title = $fns[1];
            }
        }
    }

    public function getFileName()
    {
        return $this->fileName;
    }

    public function setFileName($fileName)
    {
        $this->fileName = $fileName;
    }

    public function getImageType()
    {
        return $this->imageType;
    }

    public function setImageType($imageType)
    {
        $this->imageType = $imageType;
    }

    public function getImageExt()
    {
        return $this->imageExt;
    }

    public function setImageExt($imageExt)
    {
        $this->imageExt = $imageExt;
    }

    public function getMediaExt()
    {
        return $this->imageExt;
    }

    public function setMediaExt($mediaExt)
    {
        $this->imageExt = $mediaExt;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function setTitle($title)
    {
        $this->title = $title;
    }

    public function getCateId()
    {
        return $this->cateId;
    }

    public function setCateId($cateId)
    {
        $this->cateId = $cateId;
    }

    public function getTags()
    {
        return $this->tags;
    }

    public function setTags($tags)
    {
        $this->tags = $tags;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function getStorageLocation()
    {
        return $this->storageLocation;
    }

    public function setStorageLocation($storageLocation)
    {
        $this->storageLocation = $storageLocation;
    }

    public function getUserData()
    {
        return $this->userData;
    }

    public function setUserData($userData)
    {
        $this->userData = $userData;
    }

    public function getAppId()
    {
        return $this->appId;
    }

    public function setAppId($appId)
    {
        $this->appId = $appId;
    }

    public function getWorkflowId()
    {
        return $this->workflowId;
    }

    public function setWorkflowId($WorkflowId)
    {
        $this->workflowId = $WorkflowId;
    }

    private $filePath = null;
    private $fileName = null;
    private $imageType = null;
    private $imageExt = null;
    private $title = null;
    private $cateId = null;
    private $tags = null;
    private $description = null;
    private $storageLocation = null;
    private $userData = null;
    private $appId = null;
    private $workflowId = null;
}
