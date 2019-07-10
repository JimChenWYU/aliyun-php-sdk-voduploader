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
use JimChen\AliyunCore\AcsRequest;
use JimChen\AliyunCore\DefaultAcsClient;
use JimChen\AliyunCore\Http\HttpHelper;
use JimChen\AliyunCore\Profile\DefaultProfile;
use JimChen\AliyunVod as vod;
use JimChen\OSS\Core\OssUtil;
use JimChen\OSS\OssClient;

class AliyunVodUploader
{
    public function __construct($accessKeyId, $accessKeySecret, $apiRegionId = null)
    {
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        if (empty($apiRegionId)) {
            // VoD的接入地址，国内为cn-shanghai，参考：https://help.aliyun.com/document_detail/98194.html
            $this->apiRegionId = 'cn-shanghai';
        } else {
            $this->apiRegionId = $apiRegionId;
        }

        // VOD和OSS的连接超时时间，单位秒
        $this->connTimeout = 1;

        // 设置VOD请求超时时间，单位秒
        $this->vodTimeout = 3;

        // 设置OSS请求超时时间，单位秒，默认是7天, 建议不要设置太小，如果上传文件很大，消耗的时间会比较长
        $this->ossTimeout = 86400 * 7;

        // 失败后的最大重试次数
        $this->maxRetryTimes = 3;

        $this->multipartThreshold = 10 * 1024 * 1024;
        $this->multipartPartSize = 10 * 1024 * 1024;
        $this->multipartThreadsNum = 3;

        $this->vodClient = $this->initVodClient();
        $this->ossClient = null;
        $this->ecsRegionId = null;
        $this->enableSSL = false;
    }

    /**
     * 设置上传脚本部署的ECS区域（如有），当检测上传区域和点播存储区域位于同一区域时，会自动开启内网上传，能节省流量费用且上传速度更快.
     *
     * @param string, $regionId 可选值参考：https://help.aliyun.com/document_detail/40654.html，如cn-shanghai
     */
    public function setEcsRegionId($regionId)
    {
        $this->ecsRegionId = $regionId;
    }

    /**
     * 是否启用SSL(网络请求使用HTTPS)，默认不启用，以避免相关扩展未安装或配置异常时无法使用.
     *
     * @param bool, $isEnable 可选值参考：https://help.aliyun.com/document_detail/40654.html，如cn-shanghai
     */
    public function setEnableSSL($isEnable)
    {
        $this->enableSSL = $isEnable;
    }

    /**
     * 上传本地视频或音频文件到点播，最大支持48.8TB的单个文件，暂不支持断点续传.
     *
     * @param UploadVideoRequest $uploadVideoRequest: UploadVideoRequest类的实例，注意filePath为本地文件的绝对路径
     *
     * @return string, $videoId
     *
     * @throws Exception
     */
    public function uploadLocalVideo($uploadVideoRequest)
    {
        $uploadInfo = $this->createUploadVideo($uploadVideoRequest);
        $headers = $this->getUploadHeaders($uploadVideoRequest);
        $this->uploadOssObject($uploadVideoRequest->getFilePath(), $uploadInfo->UploadAddress->FileName, $uploadInfo, $headers);

        return $uploadInfo->VideoId;
    }

    /**
     * 上传网络视频或音频文件到点播，最大支持48.8TB的单个文件(需本地磁盘空间足够)；会先下载到本地临时目录，再上传到点播存储.
     *
     * @param UploadVideoRequest $uploadVideoRequest: UploadVideoRequest类的实例，注意filePath为网络文件的URL地址
     *
     * @return string, $videoId
     *
     * @throws Exception
     */
    public function uploadWebVideo($uploadVideoRequest)
    {
        // 下载文件
        $uploadVideoRequest = $this->downloadWebMedia($uploadVideoRequest);

        // 上传到点播
        $uploadInfo = $this->createUploadVideo($uploadVideoRequest);
        $headers = $this->getUploadHeaders($uploadVideoRequest);
        $this->uploadOssObject($uploadVideoRequest->getFilePath(), $uploadInfo->UploadAddress->FileName, $uploadInfo, $headers);

        // 删除下载的本地视频
        unlink($uploadVideoRequest->getFilePath());

        return $uploadInfo->VideoId;
    }

    /**
     * 上传本地图片文件到点播，最大支持48.8TB的单个文件.
     *
     * @param UploadImageRequest $uploadImageRequest: UploadImageRequest类的实例，注意filePath为本地文件的绝对路径
     *
     * @return array, array('ImageId'=>'xxxxx', 'ImageURL'=>'http://xxx.jpg')
     *
     * @throws Exception
     */
    public function uploadLocalImage($uploadImageRequest)
    {
        $uploadInfo = $this->createUploadImage($uploadImageRequest);
        $this->uploadOssObject($uploadImageRequest->getFilePath(), $uploadInfo->UploadAddress->FileName, $uploadInfo);

        return ['ImageId' => $uploadInfo->ImageId, 'ImageURL' => $uploadInfo->ImageURL];
    }

    /**
     * 上传网络图片文件到点播，最大支持48.8TB的单个文件(需本地磁盘空间足够)；会先下载到本地临时目录，再上传到点播存储.
     *
     * @param UploadImageRequest $uploadVideoRequest: UploadImageRequest类的实例，注意filePath为网络文件的URL地址
     *
     * @return array, array('ImageId'=>'xxxxx', 'ImageURL'=>'http://xxx.jpg')
     *
     * @throws Exception
     */
    public function uploadWebImage($uploadImageRequest)
    {
        // 下载文件
        $uploadImageRequest = $this->downloadWebMedia($uploadImageRequest);

        // 上传到点播
        $uploadInfo = $this->createUploadImage($uploadImageRequest);
        $this->uploadOssObject($uploadImageRequest->getFilePath(), $uploadInfo->UploadAddress->FileName, $uploadInfo);

        // 删除下载的本地图片
        unlink($uploadImageRequest->getFilePath());

        return ['ImageId' => $uploadInfo->ImageId, 'ImageURL' => $uploadInfo->ImageURL];
    }

    /**
     * 上传本地辅助媒资文件(如水印、字幕等)到点播，最大支持48.8TB的单个文件.
     *
     * @param UploadAttachedMediaRequest $uploadAttachedRequest: UploadAttachedMediaRequest类的实例，注意filePath为本地文件的绝对路径
     *
     * @return array, array('MediaId'=>'xxxxx', 'MediaURL'=>'http://xxx.jpg', 'FileURL'=>'http://xxx.jpg')
     *
     * @throws Exception
     */
    public function uploadLocalAttachedMedia($uploadAttachedRequest)
    {
        $uploadInfo = $this->createUploadAttachedMedia($uploadAttachedRequest);
        $this->uploadOssObject($uploadAttachedRequest->getFilePath(), $uploadInfo->UploadAddress->FileName, $uploadInfo);

        return ['MediaId' => $uploadInfo->MediaId, 'MediaURL' => $uploadInfo->MediaURL, 'FileURL' => $uploadInfo->FileURL];
    }

    /**
     * 上传网络辅助媒资文件(如水印、字幕等)到点播，最大支持48.8TB的单个文件(需本地磁盘空间足够)；会先下载到本地临时目录，再上传到点播存储.
     *
     * @param UploadAttachedMediaRequest $uploadAttachedRequest: UploadAttachedMediaRequest类的实例，注意filePath为网络文件的URL地址
     *
     * @return array, array('MediaId'=>'xxxxx', 'MediaURL'=>'http://xxx.jpg', 'FileURL'=>'http://xxx.jpg')
     *
     * @throws Exception
     */
    public function uploadWebAttachedMedia($uploadAttachedRequest)
    {
        // 下载文件
        $uploadAttachedRequest = $this->downloadWebMedia($uploadAttachedRequest);

        // 上传到点播
        $uploadInfo = $this->createUploadAttachedMedia($uploadAttachedRequest);
        $this->uploadOssObject($uploadAttachedRequest->getFilePath(), $uploadInfo->UploadAddress->FileName, $uploadInfo);

        // 删除下载的本地文件
        unlink($uploadAttachedRequest->getFilePath());

        return ['MediaId' => $uploadInfo->MediaId, 'MediaURL' => $uploadInfo->MediaURL, 'FileURL' => $uploadInfo->FileURL];
    }

    /**
     * 上传本地m3u8视频或音频文件到点播，m3u8文件和分片文件默认在同一目录($sliceFiles为null时，会按照同一目录去解析分片地址).
     *
     * @param UploadVideoRequest $uploadVideoRequest: 注意filePath为本地m3u8索引文件的绝对路径，
     *                                                且m3u8文件的分片信息必须是相对地址，不能含有URL或本地绝对路径
     * @param array              $sliceFiles:         ts分片文件的绝对路径列表，如指定则以此为准，若不指定，则自动解析$uploadVideoRequest里的m3u8文件
     *
     * @return string, $videoId
     *
     * @throws Exception
     */
    public function uploadLocalM3u8($uploadVideoRequest, $sliceFiles)
    {
        if (!is_array($sliceFiles) || empty($sliceFiles)) {
            throw new Exception('m3u8 slice files invalid', AliyunVodError::VOD_INVALID_M3U8_SLICE_FILE);
        }

        // 上传到点播的m3u8索引文件会重写，以此确保分片地址都为相对地址
        $downloader = new AliyunVodDownloader();
        $m3u8LocalDir = $downloader->getSaveLocalDir().md5($uploadVideoRequest->getFileName()).'/';
        $downloader->setSaveLocalDir($m3u8LocalDir);
        $m3u8LocalPath = $m3u8LocalDir.basename($uploadVideoRequest->getFileName());
        $this->rewriteM3u8File($uploadVideoRequest->getFilePath(), $m3u8LocalPath);

        // 解析分片文件地址和文件名
        $sliceList = [];
        if (isset($sliceFiles)) {
            foreach ($sliceFiles as $sliceFilePath) {
                $arr = AliyunVodUtils::getFileName($sliceFilePath);
                $sliceList[] = [$sliceFilePath, $arr[1]];
            }
        }

        // 获取上传地址和凭证
        $uploadVideoRequest->setFilePath($m3u8LocalPath);
        $uploadInfo = $this->createUploadVideo($uploadVideoRequest);
        $headers = $this->getUploadHeaders($uploadVideoRequest);

        // 依次上传分片文件
        foreach ($sliceList as $slice) {
            $this->uploadOssObject($slice[0], $uploadInfo->UploadAddress->ObjectPrefix.$slice[1], $uploadInfo, $headers);
        }

        // 上传m3u8索引文件
        $this->uploadOssObject($uploadVideoRequest->getFilePath(), $uploadInfo->UploadAddress->FileName, $uploadInfo, $headers);

        // 删除重写到本地的m3u8文件
        unlink($m3u8LocalPath);

        return $uploadInfo->VideoId;
    }

    /**
     * 上传网络m3u8视频或音频文件到点播，需本地磁盘空间足够，会先下载到本地临时目录，再上传到点播存储.
     *
     * @param UploadVideoRequest $uploadVideoRequest: 注意filePath为网络m3u8索引文件的URL地址，
     * @param array              $sliceFileUrls:      ts分片文件的URL地址列表；可自行拼接ts分片的URL地址列表，或者使用parseWebM3u8解析
     *
     * @return string, $videoId
     *
     * @throws Exception
     */
    public function uploadWebM3u8($uploadVideoRequest, $sliceFileUrls)
    {
        if (!is_array($sliceFileUrls) || empty($sliceFileUrls)) {
            throw new Exception('m3u8 slice files invalid', AliyunVodError::VOD_INVALID_M3U8_SLICE_FILE);
        }

        // 下载m3u8文件和所有ts分片文件到本地；上传到点播的m3u8索引文件会重写，确保分片地址都为相对地址
        $downloader = new AliyunVodDownloader();
        $m3u8LocalDir = $downloader->getSaveLocalDir().md5($uploadVideoRequest->getFileName()).'/';
        $downloader->setSaveLocalDir($m3u8LocalDir);
        $m3u8LocalPath = $m3u8LocalDir.basename($uploadVideoRequest->getFileName());
        $this->rewriteM3u8File($uploadVideoRequest->getFilePath(), $m3u8LocalPath);

        $sliceList = [];
        foreach ($sliceFileUrls as $sliceFileUrl) {
            $arr = AliyunVodUtils::getFileName($sliceFileUrl);
            $sliceLocalPath = $downloader->downloadFile($sliceFileUrl, $arr[1]);
            if (false === $sliceLocalPath) {
                throw new Exception('ts file download fail: '.$sliceFileUrl, AliyunVodError::VOD_ERR_FILE_DOWNLOAD);
            }
            $sliceList[] = [$sliceLocalPath, $arr[1]];
        }

        // 获取上传地址和凭证
        $uploadVideoRequest->setFilePath($m3u8LocalPath);
        $uploadInfo = $this->createUploadVideo($uploadVideoRequest);
        $headers = $this->getUploadHeaders($uploadVideoRequest);

        // 依次上传分片文件
        foreach ($sliceList as $slice) {
            $this->uploadOssObject($slice[0], $uploadInfo->UploadAddress->ObjectPrefix.$slice[1], $uploadInfo, $headers);
        }

        // 上传m3u8索引文件
        $this->uploadOssObject($uploadVideoRequest->getFilePath(), $uploadInfo->UploadAddress->FileName, $uploadInfo, $headers);

        // 删除下载到本地的文件
        unlink($m3u8LocalPath);
        foreach ($sliceList as $slice) {
            unlink($slice[0]);
        }
        rmdir($m3u8LocalDir);

        return $uploadInfo->VideoId;
    }

    /**
     * 解析m3u8文件得到所有分片文件地址列表，原理是将m3u8地址前缀拼接ts分片名称作为后者的地址；同时支持本地、网络m3u8文件的解析.
       本函数解析时会默认分片文件和m3u8文件位于同一目录，如不是则请自行拼接分片文件的地址列表
     * @param m3u8FileUrl: string, m3u8网络文件url，例如：/opt/sample.m3u8 或 http://host/sample.m3u8
     *
     * @return array, $sliceFileUrls
     *
     * @throws Exception
     */
    public function parseM3u8File($m3u8FilePath)
    {
        $sliceFileUrls = [];
        $str = @file_get_contents(OssUtil::encodePath($m3u8FilePath));
        if (false === $str) {
            throw new Exception('m3u8 file access fail: '.$m3u8FilePath, AliyunVodError::VOD_ERR_FILE_READ);
        }
        $lines = explode("\n", $str);
        foreach ($lines as $line) {
            $sliceFileName = trim($line);
            if (strlen($sliceFileName) <= 0) {
                continue;
            }
            if (AliyunVodUtils::startsWith($sliceFileName, '#')) {
                continue;
            }

            $sliceFile = AliyunVodUtils::replaceFileName($m3u8FilePath, $sliceFileName);
            if (false === $sliceFile) {
                throw new Exception('m3u8 file invalid', AliyunVodError::VOD_INVALID_M3U8_SLICE_FILE);
            }
            $sliceFileUrls[] = $sliceFile;
        }

        return $sliceFileUrls;
    }

    private function rewriteM3u8File($srcM3u8FilePath, $dstM3u8FilePath)
    {
        $str = @file_get_contents(OssUtil::encodePath($srcM3u8FilePath));
        if (false === $str) {
            throw new Exception('m3u8 file access fail: '.$srcM3u8FilePath, AliyunVodError::VOD_ERR_M3U8_FILE_REWRITE);
        }
        $lines = explode("\n", $str);
        $newM3u8Text = '';
        foreach ($lines as $line) {
            $sliceFileName = trim($line);
            if (strlen($sliceFileName) <= 0) {
                continue;
            }
            if (AliyunVodUtils::startsWith($sliceFileName, '#')) {
                $newM3u8Text .= $sliceFileName."\n";
                continue;
            }
            $arr = AliyunVodUtils::getFileName($sliceFileName);
            $newM3u8Text .= $arr[1]."\n";
        }

        $res = AliyunVodUtils::mkDir($dstM3u8FilePath);
        if (false === $res) {
            throw new Exception('m3u8 file mkdir fail: '.$dstM3u8FilePath, AliyunVodError::VOD_ERR_M3U8_FILE_REWRITE);
        }

        $res = @file_put_contents(OssUtil::encodePath($dstM3u8FilePath), $newM3u8Text);
        if (false === $res) {
            throw new Exception('m3u8 file rewrite fail: '.$dstM3u8FilePath, AliyunVodError::VOD_ERR_M3U8_FILE_REWRITE);
        }

        return true;
    }

    /**
     * @param $request
     *
     * @return mixed
     *
     * @throws Exception
     */
    private function downloadWebMedia($request)
    {
        // 下载视频文件到本地临时目录
        $downloader = new AliyunVodDownloader();
        $localFileName = sprintf('%s.%s', md5($request->getFileName()), $request->getMediaExt());
        $webFilePath = $request->getFilePath();
        $localFilePath = $downloader->downloadFile($webFilePath, $localFileName);
        if (false === $localFilePath) {
            throw new Exception('file download fail: '.$webFilePath, AliyunVodError::VOD_ERR_FILE_DOWNLOAD);
        }

        // 重新设置上传请求对象
        $request->setFilePath($localFilePath);

        return $request;
    }

    private function initOssClient($uploadAuth, $uploadAddress)
    {
        $endpoint = AliyunVodUtils::convertOssInternal($uploadAddress->Endpoint, $this->ecsRegionId, $this->enableSSL);
        //printf("oss endpoint: %s\n", $endpoint);
        $this->ossClient = new OssClient($uploadAuth->AccessKeyId, $uploadAuth->AccessKeySecret, $endpoint,
            false, $uploadAuth->SecurityToken);
        $this->ossClient->setTimeout($this->ossTimeout);
        $this->ossClient->setConnectTimeout($this->connTimeout);

        return $this->ossClient;
    }

    private function initVodClient()
    {
        HttpHelper::$connectTimeout = $this->connTimeout;
        HttpHelper::$readTimeout = $this->vodTimeout;
        $profile = DefaultProfile::getProfile($this->apiRegionId, $this->accessKeyId, $this->accessKeySecret, $this->securityToken);
        //DefaultProfile::addEndpoint($this->apiRegionId, $this->apiRegionId, "vod", "vod.".$this->apiRegionId.".aliyuncs.com");
        return new DefaultAcsClient($profile);
    }

    private function getUploadHeaders($uploadVideoRequest)
    {
        $switch = $uploadVideoRequest->getWatermarkSwitch();
        if (is_null($switch)) {
            return null;
        }
        $userData = sprintf('{"Vod":{"UserData":{"IsShowWaterMark": "%s"}}}', $switch);

        return ['x-oss-notification' => base64_encode($userData)];
    }

    /**
     * @param UploadVideoRequest $uploadVideoRequest
     *
     * @return mixed|\SimpleXMLElement
     *
     * @throws \JimChen\AliyunCore\Exception\ClientException
     * @throws \JimChen\AliyunCore\Exception\ServerException
     */
    private function createUploadVideo($uploadVideoRequest)
    {
        $request = new vod\CreateUploadVideoRequest();
        $title = AliyunVodUtils::subString($uploadVideoRequest->getTitle(), AliyunVodUtils::VOD_MAX_TITLE_LENGTH);
        $request->setTitle($title);
        $request->setFileName($uploadVideoRequest->getFileName());

        if (!is_null($uploadVideoRequest->getDescription())) {
            $request->setDescription($uploadVideoRequest->getDescription());
        }
        if (!is_null($uploadVideoRequest->getCateId())) {
            $request->setCateId($uploadVideoRequest->getCateId());
        }
        if (!is_null($uploadVideoRequest->getTags())) {
            $request->setTags($uploadVideoRequest->getTags());
        }
        if (!is_null($uploadVideoRequest->getCoverURL())) {
            $request->setCoverURL($uploadVideoRequest->getCoverURL());
        }
        if (!is_null($uploadVideoRequest->getTemplateGroupId())) {
            $request->setTemplateGroupId($uploadVideoRequest->getTemplateGroupId());
        }
        if (!is_null($uploadVideoRequest->getStorageLocation())) {
            $request->setStorageLocation($uploadVideoRequest->getStorageLocation());
        }
        if (!is_null($uploadVideoRequest->getUserData())) {
            $request->setUserData($uploadVideoRequest->getUserData());
        }
        if (!is_null($uploadVideoRequest->getAppId())) {
            $request->setAppId($uploadVideoRequest->getAppId());
        }
        if (!is_null($uploadVideoRequest->getWorkflowId())) {
            $request->setWorkflowId($uploadVideoRequest->getWorkflowId());
        }

        $data = $this->requestUploadInfo($request, 'video');
        AliyunVodLog::printLog('CreateUploadVideo, FilePath: %s, VideoId: %s', $uploadVideoRequest->getFilePath(), $data->VideoId);

        return $data;
    }

    /**
     * @param AcsRequest $request
     * @param $mediaType
     *
     * @return mixed|\SimpleXMLElement
     *
     * @throws \JimChen\AliyunCore\Exception\ClientException
     * @throws \JimChen\AliyunCore\Exception\ServerException
     */
    private function requestUploadInfo($request, $mediaType)
    {
        $request->setAcceptFormat('JSON');
        $data = $this->vodClient->getAcsResponse($request, null, null, true, $this->maxRetryTimes);
        $data->OriUploadAddress = $data->UploadAddress;
        $data->OriUploadAuth = $data->UploadAuth;

        $data->UploadAddress = json_decode(base64_decode($data->OriUploadAddress));
        $data->UploadAuth = json_decode(base64_decode($data->OriUploadAuth));
        $data->MediaType = $mediaType;
        if ('video' == $mediaType) {
            $data->MediaId = $data->VideoId;
        } elseif ('image' == $mediaType) {
            $data->MediaId = $data->ImageId;
            $data->MediaURL = $data->ImageURL;
        }

        return $data;
    }

    private function refreshUploadVideo($videoId)
    {
        $request = new vod\RefreshUploadVideoRequest();
        $request->setVideoId($videoId);

        $data = $this->requestUploadInfo($request, 'video');
        AliyunVodLog::printLog('RefreshUploadVideo, VideoId: %s', $data->VideoId);

        return $data;
    }

    /**
     * @param UploadImageRequest $uploadImageRequest
     *
     * @return mixed|\SimpleXMLElement
     */
    private function createUploadImage($uploadImageRequest)
    {
        $request = new vod\CreateUploadImageRequest();
        $request->setImageType($uploadImageRequest->getImageType());
        $request->setImageExt($uploadImageRequest->getImageExt());

        if (!is_null($uploadImageRequest->getTitle())) {
            $title = AliyunVodUtils::subString($uploadImageRequest->getTitle(), AliyunVodUtils::VOD_MAX_TITLE_LENGTH);
            $request->setTitle($title);
        }
        if (!is_null($uploadImageRequest->getDescription())) {
            $request->setDescription($uploadImageRequest->getDescription());
        }
        if (!is_null($uploadImageRequest->getCateId())) {
            $request->setCateId($uploadImageRequest->getCateId());
        }
        if (!is_null($uploadImageRequest->getTags())) {
            $request->setTags($uploadImageRequest->getTags());
        }
        if (!is_null($uploadImageRequest->getStorageLocation())) {
            $request->setStorageLocation($uploadImageRequest->getStorageLocation());
        }
        if (!is_null($uploadImageRequest->getUserData())) {
            $request->setUserData($uploadImageRequest->getUserData());
        }
        if (!is_null($uploadImageRequest->getAppId())) {
            $request->setAppId($uploadImageRequest->getAppId());
        }
        if (!is_null($uploadImageRequest->getWorkflowId())) {
            $request->setWorkflowId($uploadImageRequest->getWorkflowId());
        }

        $data = $this->requestUploadInfo($request, 'image');
        AliyunVodLog::printLog('CreateUploadImage, FilePath: %s, ImageId: %s, ImageURL: %s',
            $uploadImageRequest->getFilePath(), $data->ImageId, $data->ImageURL);

        return $data;
    }

    /**
     * @param UploadAttachedMediaRequest $uploadAttachedRequest
     *
     * @return mixed|\SimpleXMLElement
     */
    private function createUploadAttachedMedia($uploadAttachedRequest)
    {
        $request = new vod\CreateUploadAttachedMediaRequest();
        $request->setBusinessType($uploadAttachedRequest->getBusinessType());
        $request->setMediaExt($uploadAttachedRequest->getMediaExt());

        if (!is_null($uploadAttachedRequest->getTitle())) {
            $title = AliyunVodUtils::subString($uploadAttachedRequest->getTitle(), AliyunVodUtils::VOD_MAX_TITLE_LENGTH);
            $request->setTitle($title);
        }
        if (!is_null($uploadAttachedRequest->getDescription())) {
            $request->setDescription($uploadAttachedRequest->getDescription());
        }
        if (!is_null($uploadAttachedRequest->getCateId())) {
            $request->setCateId($uploadAttachedRequest->getCateId());
        }
        if (!is_null($uploadAttachedRequest->getTags())) {
            $request->setTags($uploadAttachedRequest->getTags());
        }
        if (!is_null($uploadAttachedRequest->getStorageLocation())) {
            $request->setStorageLocation($uploadAttachedRequest->getStorageLocation());
        }
        if (!is_null($uploadAttachedRequest->getFileSize())) {
            $request->setFileSize($uploadAttachedRequest->getFileSize());
        }
        if (!is_null($uploadAttachedRequest->getUserData())) {
            $request->setUserData($uploadAttachedRequest->getUserData());
        }
        if (!is_null($uploadAttachedRequest->getAppId())) {
            $request->setAppId($uploadAttachedRequest->getAppId());
        }
        if (!is_null($uploadAttachedRequest->getWorkflowId())) {
            $request->setWorkflowId($uploadAttachedRequest->getWorkflowId());
        }

        $data = $this->requestUploadInfo($request, 'attached');
        AliyunVodLog::printLog('CreateUploadAttachedMedia, FilePath: %s, MediaId: %s, MediaURL: %s',
            $uploadAttachedRequest->getFilePath(), $data->MediaId, $data->MediaURL);

        return $data;
    }

    private function uploadOssObject($filePath, $object, $uploadInfo, $headers = null)
    {
        $this->initOssClient($uploadInfo->UploadAuth, $uploadInfo->UploadAddress);
        $this->multipartUploadMediaFile($filePath, $object, $uploadInfo, $headers);

        $bucketHost = str_replace('://', '://'.$uploadInfo->UploadAddress->Bucket.'.',
            $uploadInfo->UploadAddress->Endpoint);
        AliyunVodLog::printLog('UploadFile %s Finish, MediaId: %s, FilePath: %s, Destination: %s/%s',
            $uploadInfo->MediaType, $uploadInfo->MediaId, $filePath, $bucketHost, $object);
    }

    // 定义进度条回调函数；$consumedBytes: 已上传的数据量，$totalBytes：总数据量
    public function uploadProgressCallback($mediaId, $consumedBytes, $totalBytes)
    {
        if ($totalBytes > 0) {
            $rate = 100 * (floatval(($consumedBytes) / floatval($totalBytes)));
        } else {
            $rate = 0;
        }
        printf("[%s]UploadProgress of Media %s, uploaded %s bytes, percent %s%s\n",
            AliyunVodUtils::getCurrentTimeStr(), $mediaId, $consumedBytes, round($rate, 1), '%');
        flush();
    }

    // 分片上传函数
    private function multipartUploadMediaFile($filePath, $object, $uploadInfo, $headers = null)
    {
        $uploadFile = OssUtil::encodePath($filePath);
        if (!file_exists($uploadFile)) {
            throw new Exception('file not exists: '.$uploadFile, AliyunVodError::VOD_ERR_FILE_READ);
        }
        $fileSize = @filesize($uploadFile);
        if (false === $fileSize || $fileSize < 0) {
            throw new Exception('The size of file cannot be determined: '.$filePath, AliyunVodError::VOD_ERR_FILE_READ);
        }
        $this->filePartHash = null;
        $bucket = $uploadInfo->UploadAddress->Bucket;

        // 文件大小未超过分片上传阈值，或不到一个分片的大小，则直接简单上传
        if ($fileSize <= $this->multipartThreshold || $fileSize < $this->multipartPartSize) {
            $res = $this->ossClient->uploadFile($bucket, $object, $uploadFile);
            if ('video' == $uploadInfo->MediaType) {
                $this->reportVideoUploadProgress('put', $uploadInfo, $filePath, $fileSize, 0, $fileSize,
                    1, 1, $fileSize);
            }

            return $res;
        }

        // 初始化分片
        $uploadId = $this->ossClient->initiateMultipartUpload($bucket, $object);
        $pieces = $this->ossClient->generateMultiuploadParts($fileSize, $this->multipartPartSize);
        $resUploadPart = [];
        $uploadPosition = 0;
        $isCheckMd5 = false;
        $totalPart = count($pieces);

        // 上传凭证有效期3000秒，如果是音视频，则需要提前刷新
        $startTime = time();
        $expireSeconds = 2500;

        // 逐个上传分片
        foreach ($pieces as $i => $piece) {
            $fromPos = $uploadPosition + (int) $piece[OssClient::OSS_SEEK_TO];
            $toPos = (int) $piece[OssClient::OSS_LENGTH] + $fromPos - 1;
            $upOptions = [
                OssClient::OSS_FILE_UPLOAD => $uploadFile,
                OssClient::OSS_PART_NUM => ($i + 1),
                OssClient::OSS_SEEK_TO => $fromPos,
                OssClient::OSS_LENGTH => $toPos - $fromPos + 1,
                OssClient::OSS_CHECK_MD5 => $isCheckMd5,
            ];

            if ($isCheckMd5) {
                $contentMd5 = OssUtil::getMd5SumForFile($uploadFile, $fromPos, $toPos);
                $upOptions[OssClient::OSS_CONTENT_MD5] = $contentMd5;
            }

            // 上传分片
            $resUploadPart[] = $this->ossClient->uploadPart($bucket, $object, $uploadId, $upOptions);
            /*AliyunVodLog::printLog("UploadPart, FilePath: %s, MediaId: %s, MediaType: %s, UploadId: %s, PartNumber: %s, PartSize: %s",
                $uploadFile, $uploadInfo->MediaId, $uploadInfo->MediaType, $uploadId, $upOptions[OssClient::OSS_PART_NUM], $upOptions[OssClient::OSS_LENGTH]);*/

            // 上传进度回调
            $this->uploadProgressCallback($uploadInfo->MediaId, $toPos, $fileSize);

            if ('video' == $uploadInfo->MediaType) {
                // 上报上传进度
                $this->reportVideoUploadProgress('multipart', $uploadInfo, $uploadFile, $fileSize,
                    $uploadId, $this->multipartPartSize, $totalPart, $upOptions[OssClient::OSS_PART_NUM], $toPos);

                // 检测视频上传凭证是否过期
                $nowTime = time();
                if ($nowTime - $startTime >= $expireSeconds) {
                    $uploadInfo = $this->refreshUploadVideo($uploadInfo->MediaId);
                    $this->initOssClient($uploadInfo->UploadAuth, $uploadInfo->UploadAddress);
                    $startTime = $nowTime;
                }
            }
        }

        $uploadParts = [];
        foreach ($resUploadPart as $i => $eTag) {
            $uploadParts[] = [
                'PartNumber' => ($i + 1),
                'ETag' => $eTag,
            ];
        }

        // 完成上传
        $options = is_null($headers) ? null : [OssClient::OSS_HEADERS => $headers];
        $res = $this->ossClient->completeMultipartUpload($bucket, $object, $uploadId, $uploadParts, $options);
        if ('video' == $uploadInfo->MediaType) {
            $this->reportVideoUploadProgress('multipart', $uploadInfo, $filePath, $fileSize, $uploadId,
                $this->multipartPartSize, $totalPart, $totalPart, $fileSize);
        }

        return $res;
    }

    private function reportVideoUploadProgress($uploadMethod, $uploadInfo, $filePath, $fileSize, $uploadId, $partSize,
                                               $totalPart, $donePartsCount, $doneBytes)
    {
        if (is_null($this->filePartHash)) {
            $this->filePartHash = AliyunVodReportUpload::generateFilePartHash($this->accessKeyId, $filePath, $fileSize);
        }
        $uploadPoint = ['upMethod' => $uploadMethod, 'threshold' => $this->multipartThreshold,
            'partSize' => $this->multipartPartSize, 'doneBytes' => $doneBytes, ];
        $progressInfo = ['FileName' => $filePath, 'FileHash' => $this->filePartHash, 'FileSize' => $fileSize, 'UploadId' => $uploadId,
            'PartSize' => $partSize, 'TotalPart' => $totalPart, 'DonePartsCount' => $donePartsCount,
            'UploadPoint' => json_encode($uploadPoint), 'UploadAddress' => $uploadInfo->OriUploadAddress, ];

        AliyunVodReportUpload::reportUploadProgress($this->accessKeyId, $uploadInfo->MediaId, $progressInfo);
    }

    private $accessKeyId;
    private $accessKeySecret;
    private $apiRegionId;
    private $ecsRegionId;
    private $connTimeout;
    private $ossTimeout;
    private $vodTimeout;
    private $vodClient;
    /**
     * @var OssClient
     */
    private $ossClient;
    private $maxRetryTimes;
    private $filePartHash;
    private $enableSSL;
    private $securityToken = null;

    // 分片上传的阈值，超过此值开启分片上传
    private $multipartThreshold;
    // 分片大小，单位byte
    private $multipartPartSize;
    // 分片上传时并行上传的线程数，暂时为串行上传，不支持并行，后续会支持。
    private $multipartThreadsNum;
}
