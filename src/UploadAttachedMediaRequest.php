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

class UploadAttachedMediaRequest
{
    /**
     * UploadAttachedMediaRequest constructor.
     *
     * @param $filePath
     * @param $businessType, optional values: "watermark", "subtitle" and and so on
     * @param null $title
     *
     * @throws Exception
     */
    public function __construct($filePath, $businessType, $title = null)
    {
        $this->setFilePath($filePath, $title);
        $this->businessType = $businessType;
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
        $this->mediaExt = $extName;

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

    public function getBusinessType()
    {
        return $this->businessType;
    }

    public function getMediaExt()
    {
        return $this->mediaExt;
    }

    public function getFileSize()
    {
        return $this->fileSize;
    }

    public function setFileSize($fileSize)
    {
        $this->fileSize = $fileSize;
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
    private $fileSize = null;
    private $businessType = null;
    private $mediaExt = null;
    private $title = null;
    private $cateId = null;
    private $tags = null;
    private $description = null;
    private $storageLocation = null;
    private $userData = null;
    private $appId = null;
    private $workflowId = null;
}
