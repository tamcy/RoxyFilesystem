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


use Psr\Http\Message\StreamInterface;

interface DownloadableFileInterface {

    /**
     * @return string content type (image/jpg, ...)
     */
    public function getContentType();

    /**
     * @return string filename
     */
    public function getFilename();

    /**
     * @return StreamInterface
     */
    public function getStream();

}