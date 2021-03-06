<?php

namespace Jackalope;

/**
 * This class implements a stream wrapper that allows for lazy loaded binary properties
 *
 * The stream is registered for the protocol "jackalope://". The URL must contain the sessions
 * registryKey as the host part and the oath of the binary property as the path part, e.g.
 * "jackalope://abc0123/content/node/binary"
 *
 * For multivalued properties the url also contains the position of the stream in the property array
 * in the port field and a token to identify all streams loaded by the single backend call in an static
 * array as username.
 *
 * The loading from the backend is deferred until the stream is accessed. Then it is loaded and all
 * stream functions are passed on to the underlying stream.
 *
 * @package jackalope
 * @private
 */
class BinaryStreamWrapper
{
    /** array to store the streams loaded by a backend call for all values of a multivalue property */
    private static $multiValueMap = array();

    private $path = null;
    private $stream = null;
    private $session = null;

    /**
     * Parse the url and store the information.
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->path = $path;
        return true;
    }

    public function stream_read($count)
    {
        $this->init_stream();
        return fread($this->stream, $count);
    }

    public function stream_write($data)
    {
        $this->init_stream();
        return fwrite($this->stream, $data);
    }

    public function stream_tell()
    {
        $this->init_stream();
        return ftell($this->stream);
    }

    public function stream_eof()
    {
        $this->init_stream();
        return feof($this->stream);
    }

    public function stream_stat()
    {
        $this->init_stream();
        return fstat($this->stream);
    }

    public function stream_seek($offset, $whence)
    {
        $this->init_stream();
        return fseek($this->stream, $offset, $whence);
    }

    public function stream_close()
    {
        if ($this->stream) {
            fclose($this->stream);
        }
    }

    public function stream_flush()
    {
        if ($this->stream) {
            return fflush($this->stream);
        } else {
            return false;
        }
    }

    /**
     * Check whether stream was already loaded, otherwise fetch from backend.
     *
     * Multivalued properties have a special handling since the backend returns all
     * streams in a single call.
     *
     * Always checks that the current session is still alive.
     */
    private function init_stream()
    {
        if ($this->session && !$this->session->isLive()) {
            throw new \LogicException("Trying to read a stream from a closed transport.");
        }
        if (null === $this->stream) {
            $url = parse_url($this->path);
            $this->session = Session::getSessionFromRegistry($url['host']);
            $property_path = $url['path'];
            $token = null;
            if (isset($url['user'])) {
                $token = $url['user'];
            }
            if (isset($url['port'])) {
                $index = $url['port'] - 1;
            }
            if (null === $token) {
                $this->stream = $this->session->getObjectManager()->getBinaryStream($property_path);
            } else {
                // check if streams have been loaded for multivalued properties
                if (!isset(self::$multiValueMap[$token])) {
                    self::$multiValueMap[$token] = $this->session->getObjectManager()->getBinaryStream($property_path);
                }
                $this->stream = self::$multiValueMap[$token][$index];
            }
        }
    }
}
