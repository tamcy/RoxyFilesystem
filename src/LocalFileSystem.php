<?php
/**
 * This file is part of the Roxyfileman Bundle
 *
 * (c) Jonas Renaudot <jonas.renaudot@gmail.com>
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this code source
 */

namespace Sparkson\RoxyFilesystem;


use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class LocalFileSystem implements FileSystemInterface
{

    private $rootPath;

    private $virtualRootPath;

    /**
     * @var FileSystem
     */
    private $fs;

    /**
     * @param null $rootPath
     * @param null $virtualRootPath path used as a virtual root path for served files
     * By default the directory name
     */
    public function __construct($rootPath = null, $virtualRootPath = null)
    {
        $this->rootPath = $rootPath;
        $this->fs = new Filesystem();

        if ($virtualRootPath) {
            $this->virtualRootPath = $virtualRootPath;
        } else {
            $file = new \SplFileInfo($rootPath);
            $this->virtualRootPath = '/' . $file->getBasename();
        }
    }

    /**
     * Return the directory tree in the form of a list
     * @return DirectoryInterface[]
     */
    public function getDirectoryTreeList()
    {
        $finder = new Finder();
        $finder->in($this->rootPath)->directories();

        $directories = array();


        $rootFinder = new Finder();
        $rootFinder->in($this->rootPath)->depth(0);

        //Add root directory
        $directories[] = new Directory($this->virtualRootPath, $rootFinder->directories()->count(), $rootFinder->files()->count());

        foreach ($finder as $dir) {
            $dirFinder = new Finder();
            $dirFinder->in($dir->getPathname())->depth(0);

            $virtualAbsolutePath = substr($this->fs->makePathRelative($dir->getPathname(), $this->rootPath), 0, -1); //substring to remove last /

            $directories[] = new Directory($this->virtualRootPath . '/' . $virtualAbsolutePath, $dirFinder->files()->count(), $dirFinder->directories()->count());
        }

        return $directories;
    }

    /**
     * @param $path
     * @param $directoryName
     * @return StandardResponseInterface
     */
    public function createDirectory($path, $directoryName)
    {
        if (!$this->isNameValid($directoryName)) {
            return new StandardResponse(false, 'Name not valid. Only a-z, A-Z, 0-9, - and _ are authorized');
        }

        $realPath = $this->getRealPath($path);

        try {
            $this->fs->mkdir($realPath . '/' . $directoryName);
            return new StandardResponse();
        } catch (IOException $e) {
            return new StandardResponse(false, 'Unable to create directory');
        }

    }

    /**
     * @param $path of the directory to remove
     * @return StandardResponseInterface
     */
    public function deleteDirectory($path)
    {
        $realPath = $this->getRealPath($path);

        try {
            $this->fs->remove($realPath);

            return new StandardResponse();
        } catch (IOException $e) {
            return new StandardResponse(false, 'Unable to delete directory');
        }

    }

    /**
     * @param $origin Path of the origin directory
     * @param $destinationDirectoryPath Destination directory withouth directory name
     * @return StandardResponseInterface
     */
    public function moveDirectory($origin, $destinationDirectoryPath)
    {
        $realOrigin = $this->getRealPath($origin);
        $realDestination = $this->getRealPath($destinationDirectoryPath);

        $this->fs->rename($realOrigin, $realDestination . '/' . basename($realOrigin));

        return new StandardResponse();
    }

    /**
     * @param $origin directory
     * @param $destination directory
     * @return StandardResponseInterface
     */
    public function copyDirectory($origin, $destination)
    {
        //$realOrigin = $this->getRealPath($origin);
        //$realDestination = $this->getRealPath($destination);

        return new StandardResponse(false, 'Directory copy is not yet implemented');
    }

    /**
     * @param $origin
     * @param $newName of the directory
     * @return StandardResponseInterface
     */
    public function renameDirectory($origin, $newName)
    {
        if (!$this->isNameValid($newName)) {
            return new StandardResponse(false, 'New name not valid');
        }

        $realOrigin = $this->getRealPath($origin);

        $this->fs->rename($realOrigin, dirname($realOrigin) . '/' . $newName);

        return new StandardResponse();
    }

    /**
     * @param $directory
     * @param string $type generally one of image|files|pdf
     * @return FileInterface[]
     */
    public function getFilesList($directory = null, $type = null)
    {
        $directory = $this->getRealPath($directory);

        if (!is_dir($directory)) {
            return array();
        }

        $finder = new Finder();
        $finder->in($directory)->files()->depth(0);

        if ($type == 'image') {
            $finder->name('/\.(?:png|jpg|jpeg|gif)$/');
        }

        $files = array();

        foreach ($finder as $file) {
            $relativePath = substr($this->fs->makePathRelative($file->getPathname(), $this->rootPath), 0, -1);//remove last / and remove ./
            $relativePath = preg_replace('|^\./|', '', $relativePath);
            $fileUrl = $this->virtualRootPath . '/' . $relativePath;

            $height = 0;
            $width = 0;

            $extension = $file->getExtension();
            if ($type == 'image' || $extension == 'jpg' || $extension == 'jpeg' || $extension == 'png' || $extension == 'gif') {
                list($width, $height) = getimagesize($file->getPathname());
            }

            $files[] = new File($fileUrl, $file->getSize(), $file->getMTime(), $height, $width);
        }

        return $files;
    }

    /**
     * @param $destinationDirectory
     * @param UploadedFile[] $files array of uploaded files
     * @return StandardResponseInterface
     */
    public function upload($destinationDirectory, $files)
    {
        $realDestinationDirectory = $this->getRealPath($destinationDirectory);

        if (!is_dir($realDestinationDirectory)) {
            return new StandardResponse(false, 'The destination directory is not a valid directory');
        }

        $errors = array();
        foreach ($files as $file) {
            if ($file->isValid()) {
                $file->move($realDestinationDirectory, $this->cleanFilename($file->getClientOriginalName()));
            } else {
                $errors[] = $file->getErrorMessage();
            }
        }

        if ($errors) {
            return new StandardResponse(false, implode("\n", $errors));
        } else {
            return new StandardResponse();
        }
    }

    /**
     * @param $file path
     * @return DownloadableFileInterface
     */
    public function download($file)
    {
        $realPath = $this->getRealPath($file);

        $finfo = new \finfo();
        $type = $finfo->file($realPath, FILEINFO_MIME_TYPE);

        $callback = function () use ($realPath) {
            $fp = fopen($realPath, 'r');
            return new Stream($fp);
        };

        return new DownloadableFile($callback, $type, basename($realPath));
    }

    /**
     * @param $directory
     * @return DownloadableFileInterface zip of the directory content
     */
    public function downloadDirectory($directory)
    {
        $realPath = $this->getRealPath($directory);

        $finder = new Finder();

        $finder->in($realPath)->files();

        $zipArchive = new \ZipArchive();

        $tmpZipArchive = tempnam(sys_get_temp_dir(), 'ERF');
        $zipArchive->open($tmpZipArchive, \ZipArchive::CREATE);

        try {
            foreach ($finder as $file) {

                $relativePath = substr($this->fs->makePathRelative($file->getPathname(), $this->rootPath), 0, -1);//remove last / and remove ./
                $relativePath = preg_replace('|^\./|', '', $relativePath);

                $zipArchive->addFile($file->getPathname(), $relativePath);
            }

            $zipArchive->close();

            $callback = function () use ($realPath) {
            };


            return new DownloadableFile(function () use ($tmpZipArchive) {
                $fp = fopen($tmpZipArchive, 'r');
                $stream = new Stream($fp);
                $stream->setOnClosedCallback(function () {
                    @unlink($tmpZipArchive);
                });
                return $stream;
            }, 'application/zip', basename($directory) . '.zip');
        } catch (\Exception $e) {
            unlink($tmpZipArchive);
        }
    }

    /**
     * @param $file
     * @return StandardResponseInterface
     */
    public function deleteFile($file)
    {
        $fullPath = $this->getRealPath($file);

        $this->fs->remove($fullPath);

        return new StandardResponse();
    }

    /**
     * @param $origin file path
     * @param $destination new file path
     * @return StandardResponseInterface
     */
    public function moveFile($origin, $destination)
    {
        $fullPath = $this->getRealPath($origin);
        $fullDestinationPath = $this->getRealPath($destination);

        $this->fs->rename($fullPath, $fullDestinationPath);

        return new StandardResponse();
    }

    /**
     * @param $origin file path
     * @param $destinationDirectory destination directory path
     * @return StandardResponseInterface
     */
    public function copyFile($origin, $destinationDirectory)
    {
        $fullPath = $this->getRealPath($origin);
        $fullDestinationPath = $this->getRealPath($destinationDirectory);

        $this->fs->copy($fullPath, $fullDestinationPath . '/' . basename($fullPath));

        return new StandardResponse();
    }

    /**
     * @param $file
     * @param $newName new filename
     * @return StandardResponseInterface
     */
    public function renameFile($file, $newName)
    {

        if (!$this->isNameValid($newName)) {
            return new StandardResponse(false, 'New name not valid');
        }

        $fullPath = $this->getRealPath($file);
        $this->fs->rename($fullPath, dirname($fullPath) . '/' . $newName);

        return new StandardResponse();
    }

    /**
     * @param $file path
     * @param $width of the generated thumbnail
     * @param $height of the generated thumbnail
     * @return DownloadableFileInterface
     */
    public function thumbnail($file, $width, $height)
    {
        $response = new DownloadableFile();

        $filePath = $this->getRealPath($file);

        list($imageWidth, $imageHeight, $type) = getimagesize($filePath);
        $type = image_type_to_mime_type($type);

        $response->setContentType($type);

        $response->setCallback(function () use ($filePath, $width, $height, $imageWidth, $imageHeight, $type) {

            switch ($type) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($filePath);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($filePath);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($filePath);
                    break;
            }

            if (!$image) {
                return;
            }


            if ($width < $imageWidth || $height < $imageHeight) { //resize image ?
                $heightRatio = $imageHeight / $height;
                $widthRatio = $imageWidth / $width;

                $ratio = $widthRatio < $heightRatio ? $heightRatio : $widthRatio;

                $thumbWidth = $imageWidth / $ratio;
                $thumbHeight = $imageHeight / $ratio;

                $thumbnail = imagecreatetruecolor($thumbWidth, $thumbHeight);
                imagecopyresized($thumbnail, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $imageWidth, $imageHeight);
                imagedestroy($image);
                $image = $thumbnail;
            }

            ob_start();

            switch ($type) {
                case 'image/jpeg':
                    imagejpeg($image);
                    break;
                case 'image/gif':
                    imagegif($image);
                    break;
                case 'image/png':
                    imagepng($image);
                    break;
            }

            imagedestroy($image);
            $data = ob_get_contents();
            ob_end_clean();

            $fp = fopen('php://temp', 'rb+');
            fwrite($fp, $data);
            fseek($fp, 0);

            return new Stream($fp);
        });

        return $response;
    }

    /**
     * Return local path from remote query
     * @param $path
     */
    private function getRealPath($path)
    {
        if (strpos($path, '../') !== false) {
            throw new AccessDeniedException('Path cannot contain "../" pattern');
        }

        return str_replace($this->virtualRootPath, $this->rootPath, $path);
    }

    /**
     * @param $name
     * Check if a directory or file name is valid (doesn't contain ../ or /)
     */
    private function isNameValid($name)
    {
        return preg_match('/^[a-zA-Z0-9\.\-\_]+$/', $name) > 0;
    }

    private function cleanFilename($filename)
    {
        $replacements = array(
            '/\s+/' => '_',
            '/[é|è|ê]/' => 'e',
            '/[à|ä]/' => 'a',
            '/[^a-zA-Z0-9\.\-\_]/' => ''
        );

        return preg_replace(array_keys($replacements), array_values($replacements), $filename);
    }
}