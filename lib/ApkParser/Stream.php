<?php

namespace ApkParser;

/**
 * This file is part of the Apk Parser package.
 *
 * (c) Tufan Baris Yildirim <tufanbarisyildirim@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class Stream
{
    /**
     * file strem, like "fopen"
     *
     * @var resource
     */
    private $stream;

    /**
     * @param resource $stream File stream.
     * @throws \Exception
     */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            // TODO : the resource type must be a regular file stream resource.
            throw new \Exception("Invalid stream");
        }

        $this->stream = $stream;
    }

    /**
     * Read the next character from stream.
     *
     * @param mixed $length
     * @return string
     */
    public function read($length = 1)
    {
        return fread($this->stream, $length);
    }

    /**
     * check if end of filestream
     */
    public function feof()
    {
        return feof($this->stream);
    }

    /**
     * Jump to the index!
     * @param int $offset
     */
    public function seek($offset)
    {
        fseek($this->stream, $offset);
    }

    /**
     * Close the stream
     */
    public function close()
    {
        fclose($this->stream);
    }

    /**
     * Read the next byte
     * @return int
     */
    public function readByte()
    {
        return ord($this->read());
    }

    /**
     * fetch the remaining byte into an array
     *
     * @param mixed $count Byte length.
     * @return array
     */
    public function getByteArray($count = null)
    {
        $data = stream_get_contents($this->stream, $count === null ? -1 : $count);

        if ($data === false || $data === '') {
            return array();
        }

        return array_values(unpack('C*', $data));
    }

    /**
     * Write a string to the stream
     *
     * @param mixed $str
     */
    function write($str)
    {
        fwrite($this->stream, $str);
    }

    /**
     * Write a byte to the stream
     *
     * @param mixed $byte
     */
    function writeByte($byte)
    {
        $this->write(chr($byte));
    }

    /**
     * Write the stream to the given destionation directly without using extra memory like storing in an array etc.
     *
     * @param mixed $destination file path.
     */
    public function save($destination)
    {
        $weOpenedIt = !is_resource($destination);
        $target = new Stream($weOpenedIt ? fopen($destination, 'w+') : $destination);

        while (!$this->feof()) {
            $target->write($this->read(8192));
        }

        if ($weOpenedIt) { // close the file if we opened it, otherwise don't touch.
            $target->close();
        }
    }
}