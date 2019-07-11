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
use JimChen\AliyunCore\Http\HttpHelper;
use JimChen\OSS\Core\OssUtil;

class AliyunVodUtils
{
    const VOD_MAX_TITLE_LENGTH = 128;
    const VOD_MAX_DESCRIPTION_LENGTH = 1024;

    public static function convertOssInternal($ossUrl, $ecsRegion = null, $isEnableSSL = false)
    {
        if (!$isEnableSSL) {
            $ossUrl = str_replace('https:', 'http:', $ossUrl);
        }

        if (!is_string($ossUrl) || !is_string($ecsRegion)) {
            return $ossUrl;
        }
        $availableRegions = ['cn-qingdao', 'cn-beijing', 'cn-zhangjiakou', 'cn-huhehaote', 'cn-hangzhou', 'cn-shanghai', 'cn-shenzhen',
            'cn-hongkong', 'ap-southeast-1', 'ap-southeast-2', 'ap-southeast-3',
            'ap-northeast-1', 'us-west-1', 'us-east-1', 'eu-central-1', 'me-east-1', ];

        if (!in_array($ecsRegion, $availableRegions)) {
            return $ossUrl;
        }

        $ossUrl = str_replace('https:', 'http:', $ossUrl);

        return str_replace(sprintf('oss-%s.aliyuncs.com', $ecsRegion),
            sprintf('oss-%s-internal.aliyuncs.com', $ecsRegion), $ossUrl);
    }

    public static function getFileName($fileUrl)
    {
        $fileUrl = urldecode($fileUrl);
        $pos = strrpos($fileUrl, '?');
        $briefPath = $fileUrl;
        if (false !== $pos) {
            $briefPath = substr($fileUrl, 0, $pos);
        }

        return [$briefPath, basename($briefPath)];
    }

    public static function getFileExtension($fileName)
    {
        $pos = strrpos($fileName, '.');
        if (false !== $pos) {
            return substr($fileName, $pos + 1);
        }

        return null;
    }

    // 考虑分隔符为"/" 或 "\"(windows)
    public static function replaceFileName($filePath, $replace)
    {
        if (strlen($filePath) <= 0 || strlen($replace) <= 0) {
            return $filePath;
        }
        $filePath = urldecode($filePath);
        $separator = '/';
        $start = strrpos($filePath, $separator);
        if (false === $start) {
            $separator = '\\';
            $start = strrpos($filePath, '\\');
            if (false === $start) {
                return false;
            }
        }

        return substr($filePath, 0, $start).$separator.$replace;
    }

    public static function mkDir($filePath)
    {
        if (strlen($filePath) <= 0) {
            return true;
        }
        $filePath = urldecode($filePath);
        $start = strrpos($filePath, '/');
        if (false === $start) {
            return false;
        }
        $fileDir = substr($filePath, 0, $start);
        if (!is_dir($fileDir)) {
            return mkdir($fileDir, 0777, true);
        }

        return true;
    }

    // 截取字符串，在不超过最大字节数前提下确保中文字符不被截断出现乱码
    public static function subString($strVal, $maxBytes)
    {
        $i = mb_strlen($strVal);
        while (strlen($strVal) > $maxBytes) {
            --$i;
            if ($i <= 0) {
                return '';
            }
            $strVal = mb_substr($strVal, 0, $i);
        }

        return $strVal;
    }

    public static function getCurrentTimeStr()
    {
        return date('Y-m-d H:i:s');
    }

    public static function startsWith($haystack, $needle)
    {
        return 0 === strncmp($haystack, $needle, strlen($needle));
    }

    public static function endsWith($haystack, $needle)
    {
        return '' === $needle || 0 === substr_compare($haystack, $needle, -strlen($needle));
    }
}

class AliyunVodLog
{
    public static $logSwitch = true;

    public static function printLog($format, $args = null, $_ = null)
    {
        if (!AliyunVodLog::$logSwitch) {
            return;
        }
        $vargs = func_get_args();
        unset($vargs[0]);
        $msg = vsprintf($format, $vargs);
        self::printStr($msg);
    }

    private static function printStr($msg)
    {
        printf("[%s]%s\n", AliyunVodUtils::getCurrentTimeStr(), $msg);
    }
}

class AliyunVodReportUpload
{
    public static $isEnableSSL = false;

    const VOD_REPORT_URL = 'vod.cn-shanghai.aliyuncs.com';
    const VOD_REPORT_VERSION = '1.0.2';
    const VOD_REPORT_KEY = 'wXr&aLIJdfI7so';
    const REPORT_FILE_HASH_READ_LEN = 1048576;

    public static function reportUploadProgress($clientId, $videoId, $progressInfo)
    {
        try {
            HttpHelper::$connectTimeout = 1;
            HttpHelper::$readTimeout = 2;
            $authTimestamp = time();
            $authInfo = md5(sprintf('%s|%s|%s', $clientId, self::VOD_REPORT_KEY, $authTimestamp));
            $fields = ['Action' => 'ReportUploadProgress', 'Format' => 'JSON', 'Version' => '2017-03-21',
                'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'SignatureNonce' => md5(uniqid(mt_rand(), true)),
                'VideoId' => $videoId, 'Source' => 'PHPSDK', 'ClientId' => $clientId,
                'BusinessType' => 'UploadVideo', 'TerminalType' => 'PC', 'DeviceModel' => 'Server',
                'AppVersion' => self::VOD_REPORT_VERSION, 'AuthTimestamp' => $authTimestamp, 'AuthInfo' => $authInfo,
                'FileName' => $progressInfo['FileName'], 'FileHash' => $progressInfo['FileHash'],
                'FileSize' => empty($progressInfo['FileSize']) ? 0 : $progressInfo['FileSize'],
                'FileCreateTime' => empty($progressInfo['CreateTime']) ? $authTimestamp : $progressInfo['CreateTime'],
                'UploadRatio' => empty($progressInfo['UploadRatio']) ? 0 : $progressInfo['UploadRatio'],
                'UploadId' => empty($progressInfo['UploadId']) ? 0 : $progressInfo['UploadId'],
                'DonePartsCount' => empty($progressInfo['DonePartsCount']) ? 0 : $progressInfo['DonePartsCount'],
                'PartSize' => empty($progressInfo['PartSize']) ? 0 : $progressInfo['PartSize'],
                'UploadPoint' => $progressInfo['UploadPoint'], 'UploadAddress' => $progressInfo['UploadAddress'],
            ];

            $url = (self::$isEnableSSL ? 'https://' : 'http://').self::VOD_REPORT_URL;
            HttpHelper::curl($url, 'POST', $fields);
        } catch (Exception $e) {
            AliyunVodLog::printLog("reportUploadProgress failed, ErrorMessage: %s\n", $e->getMessage());
        }
    }

    public static function generateFilePartHash($clientId, $filePath, $fileSize)
    {
        try {
            $fp = @fopen(OssUtil::encodePath($filePath), 'r');
            $len = $fileSize <= self::REPORT_FILE_HASH_READ_LEN ? $fileSize : self::REPORT_FILE_HASH_READ_LEN;
            $str = fread($fp, $len);
            fclose($fp);
        } catch (Exception $e) {
            $str = sprintf('%s|%s|%s', $clientId, $filePath, @filemtime($filePath));
        }

        return md5($str);
    }
}

class AliyunVodDownloader
{
    public function __construct($saveLocalDir = null)
    {
        if (isset($saveLocalDir)) {
            $this->saveLocalDir = $saveLocalDir;
        } else {
            $this->saveLocalDir = dirname(__DIR__).'/tmp_dlfiles/';
        }
    }

    public function downloadFile($downloadUrl, $localFileName, $fileSize = null)
    {
        $localPath = $this->saveLocalDir.$localFileName;
        AliyunVodLog::printLog('Download %s To %s', $downloadUrl, $localPath);

        if (isset($fileSize)) {
            $lsize = @filesize($localPath);
            if ($lsize > 0 and $lsize == $fileSize) {
                return $localPath;
            }
        }
        if (!is_dir($this->saveLocalDir)) {
            @mkdir($this->saveLocalDir, 0777, true);
        }

        $sfp = @fopen($downloadUrl, 'rb');
        if (false === $sfp) {
            throw new Exception('download file fail while reading '.$downloadUrl,
                AliyunVodError::VOD_ERR_FILE_DOWNLOAD);
        }

        $dfp = @fopen(OssUtil::encodePath($localPath), 'ab+');
        if (false === $dfp) {
            throw new Exception('download file fail while writing '.$localPath,
                AliyunVodError::VOD_ERR_FILE_DOWNLOAD);
        }
        while (!feof($sfp)) {
            $contents = fread($sfp, 8 * 1024);
            fwrite($dfp, $contents);
        }

        fclose($sfp);
        fclose($dfp);

        return $localPath;
    }

    public function getSaveLocalDir()
    {
        return $this->saveLocalDir;
    }

    public function setSaveLocalDir($localDir)
    {
        return $this->saveLocalDir = $localDir;
    }

    private $saveLocalDir = null;
}

class AliyunVodError
{
    const VOD_ERR_FILE_READ = 10000;
    const VOD_ERR_FILE_DOWNLOAD = 10001;
    const VOD_ERR_M3U8_FILE_REWRITE = 10002;
    const VOD_INVALID_M3U8_SLICE_FILE = 10003;
}
