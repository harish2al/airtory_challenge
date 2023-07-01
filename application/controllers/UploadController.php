<?php 
use Aws\S3\S3Client;
use Aws\ElasticTranscoder\ElasticTranscoderClient;

class UploadController extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->bucketName = 'xxxxxx';
        $this->requiredBaseLength = 60;
        $this->pipelineId = 'xxxxxx';
    }

    /**
     * Function to upload & Trancode video files
     * 
     */
    public function uploadAndTranscode()
    {
        //Initiate the S3 client
        $s3Client = new S3Client([
            'region'      => 'ap-south-1', 
            'version'     => 'latest',
        ]);

        //Initiate the transcoder client
        $transcoderClient = new ElasticTranscoderClient([
            'region'      => 'ap-south-1', 
            'version'     => 'latest',
        ]);

        //Get the uploaded file
        $videoFile = $_FILES['video'];
        $tempFilePath = $videoFile['tmp_name'];
        $getFileNameExtension = explode(".", $videoFile['name']);
        $today = date('Y-m-d H:i:s');
        $uniqueFolderName = $getFileNameExtension[0]."_".strtotime($today)."_".rand();
        $uniqueName = $getFileNameExtension[0]."_".strtotime($today)."_".rand().".".$getFileNameExtension[1];

        $uploadFileToS3 = '';
        $videoDetails = $this->checkVideoLengthAndResolution($tempFilePath);
        if($videoDetails['duration'] <= $this->requiredBaseLength) {
            $uploadFileToS3 = $this->uploadFileToS3($uniqueName);
            $transcodedFiles =  $this->transcodeVideo($uniqueFolderName, $uploadFileToS3['uploadKey'], $videoFile['name'], $videoDetails['resolution']);

            // Upload the transcoded files to S3
            $uploadedFiles = $this->uploadTranscodedFilesToS3($transcodedFiles, $uniqueFolderName);

            // Return the S3 URLs with size and resolution of the files
            $response = [];
            foreach ($uploadedFiles as $file) {
                $response[] = [
                    'resolution' => $file['resolution'],
                    'url' => $file['url'],
                    'size' => $file['size'],
                ];
            }

            // Return the response as JSON or in any other desired format
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode($response));
        } else {
            $this->output->set_status_header(400);
            $this->output->set_output('Video duration exceeds the maximum limit of 1 minute.');
        }  
    }

    /**
     * Function to check the length of video & resolution uploaded
     * 
     * @return array
     */
    public function checkVideoLengthAndResolution(): array{
        $response = [];
        $ffmpegPath = '/usr/bin/ffmpeg'; 
        $command = $ffmpegPath . ' -i ' . $tempFilePath . ' 2>&1';
        $output = shell_exec($command);

        // Video duration and resolution from output
        $duration = 0;
        $resolution = '0x0';

        preg_match('/Duration: (\d+):(\d+):(\d+)/', $output, $matches);
        if (count($matches) === 4) {
            $hours = intval($matches[1]);
            $minutes = intval($matches[2]);
            $seconds = intval($matches[3]);
            $duration = $hours * 3600 + $minutes * 60 + $seconds;
            $response['duration'] = $duration;
        }

        preg_match('/Stream #.*: Video:.* (\d+)x(\d+)/', $output, $matches);
        if (count($matches) === 3) {
            $resolution = $matches[1] . 'x' . $matches[2];
            $response['resolution'] = $resolution;
        }

        return $response;
    }

    /**
     * Fucntion to upload the file to S3
     * 
     * @param string $fileName
     * @return string
     */
    public function uploadFileToS3(string $fileName): string {
        $uploadResponse = [];
        $key = 'video/'.$fileName;
        $uploadResponse['uploadKey'] = $key;

        $uploader = $s3Client->createMultipartUploader([
            'Bucket' => $this->bucketName,
            'Key' => $key,
            'SourceFile' => $tempFilePath,
        ]);

        try {
            $result = $uploader->upload();
            // File uploaded successfully
            // Return the S3 URL of the uploaded file
            $s3Url = $result['ObjectURL'];
            $uploadResponse['s3Url'] = $s3Url;
        } catch (S3Exception $exception) {
            throw $exception->getMessage();
        }
        return $uploadResponse;
    }

    /**
     * Function to Transcode video to different resolution
     * 
     * @param string $folderName, string $uploadKey, string $originalName, number $resolution
     * @return array
     */
    public function transcodeVideo(string $folderName, string $uploadKey, string $originalName, number $resolution): array{
        $getfileExtension = explode('.', $originalName);

        // Define the outputs for different resolutions
        $outputs = [
            [
                'Key' => $folderName.'/'.$folderName.'_144'.$getfileExtension[1],
                'PresetId' => 'first-resolution-144',
            ],
            [
                'Key' => $folderName.'/'.$folderName.'_240'.$getfileExtension[1],
                'PresetId' => 'second-resolution-240',
            ],
            [
                'Key' => $folderName.'/'.$folderName.'_360'.$getfileExtension[1],
                'PresetId' => 'third-resolution-360',
            ],
            [
                'Key' => $folderName.'/'.$folderName.'_480'.$getfileExtension[1],
                'PresetId' => 'fourth-resolution-480',
            ],
            [
                'Key' => $folderName.'/'.$folderName.'_720'.$getfileExtension[1],
                'PresetId' => 'fifth-resolution-720',
            ],
            [
                'Key' => $folderName.'/'.$folderName.'_1080'.$getfileExtension[1],
                'PresetId' => 'sixth-resolution-1080',
            ],
        ];

        // Create the transcoding job
        $response = $this->transcoderClient->createJob([
            'PipelineId' => $this->pipelineId,
            'Input' => [
                'Key' => $uploadKey,
            ],
            'Outputs' => $outputs,
        ]);

        // Get the job ID for monitoring the job status
        $jobId = $response['Job']['Id'];

        // Wait for the transcoding job to complete
        $this->waitForTranscodingJobCompletion($jobId);

        // Get the output files and their resolutions
        $transcodedFiles = [];

        foreach ($outputs as $output) {
            $resolution = $this->getResolutionFromPresetId($output['PresetId']);
            $transcodedFiles[] = [
                'resolution' => $resolution,
                's3Key' => $output['Key'],
            ];
        }

        return $transcodedFiles;

    }

    /**
     * Function to wait for ongoing transcoding to complete
     * 
     * @param string $jobId
     * @return void
     */
    private function waitForTranscodingJobCompletion(string $jobId): void
    {
        $jobStatus = '';

        while ($jobStatus !== 'Complete') {
            // Get the status of the transcoding job
            $response = $this->transcoderClient->readJob([
                'Id' => $jobId,
            ]);

            $jobStatus = $response['Job']['Status'];

            if ($jobStatus === 'Error') {
                break;
            }

            // Wait for some time before checking the job status again
            sleep(5);
        }
    }

    /**
     * Function to get resolution from Preset ID
     * 
     * @param string $presetId
     * @return string
     */
    private function getResolutionFromPresetId(string $presetId): string
    {
        // Map preset IDs to resolutions as needed
        $presetResolutionMap = [
            'first-resolution-144' => '144',
            'second-resolution-240' => '240',
            'third-resolution-360' => '360',
            'fourth-resolution-480' => '480',
            'fifth-resolution-720' => '720',
            'sixth-resolution-1080' => '1080',
        ];

        return $presetResolutionMap[$presetId] ?? 'unknown';
    }

    /**
     * Function to upload the transcoded files to S3 & return the uploaded details
     * 
     * @param array $transcodedFiles, string $uniqueFolderName
     * @return array
     */
    private function uploadTranscodedFilesToS3(array $transcodedFiles, string $uniqueFolderName): array
    {
        $uploadedFiles = [];

        foreach ($transcodedFiles as $file) {
            $s3Key = $file['s3Key'];
            $tempFilePath = $uniqueFolderName.'/transcoded/file/' . $s3Key;

            // Download the transcoded file to a temporary location
            $this->s3Client->getObject([
                'Bucket' => $this->bucketName,
                'Key' => $s3Key,
                'SaveAs' => $tempFilePath,
            ]);

            // Upload the file to S3
            $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key' => $s3Key,
                'SourceFile' => $tempFilePath,
            ]);

            // Get the file size
            $size = $this->s3Client->headObject([
                'Bucket' => $this->bucketName,
                'Key' => $s3Key,
            ])->get('ContentLength');

            $transcodedSize = filesize($tempFilePath);
            $compressionRatio = $originalSize > 0 ? ($transcodedSize / $originalSize) : 0;

            $uploadedFiles[] = [
                'resolution' => $file['resolution'],
                'url' => $this->getS3ObjectUrl($s3Key),
                'size' => $size,
                'compressionRatio' => $compressionRatio,
            ];
        }

        return $uploadedFiles;
    }

    /**
     * Function to fetch the final S3 url
     * 
     * @param $s3Key
     * @return string
     */
    private function getS3ObjectUrl($s3Key): string
    {
        return 'https://' . $this->bucketName . '.s3.amazonaws.com/' . $s3Key;
    }
}
