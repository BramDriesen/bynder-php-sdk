<?php

/**
 *
 * Copyright (c) Bynder. All rights reserved.
 *
 * Licensed under the MIT License. For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Bynder\Api\Impl\Upload;

use Exception;
use GuzzleHttp\Promise;
use VirtualFileSystem\FileSystem;
use Bynder\Api\Impl\AbstractRequestHandler;

/**
 * Class used to upload files to Bynder.
 */
class FileUploader
{
    /**
     * Max chunk size
     */
    const CHUNK_SIZE = 1024 * 1024 * 5;

    /**
     *
     * @var AbstractRequestHandler Request handler used to communicate with the API.
     */
    private $requestHandler;

    /**
     * Initialises a new instance of the class.
     *
     * @param AbstractRequestHandler $requestHandler Request handler used to communicate with the API.
     */
    public function __construct(AbstractRequestHandler $requestHandler)
    {
        $this->requestHandler = $requestHandler;
    }

    /**
     * Creates a new instance of FileUploader.
     *
     * @param AbstractRequestHandler $requestHandler Request handler used to communicate with the API.
     * @return FileUploader
     */
    public static function create(AbstractRequestHandler $requestHandler)
    {
        return new FileUploader($requestHandler);
    }

    /**
     * Uploads a file with the data specified in the data parameter.
     *
     * Client requests a unique fileId from the Bynder API.
     * For each file the client needs to requests upload authorization from the Bynder API.
     * The client uploads a file chunked with Bynder API received in step 1.
     * Each chunk needs to have a valid sha256 to be validated as completed request to Bynder passed as form data.
     * When the file is completely uploaded, the client sends a “finalise” request to Bynder.
     * After the file is processed, the client sends a “save” call to save the file in Bynder.
     *      Additional information can be provided such as title, tags, metadata and description.
     *
     * @param $data array containing the file and media asset information.
     *
     * @return json object containing the error message(if an Exception was thrown) or: 
     *  - success: boolean that indicate the result of the upload call
     *  - mediaitems: a list of mediaitems created, with at least the original.
     *  - batchId: the batchId of the upload.
     *  - mediaid: the mediaId update or created.
     */
    public function uploadFile($filePath, $data)
    {
        try {
            $fileId = $this->prepareFile()->wait()['file_id'];
            $fileSize = filesize($filePath);
            $fileSha256 = hash_file("sha256", $filePath);
            $chunksCount = $this->uploadInChunks($filePath, $fileId, $fileSize);
            $this->finalizeFile($fileId, $filePath, $fileSize, $chunksCount, $fileSha256);
            return $this->saveMediaAsync($fileId, $data)->wait();
        } catch (Exception $e) {
            return json_encode(
                array(
                    'Error' => "Unable to upload file. " . $e->getMessage()
                )
            );
        }
    }

    /**
     * Initializes and prepares the file for upload by generating a fileId.
     * 
     * @return string A uuid4 that can be used to identify the file to be uploaded.
     * @throws Exception
     */
    private function prepareFile()
    {
        return $this->requestHandler->sendRequestAsync('POST', 'v7/file_cmds/upload/prepare');
    }

    /**
     * Upload the file in chunks of CHUNK_SIZE.
     * 
     * @param string $filePath refering to the path of the file to be uploaded.
     * @param string $fileId returned from the prepare endpoint used to identify the file to be uploaded.
     * @param integer $fileSize of the file to be uploaded.
     * 
     * @return integer The number of chunks in which to upload the file.
     * @throws Exception
     */
    private function uploadInChunks($filePath, $fileId, $fileSize)
    {
        $chunksCount = 0;
        if ($file = fopen($filePath, 'rb')) {
            $chunksCount = round(($fileSize + self::CHUNK_SIZE - 1) / self::CHUNK_SIZE);
            $chunkNumber = 0;
            while ($chunk = fread($file, self::CHUNK_SIZE)) {
                $this->uploadChunk($fileId, $chunk, $chunkNumber);
                $chunkNumber++;
            }
        }
        return $chunksCount;
    }

    private function uploadChunk($fileId, $chunk, $chunkNumber)
    {
        $sessionHeader = ['headers' => [
            'content-sha256' => hash("sha256", $chunk)
        ]];
        $this->requestHandler->sendRequestAsync(
            'POST',
            'v7/file_cmds/upload/' . $fileId . '/chunk/' . $chunkNumber,
            $sessionHeader
        )->wait();
    }

    /**
     * Finalises a completely uploaded file.
     * 
     * @param string $fileId returned from the prepare endpoint used to identify the file to be uploaded.
     * @param string $filePath refering to the path of the file to be uploaded.
     * @param integer $fileSize of the file to be uploaded.
     * @param integer $chunksCount denoting the number of chunks in which the file is to be uploaded.
     * @param string $fileSha256 represents the sha digest of the file to be uploaded.
     * 
     * @throws Exception
     */
    private function finalizeFile($fileId, $filePath, $fileSize, $chunksCount, $fileSha256)
    {

        $formData = array(
            'fileName' => basename($filePath),
            'fileSize' => $fileSize,
            'chunksCount' => $chunksCount,
            'sha256' => $fileSha256
        );

        $this->requestHandler->sendRequestAsync(
            'POST',
            'v7/file_cmds/upload/' . $fileId . '/finalise_api',
            [
                'form_params' => $formData
            ]
        )->wait();
    }

    /**
     * Saves the file in the Bynder Asset Bank. This can be either a new or existing file, depending on whether or not
     * the mediaId parameter is passed.
     * 
     * @param string $fileId The uuid4 used to identify the file to be uploaded.
     * @param array $data Array of relevant file upload data, such as brandId, mediaId etc.
     *
     * @return Promise\Promise The information of the uploaded file, including IDs and all final file urls.
     * @throws Exception
     */
    private function saveMediaAsync($fileId, $data)
    {
        // If the mediaId is present, save the file as a new version of an existing asset.
        if (isset($data['mediaId'])) {
            $uri = sprintf("api/v4/media/" . $data['mediaId'] . "/save/" . $fileId);
            unset($data['mediaId']);
            return $this->requestHandler->sendRequestAsync('POST', $uri);
        }

        // If the mediaId is missing then save the file as a new asset in which case a brandId must be specified.
        if (!isset($data['brandId']) || trim($data['brandId']) === '') {
            throw new Exception('Invalid or Empty brandId');
        }
        $uri = "api/v4/media/save/" . $fileId;
        return $this->requestHandler->sendRequestAsync('POST', $uri, ['form_params' => $data]);
    }
}
